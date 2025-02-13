<?php

/**
 * Kolab Delegation Engine
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2011-2012, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

class kolab_delegation_engine
{
    public $context;

    private $rc;
    private $ldap;
    private $ldap_filter;
    private $ldap_delegate_field;
    private $ldap_login_field;
    private $ldap_name_field;
    private $ldap_email_field;
    private $ldap_org_field;
    private $ldap_dn;
    private $cache = [];
    private $folder_types = ['mail', 'event', 'task'];
    private $supported;

    public const ACL_READ  = 1;
    public const ACL_WRITE = 2;

    /**
     * Class constructor
     */
    public function __construct()
    {
        $this->rc = rcube::get_instance();
    }

    /**
     * Add delegate
     *
     * @param string|array $delegate Delegate DN (encoded) or delegate data (result of delegate_get())
     * @param array        $acl      List of folder->right map
     *
     * @return string On error returns an error label, on success returns null
     */
    public function delegate_add($delegate, $acl)
    {
        if (!is_array($delegate)) {
            $delegate = $this->delegate_get($delegate);
        }

        $dn = $delegate['ID'];
        if (empty($delegate) || empty($dn)) {
            return 'createerror';
        }

        $list = $this->list_delegates();
        $list = array_keys((array)$list);
        $list = array_filter($list);

        if (in_array($dn, $list)) {
            return 'delegationexisterror';
        }

        // add delegate to the list
        $list[] = $dn;
        $list   = array_map(['kolab_ldap', 'dn_decode'], $list);

        // update user record
        $result = $this->user_update_delegates($list);

        // Set ACL on folders
        if ($result && !empty($acl)) {
            $this->delegate_acl_update($delegate['uid'], $acl);
        }

        return $result ? null : 'createerror';
    }

    /**
     * Set/Update ACL on delegator's folders
     *
     * @param string $uid    Delegate authentication identifier
     * @param array  $acl    List of folder->right map
     * @param bool   $update Update (remove) old rights
     */
    public function delegate_acl_update($uid, $acl, $update = false)
    {
        $storage     = $this->rc->get_storage();
        $right_types = $this->right_types();
        $folders     = $update ? $this->list_folders($uid) : [];

        foreach ($acl as $folder_name => $rights) {
            $r = $right_types[$rights] ?? null;
            if ($r) {
                $storage->set_acl($folder_name, $uid, $r);
            } else {
                $storage->delete_acl($folder_name, $uid);
            }

            if (!empty($folders) && isset($folders[$folder_name])) {
                unset($folders[$folder_name]);
            }
        }

        foreach ($folders as $folder_name => $folder) {
            if (!empty($folder['rights'])) {
                $storage->delete_acl($folder_name, $uid);
            }
        }
    }

    /**
     * Delete delgate
     *
     * @param string $dn      Delegate DN (encoded)
     * @param bool   $acl_del Enable ACL deletion on delegator folders
     *
     * @return string On error returns an error label, on success returns null
     */
    public function delegate_delete($dn, $acl_del = false)
    {
        $delegate = $this->delegate_get($dn);
        $list     = $this->list_delegates();
        $user     = $this->user();

        if (empty($delegate) || !isset($list[$dn])) {
            return 'deleteerror';
        }

        // remove delegate from the list
        unset($list[$dn]);
        $list = array_keys($list);
        $list = array_map(['kolab_ldap', 'dn_decode'], $list);
        $user[$this->ldap_delegate_field] = $list;

        // update user record
        $result = $this->user_update_delegates($list);

        // remove ACL
        if ($result && $acl_del) {
            $this->delegate_acl_update($delegate['uid'], [], true);
        }

        return $result ? null : 'deleteerror';
    }

    /**
     * Return delegate data
     *
     * @param string $dn Delegate DN (encoded)
     *
     * @return array Delegate record (ID, name, uid, imap_uid)
     */
    public function delegate_get($dn)
    {
        // use internal cache so we not query LDAP more than once per request
        if (!isset($this->cache[$dn])) {
            $ldap = $this->ldap();

            if (!$ldap || empty($dn)) {
                return [];
            }

            // Get delegate
            $user = $ldap->get_record(kolab_ldap::dn_decode($dn));

            if (empty($user)) {
                return [];
            }

            $delegate = $this->parse_ldap_record($user);
            $delegate['ID'] = $dn;

            $this->cache[$dn] = $delegate;
        }

        return $this->cache[$dn];
    }

    /**
     * Return delegate data
     *
     * @param string $login Delegate name (the 'uid' returned in get_users())
     *
     * @return array Delegate record (ID, name, uid, imap_uid)
     */
    public function delegate_get_by_name($login)
    {
        $ldap = $this->ldap();

        if (!$ldap || empty($login)) {
            return [];
        }

        $list = $ldap->dosearch($this->ldap_login_field, $login, 1);

        if (count($list) == 1) {
            $dn   = key($list);
            $user = $list[$dn];

            return $this->parse_ldap_record($user, $dn);
        }

        return [];
    }

    /**
     * LDAP object getter
     *
     * @return ?kolab_ldap Kolab LDAP addressbook
     */
    private function ldap()
    {
        if ($this->ldap !== null) {
            return $this->ldap;
        }

        $this->ldap = kolab_storage::ldap('kolab_delegation_addressbook');

        if (!$this->ldap || !$this->ldap->ready) {
            return null;
        }

        // Default filter of LDAP queries
        $this->ldap_filter = $this->rc->config->get('kolab_delegation_filter', '(|(objectClass=kolabInetOrgPerson)(&(objectclass=kolabsharedfolder)(kolabFolderType=mail)))');
        // Name of the LDAP field for delegates list
        $this->ldap_delegate_field = $this->rc->config->get('kolab_delegation_delegate_field', 'kolabDelegate');
        // Encoded LDAP DN of current user, set on login by kolab_auth plugin
        $this->ldap_dn = $_SESSION['kolab_dn'];

        // Name of the LDAP field with authentication ID
        $this->ldap_login_field = $this->rc->config->get('kolab_delegation_login_field', $this->rc->config->get('kolab_auth_login'));
        // Name of the LDAP field with user name used for identities
        $this->ldap_name_field = $this->rc->config->get('kolab_delegation_name_field', $this->rc->config->get('kolab_auth_name'));
        // Name of the LDAP field with email addresses used for identities
        $this->ldap_email_field = $this->rc->config->get('kolab_delegation_email_field', $this->rc->config->get('kolab_auth_email'));
        // Name of the LDAP field with organization name for identities
        $this->ldap_org_field = $this->rc->config->get('kolab_delegation_organization_field', $this->rc->config->get('kolab_auth_organization'));

        $this->ldap->set_filter($this->ldap_filter);
        $this->ldap->extend_fieldmap([$this->ldap_delegate_field => $this->ldap_delegate_field]);

        return $this->ldap;
    }

    /**
     * List current user delegates
     */
    public function list_delegates()
    {
        $result = [];
        $ldap   = $this->ldap();
        $user   = $this->user();

        if (empty($ldap) || empty($user)) {
            return [];
        }

        // Get delegates of current user
        $delegates = $user[$this->ldap_delegate_field] ?? null;

        if (!empty($delegates)) {
            foreach ((array)$delegates as $dn) {
                $delegate = $ldap->get_record($dn);
                $data     = $this->parse_ldap_record($delegate, $dn);

                if (!empty($data) && !empty($data['name'])) {
                    $result[$data['ID']] = $data['name'];
                }
            }
        }

        return $result;
    }

    /**
     * List current user delegators
     *
     * @return array List of delegators
     */
    public function list_delegators()
    {
        $result = [];
        $ldap   = $this->ldap();

        if (empty($ldap) || empty($this->ldap_dn)) {
            return [];
        }

        $list = $ldap->dosearch($this->ldap_delegate_field, $this->ldap_dn, 1);

        foreach ($list as $dn => $delegator) {
            $delegator = $this->parse_ldap_record($delegator, $dn);
            $result[$delegator['ID']] = $delegator;
        }

        return $result;
    }

    /**
     * List current user delegators in format compatible with Calendar plugin
     *
     * @return array List of delegators
     */
    public function list_delegators_js()
    {
        $list   = $this->list_delegators();
        $result = [];

        foreach ($list as $delegator) {
            $name = $delegator['name'];
            if ($pos = strrpos($name, '(')) {
                $name = trim(substr($name, 0, $pos));
            }

            $result[$delegator['imap_uid']] = [
                'emails' => ';' . implode(';', $delegator['email']),
                'email'  => $delegator['email'][0],
                'name'   => $name,
            ];
        }

        return $result;
    }

    /**
     * Prepare namespace prefixes for JS environment
     *
     * @return array List of prefixes
     */
    public function namespace_js()
    {
        $storage = $this->rc->get_storage();
        $ns      = $storage->get_namespace('other');

        if ($ns) {
            foreach ($ns as $idx => $nsval) {
                $ns[$idx] = kolab_storage::folder_id($nsval[0]);
            }
        }

        return $ns;
    }

    /**
     * Get all folders to which current user has admin access
     *
     * @param string $delegate IMAP user identifier
     *
     * @return array Folder type/rights
     */
    public function list_folders($delegate = null)
    {
        $storage  = $this->rc->get_storage();
        $folders  = $storage->list_folders();
        $metadata = kolab_storage::folders_typedata();
        $result   = [];

        if (!is_array($metadata)) {
            return $result;
        }

        // Definition of read and write ACL
        $right_types = $this->right_types();

        $delegate_lc = strtolower((string) $delegate);

        foreach ($folders as $folder) {
            // get only folders in personal namespace
            if ($storage->folder_namespace($folder) != 'personal') {
                continue;
            }

            $rights = null;
            $type   = !empty($metadata[$folder]) ? $metadata[$folder] : 'mail';
            [$class, $subclass] = strpos($type, '.') ? explode('.', $type) : [$type, ''];

            if (!in_array($class, $this->folder_types)) {
                continue;
            }

            // in edit mode, get folder ACL
            if ($delegate) {
                // @TODO: cache ACL
                $imap_acl = $storage->get_acl($folder);
                if (!empty($imap_acl) && (($acl = ($imap_acl[$delegate] ?? null)) || ($acl = ($imap_acl[$delegate_lc] ?? null)))) {
                    if ($this->acl_compare($acl, $right_types[self::ACL_WRITE])) {
                        $rights = self::ACL_WRITE;
                    } elseif ($this->acl_compare($acl, $right_types[self::ACL_READ])) {
                        $rights = self::ACL_READ;
                    }
                }
            } elseif ($folder == 'INBOX' || $subclass == 'default' || $subclass == 'inbox') {
                $rights = self::ACL_WRITE;
            }

            $result[$folder] = [
                'type'   => $class,
                'rights' => $rights,
            ];
        }

        return $result;
    }

    /**
     * Returns list of users for autocompletion
     *
     * @param string $search Search string
     *
     * @return array Users list
     */
    public function list_users($search)
    {
        $ldap = $this->ldap();

        if (empty($ldap) || $search === '' || $search === null) {
            return [];
        }

        $max    = (int) $this->rc->config->get('autocomplete_max', 15);
        $mode   = (int) $this->rc->config->get('addressbook_search_mode');
        $fields = array_unique(array_filter(array_merge((array)$this->ldap_name_field, (array)$this->ldap_login_field)));
        $users  = [];
        $keys   = [];

        $result = $ldap->dosearch($fields, $search, $mode, (array)$this->ldap_login_field, $max);

        foreach ($result as $record) {
            // skip self
            if ($record['dn'] == $_SESSION['kolab_dn']) {
                continue;
            }

            $user = $this->parse_ldap_record($record);

            if ($uid = $user['uid']) {
                $display = rcube_addressbook::compose_search_name($record);
                $user    = ['name' => $uid, 'display' => $display];
                $users[] = $user;
                $keys[]  = $display ?: $uid;
            }
        }

        if (count($users)) {
            // sort users index
            asort($keys, SORT_LOCALE_STRING);
            // re-sort users according to index
            foreach (array_keys($keys) as $idx) {
                $keys[$idx] = $users[$idx];
            }
            $users = array_values($keys);
        }

        return $users;
    }

    /**
     * Extract delegate identifiers and pretty name from LDAP record
     */
    private function parse_ldap_record($data, $dn = null)
    {
        $email = [];
        $uid   = $data[$this->ldap_login_field];
        $name  = '';

        if (is_array($uid)) {
            $uid = array_filter($uid);
            $uid = $uid[0];
        }

        // User name for identity
        foreach ((array)$this->ldap_name_field as $field) {
            $name = is_array($data[$field]) ? $data[$field][0] : $data[$field];
            if (!empty($name)) {
                break;
            }
        }

        // User email(s) for identity
        foreach ((array)$this->ldap_email_field as $field) {
            $user_email = is_array($data[$field]) ? array_filter($data[$field]) : $data[$field];
            if (!empty($user_email)) {
                $email = array_merge((array)$email, (array)$user_email);
            }
        }

        // Organization for identity
        foreach ((array)$this->ldap_org_field as $field) {
            $organization = is_array($data[$field]) ? $data[$field][0] : $data[$field];
            if (!empty($organization)) {
                break;
            }
        }

        $realname = $name;
        if ($uid && $name) {
            $name .= ' (' . $uid . ')';
        } else {
            $name = $uid;
        }

        // get IMAP uid - identifier used in shared folder hierarchy
        $imap_uid = $uid;
        if ($pos = strpos($imap_uid, '@')) {
            $imap_uid = substr($imap_uid, 0, $pos);
        }

        return [
            'ID'       => kolab_ldap::dn_encode($dn),
            'uid'      => $uid,
            'name'     => $name,
            'realname' => $realname,
            'imap_uid' => $imap_uid,
            'email'    => $email,
            'organization' => $organization ?? null,
        ];
    }

    /**
     * Returns LDAP record of current user
     *
     * @return array User data
     */
    public function user($parsed = false)
    {
        if (!isset($this->cache['user'])) {
            $ldap = $this->ldap();

            if (!$ldap) {
                return [];
            }

            // Get current user record
            $this->cache['user'] = $ldap->get_record($this->ldap_dn);
        }

        return $parsed ? $this->parse_ldap_record($this->cache['user']) : $this->cache['user'];
    }

    /**
     * Update LDAP record of current user
     *
     * @param array $list List of delegates
     */
    public function user_update_delegates($list)
    {
        $ldap = $this->ldap();
        $pass = $this->rc->decrypt($_SESSION['password']);

        if (!$ldap) {
            return false;
        }

        // need to bind as self for sufficient privilages
        if (!$ldap->bind($this->ldap_dn, $pass)) {
            return false;
        }

        $user[$this->ldap_delegate_field] = $list;

        unset($this->cache['user']);

        // replace delegators list in user record
        return $ldap->replace($this->ldap_dn, $user);
    }

    /**
     * Manage delegation data on user login
     */
    public function delegation_init()
    {
        // Fetch all delegators from LDAP who assigned the
        // current user as their delegate and create identities
        //  a) if identity with delegator's email exists, continue
        //  b) create identity ($delegate on behalf of $delegator
        //        <$delegator-email>) for new delegators
        //  c) remove all other identities which do not match the user's primary
        //       or alias email if 'kolab_delegation_purge_identities' is set.

        $delegators = $this->list_delegators();
        $use_subs   = $this->rc->config->get('kolab_use_subscriptions');
        $identities = $this->rc->user->list_emails();
        $emails     = [];
        $uids       = [];

        if (!empty($delegators)) {
            $storage  = $this->rc->get_storage();
            $other_ns = $storage->get_namespace('other') ?: [];
            $folders  = $storage->list_folders();
        }

        // convert identities to simpler format for faster access
        foreach ($identities as $idx => $ident) {
            // get user name from default identity
            if (!$idx) {
                $default = [
                    'name' => $ident['name'],
                ];
            }
            $emails[$ident['identity_id']] = $ident['email'];
        }

        // for every delegator...
        foreach ($delegators as $delegator) {
            $uids[$delegator['imap_uid']] = $email_arr = $delegator['email'];
            $diff = array_intersect($emails, $email_arr);

            // identity with delegator's email already exist, do nothing
            if (count($diff)) {
                $emails = array_diff($emails, $email_arr);
                continue;
            }

            // create identities for delegator emails
            foreach ($email_arr as $email) {
                // @TODO: "Delegatorname" or "Username on behalf of Delegatorname"?
                $default['name']  = $delegator['realname'];
                $default['email'] = $email;
                // Database field for organization is NOT NULL
                $default['organization'] = empty($delegator['organization']) ? '' : $delegator['organization'];
                $this->rc->user->insert_identity($default);
            }

            // IMAP folders shared by new delegators shall be subscribed on login,
            // as well as existing subscriptions of previously shared folders shall
            // be removed. I suppose the latter one is already done in Roundcube.

            // for every accessible folder...
            foreach ($folders as $folder) {
                // for every 'other' namespace root...
                foreach ($other_ns as $ns) {
                    $prefix = $ns[0] . $delegator['imap_uid'];
                    // subscribe delegator's folder
                    if ($folder === $prefix || strpos($folder, $prefix . substr($ns[0], -1)) === 0) {
                        // Event/Task folders need client-side activation
                        $type = kolab_storage::folder_type($folder);
                        if (preg_match('/^(event|task)/i', $type)) {
                            kolab_storage::folder_activate($folder);
                        }
                        // Subscribe to mail folders and (if system is configured
                        // to display only subscribed folders) to other
                        if ($use_subs || preg_match('/^mail/i', $type)) {
                            $storage->subscribe($folder);
                        }
                    }
                }
            }
        }

        // remove identities that "do not belong" to user nor delegators
        if ($this->rc->config->get('kolab_delegation_purge_identities')) {
            $user   = $this->user(true);
            $emails = array_diff($emails, $user['email']);

            foreach (array_keys($emails) as $idx) {
                $this->rc->user->delete_identity($idx);
            }
        }

        $_SESSION['delegators'] = $uids;
    }

    /**
     * Sets delegator context according to email message recipient
     *
     * @param rcube_message $message Email message object
     */
    public function delegator_context_from_message($message)
    {
        if (empty($_SESSION['delegators'])) {
            return;
        }

        // Match delegators' addresses with message To: address
        // @TODO: Is this reliable enough?
        // Roundcube sends invitations to every attendee separately,
        // but maybe there's a software which sends with CC header or many addresses in To:

        $emails = $message->get_header('to');
        $emails = rcube_mime::decode_address_list($emails, null, false);

        foreach ($emails as $email) {
            foreach ($_SESSION['delegators'] as $uid => $addresses) {
                if (in_array($email['mailto'], $addresses)) {
                    return $this->context = $uid;
                }
            }
        }
    }

    /**
     * Return (set) current delegator context
     *
     * @return string Delegator UID
     */
    public function delegator_context()
    {
        if (!$this->context && !empty($_SESSION['delegators'])) {
            $context = rcube_utils::get_input_value('_context', rcube_utils::INPUT_GPC);
            if ($context && isset($_SESSION['delegators'][$context])) {
                $this->context = $context;
            }
        }

        return $this->context;
    }

    /**
     * Set user identity according to delegator delegator
     *
     * @param array $args Reference to plugin hook arguments
     */
    public function delegator_identity_filter(&$args)
    {
        $context = $this->delegator_context();

        if (!$context) {
            return;
        }

        $identities = $this->rc->user->list_emails();
        $emails     = $_SESSION['delegators'][$context];

        foreach ($identities as $ident) {
            if (in_array($ident['email'], $emails)) {
                $args['identity'] = $ident;
                return;
            }
        }

        // fallback to default identity
        $args['identity'] = array_shift($identities);
    }

    /**
     * Filter user emails according to delegator context
     *
     * @param array $args Reference to plugin hook arguments
     */
    public function delegator_emails_filter(&$args)
    {
        $context = $this->delegator_context();

        // try to derive context from the given user email
        if (!$context && !empty($args['emails'])) {
            if (($user = preg_replace('/@.+$/', '', $args['emails'][0])) && isset($_SESSION['delegators'][$user])) {
                $context = $user;
            }
        }

        // return delegator's addresses
        if ($context) {
            $args['emails'] = $_SESSION['delegators'][$context];
            $args['abort']  = true;
        }
        // return only user addresses (exclude all delegators addresses)
        elseif (!empty($_SESSION['delegators'])) {
            $identities = $this->rc->user->list_emails();
            $emails[]   = $this->rc->user->get_username();

            foreach ($identities as $identity) {
                $emails[] = $identity['email'];
            }

            foreach ($_SESSION['delegators'] as $delegator_emails) {
                $emails = array_diff($emails, $delegator_emails);
            }

            $args['emails'] = array_unique($emails);
            $args['abort']  = true;
        }
    }

    /**
     * Filters list of calendar/task folders according to delegator context
     *
     * @param array $args Reference to plugin hook arguments
     */
    public function delegator_folder_filter(&$args, $mode = 'calendars')
    {
        $context = $this->delegator_context();

        if (empty($context)) {
            return $args;
        }

        $storage  = $this->rc->get_storage();
        $other_ns = $storage->get_namespace('other') ?: [];
        $delim    = $storage->get_hierarchy_delimiter();

        if ($mode == 'calendars') {
            $editable = $args['filter'] & calendar_driver::FILTER_WRITEABLE;
            $active   = $args['filter'] & calendar_driver::FILTER_ACTIVE;
            $personal = $args['filter'] & calendar_driver::FILTER_PERSONAL;
            $shared   = $args['filter'] & calendar_driver::FILTER_SHARED;
        } else {
            $editable = $args['filter'] & tasklist_driver::FILTER_WRITEABLE;
            $active   = $args['filter'] & tasklist_driver::FILTER_ACTIVE;
            $personal = $args['filter'] & tasklist_driver::FILTER_PERSONAL;
            $shared   = $args['filter'] & tasklist_driver::FILTER_SHARED;
        }

        $folders = [];

        foreach ($args['list'] as $folder) {
            if (isset($folder->ready) && !$folder->ready) {
                continue;
            }

            if ($editable && !$folder->editable) {
                continue;
            }

            if ($active && !$folder->storage->is_active()) {
                continue;
            }

            if ($personal || $shared) {
                $ns = $folder->get_namespace();

                if ($personal && $ns == 'personal') {
                    continue;
                } elseif ($personal && $ns == 'other') {
                    $found = false;
                    foreach ($other_ns as $ns) {
                        $c_folder = $ns[0] . $context . $delim;
                        if (strpos($folder->name, $c_folder) === 0) {
                            $found = true;
                        }
                    }

                    if (!$found) {
                        continue;
                    }
                } elseif (!$shared || $ns != 'shared') {
                    continue;
                }
            }

            $folders[$folder->id] = $folder;
        }

        $args[$mode]   = $folders;
        $args['abort'] = true;
    }

    /**
     * Filters/updates message headers according to delegator context
     *
     * @param array $args Reference to plugin hook arguments
     */
    public function delegator_delivery_filter(&$args)
    {
        // no context, but message still can be send on behalf of...
        if (!empty($_SESSION['delegators'])) {
            $message = $args['message'];
            $headers = $message->headers();

            // get email address from From: header
            $from = rcube_mime::decode_address_list($headers['From']);
            $from = array_shift($from);
            $from = $from['mailto'];

            foreach ($_SESSION['delegators'] as $uid => $addresses) {
                if (in_array($from, $addresses)) {
                    $context = $uid;
                    break;
                }
            }

            // add Sender: header with current user default identity
            if (!empty($context)) {
                $identity = $this->rc->user->get_identity();
                $sender   = format_email_recipient($identity['email'], $identity['name']);

                $message->headers(['Sender' => $sender], false, true);
            }
        }
    }

    /**
     * Compares two ACLs (according to supported rights)
     *
     * @param array $acl1 ACL rights array (or string)
     * @param array $acl2 ACL rights array (or string)
     *
     * @return bool True if $acl1 contains all rights from $acl2
     */
    public function acl_compare($acl1, $acl2)
    {
        if (!is_array($acl1)) {
            $acl1 = str_split($acl1);
        }
        if (!is_array($acl2)) {
            $acl2 = str_split($acl2);
        }

        $rights = $this->rights_supported();

        $acl1 = array_intersect($acl1, $rights);
        $acl2 = array_intersect($acl2, $rights);
        $res  = array_intersect($acl1, $acl2);

        $cnt1 = count($res);
        $cnt2 = count($acl2);

        return $cnt1 >= $cnt2;
    }

    /**
     * Get list of supported access rights (according to RIGHTS capability)
     *
     * @todo: this is stolen from acl plugin, move to rcube_storage/rcube_imap
     *
     * @return array List of supported access rights abbreviations
     */
    public function rights_supported()
    {
        if ($this->supported !== null) {
            return $this->supported;
        }

        $storage = $this->rc->get_storage();
        $capa    = $storage->get_capability('RIGHTS');

        if (is_array($capa)) {
            $rights = strtolower($capa[0]);
        } else {
            $rights = 'cd';
        }

        return $this->supported = str_split('lrswi' . $rights . 'pa');
    }

    private function right_types()
    {
        // Get supported rights and build column names
        $supported = $this->rights_supported();

        // depending on server capability either use 'te' or 'd' for deleting msgs
        $deleteright = implode('', array_intersect(str_split('ted'), $supported));

        return [
            self::ACL_READ  => 'lrs',
            self::ACL_WRITE => 'lrswi' . $deleteright,
        ];
    }
}
