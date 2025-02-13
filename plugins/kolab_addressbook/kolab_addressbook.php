<?php

/**
 * Kolab address book
 *
 * Sample plugin to add a new address book source with data from Kolab storage
 * It provides also a possibilities to manage contact folders
 * (create/rename/delete/acl) directly in Addressbook UI.
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2011-2015, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_addressbook extends rcube_plugin
{
    public $task = '?(?!logout).*';

    public $driver;
    public $bonnie_api = false;

    private $sources;
    private $rc;
    private $ui;
    private $recurrent = false;

    public const GLOBAL_FIRST = 0;
    public const PERSONAL_FIRST = 1;
    public const GLOBAL_ONLY = 2;
    public const PERSONAL_ONLY = 3;

    /**
     * Startup method of a Roundcube plugin
     */
    public function init()
    {
        $this->rc = rcmail::get_instance();

        // load required plugin
        $this->require_plugin('libkolab');

        $this->load_config();

        $driver       = $this->rc->config->get('kolab_addressbook_driver') ?: 'kolab';
        $driver_class = "{$driver}_contacts_driver";

        require_once dirname(__FILE__) . "/drivers/{$driver}/{$driver}_contacts_driver.php";
        require_once dirname(__FILE__) . "/drivers/{$driver}/{$driver}_contacts.php";

        $this->driver = new $driver_class($this);

        // register hooks
        $this->add_hook('addressbooks_list', [$this, 'address_sources']);
        $this->add_hook('addressbook_get', [$this, 'get_address_book']);
        $this->add_hook('config_get', [$this, 'config_get']);

        if ($this->rc->task == 'addressbook') {
            $this->add_texts('localization');

            if ($this->driver instanceof kolab_contacts_driver) {
                $this->add_hook('contact_form', [$this, 'contact_form']);
                $this->add_hook('contact_photo', [$this, 'contact_photo']);
            }

            $this->add_hook('template_object_directorylist', [$this, 'directorylist_html']);

            // Plugin actions
            $this->register_action('plugin.book', [$this, 'book_actions']);
            $this->register_action('plugin.book-save', [$this, 'book_save']);
            $this->register_action('plugin.book-search', [$this, 'book_search']);
            $this->register_action('plugin.book-subscribe', [$this, 'book_subscribe']);

            $this->register_action('plugin.contact-changelog', [$this, 'contact_changelog']);
            $this->register_action('plugin.contact-diff', [$this, 'contact_diff']);
            $this->register_action('plugin.contact-restore', [$this, 'contact_restore']);

            $this->register_action('plugin.share-invitation', [$this, 'share_invitation']);

            // get configuration for the Bonnie API
            $this->bonnie_api = libkolab::get_bonnie_api();

            // Load UI elements
            if ($this->api->output->type == 'html') {
                require_once $this->home . '/lib/kolab_addressbook_ui.php';
                $this->ui = new kolab_addressbook_ui($this);

                if ($this->bonnie_api) {
                    $this->add_button([
                        'command'    => 'contact-history-dialog',
                        'class'      => 'history contact-history disabled',
                        'classact'   => 'history contact-history active',
                        'innerclass' => 'icon inner',
                        'label'      => 'kolab_addressbook.showhistory',
                        'type'       => 'link-menuitem',
                    ], 'contactmenu');
                }
            }
        } elseif ($this->rc->task == 'settings') {
            $this->add_texts('localization');
            $this->add_hook('preferences_list', [$this, 'prefs_list']);
            $this->add_hook('preferences_save', [$this, 'prefs_save']);
        }

        if ($this->driver instanceof kolab_contacts_driver) {
            $this->add_hook('folder_delete', [$this, 'prefs_folder_delete']);
            $this->add_hook('folder_rename', [$this, 'prefs_folder_rename']);
            $this->add_hook('folder_update', [$this, 'prefs_folder_update']);
        }
    }

    /**
     * Handler for the addressbooks_list hook.
     *
     * This will add all instances of available Kolab-based address books
     * to the list of address sources of Roundcube.
     * This will also hide some addressbooks according to kolab_addressbook_prio setting.
     *
     * @param array $p Hash array with hook parameters
     *
     * @return array Hash array with modified hook parameters
     */
    public function address_sources($p)
    {
        $abook_prio = $this->addressbook_prio();

        // Disable all global address books
        // Assumes that all non-kolab_addressbook sources are global
        if ($abook_prio == self::PERSONAL_ONLY) {
            $p['sources'] = [];
        }

        $sources = [];
        foreach ($this->_list_sources() as $abook_id => $abook) {
            // register this address source
            $sources[$abook_id] = $this->driver->abook_prop($abook_id, $abook);

            // flag folders with 'i' right as writeable
            if ($this->rc->action == 'add' && strpos($abook->rights, 'i') !== false) {
                $sources[$abook_id]['readonly'] = false;
            }
        }

        // Add personal address sources to the list
        if ($abook_prio == self::PERSONAL_FIRST) {
            // $p['sources'] = array_merge($sources, $p['sources']);
            // Don't use array_merge(), because if you have folders name
            // that resolve to numeric identifier it will break output array keys
            foreach ($p['sources'] as $idx => $value) {
                $sources[$idx] = $value;
            }
            $p['sources'] = $sources;
        } else {
            // $p['sources'] = array_merge($p['sources'], $sources);
            foreach ($sources as $idx => $value) {
                $p['sources'][$idx] = $value;
            }
        }

        return $p;
    }

    /**
     *
     */
    public function directorylist_html($args)
    {
        $out     = '';
        $spec    = '';
        $kolab   = '';
        $jsdata  = [];
        $sources = (array) $this->rc->get_address_sources();

        // list all non-kolab sources first (also exclude hidden sources), special folders will go last
        foreach ($sources  as $j => $source) {
            $id = strval(strlen($source['id']) ? $source['id'] : $j);
            if (!empty($source['kolab']) || !empty($source['hidden'])) {
                continue;
            }

            // Roundcube >= 1.5, Collected Recipients and Trusted Senders sources will be listed at the end
            if ((defined('rcube_addressbook::TYPE_RECIPIENT') && $source['id'] == (string) rcube_addressbook::TYPE_RECIPIENT)
                || (defined('rcube_addressbook::TYPE_TRUSTED_SENDER') && $source['id'] == (string) rcube_addressbook::TYPE_TRUSTED_SENDER)
            ) {
                $spec .= $this->addressbook_list_item($id, $source, $jsdata) . '</li>';
            } else {
                $out .= $this->addressbook_list_item($id, $source, $jsdata) . '</li>';
            }
        }

        // render a hierarchical list of kolab contact folders
        // TODO: Move this to the drivers
        if ($this->driver instanceof kolab_contacts_driver) {
            $folders = kolab_storage::sort_folders(kolab_storage::get_folders('contact'));
            kolab_storage::folder_hierarchy($folders, $tree);
            if ($tree && !empty($tree->children)) {
                $kolab .= $this->folder_tree_html($tree, $sources, $jsdata);
            }
        } else {
            $filter = function ($source) { return !empty($source['kolab']) && empty($source['hidden']); };
            foreach (array_filter($sources, $filter) as $j => $source) {
                $id = strval(strlen($source['id']) ? $source['id'] : $j);
                $kolab .= $this->addressbook_list_item($id, $source, $jsdata) . '</li>';
            }
        }

        $out .= $kolab . $spec;

        $this->rc->output->set_env('contactgroups', array_filter($jsdata, function ($src) { return isset($src['type']) && $src['type'] == 'group'; }));
        $this->rc->output->set_env('address_sources', array_filter($jsdata, function ($src) { return !isset($src['type']) || $src['type'] != 'group'; }));

        $args['content'] = html::tag('ul', $args, $out, html::$common_attrib);
        return $args;
    }

    /**
     * Return html for a structured list <ul> for the folder tree
     */
    protected function folder_tree_html($node, $data, &$jsdata)
    {
        $out = '';
        foreach ($node->children as $folder) {
            $id = $folder->id;
            $source = $data[$id];
            $is_collapsed = strpos($this->rc->config->get('collapsed_abooks', ''), '&' . rawurlencode($id) . '&') !== false;

            if ($folder instanceof kolab_storage_folder_virtual) {
                $source = $this->driver->abook_prop($folder->id, $folder);
            } elseif (empty($source)) {
                $this->sources[$id] = new kolab_contacts($folder->name);
                $source = $this->driver->abook_prop($id, $this->sources[$id]);
            }

            $content = $this->addressbook_list_item($id, $source, $jsdata);

            if (!empty($folder->children)) {
                $child_html = $this->folder_tree_html($folder, $data, $jsdata);

                // copy group items...
                if (preg_match('!<ul[^>]*>(.*)</ul>\n*$!Ums', $content, $m)) {
                    $child_html = $m[1] . $child_html;
                    $content = substr($content, 0, -strlen($m[0]));
                }
                // ... and re-create the subtree
                if (!empty($child_html)) {
                    $content .= html::tag('ul', ['class' => 'groups', 'style' => ($is_collapsed ? "display:none;" : null)], $child_html);
                }
            }

            $out .= $content . '</li>';
        }

        return $out;
    }

    /**
     *
     */
    protected function addressbook_list_item($id, $source, &$jsdata, $search_mode = false)
    {
        $current = rcube_utils::get_input_value('_source', rcube_utils::INPUT_GPC);

        if (empty($source['virtual'])) {
            $jsdata[$id] = $source;
            $jsdata[$id]['name'] = html_entity_decode($source['name'], ENT_NOQUOTES, RCUBE_CHARSET);
        }

        // set class name(s)
        $classes = ['addressbook'];
        if (!empty($source['group'])) {
            $classes[] = $source['group'];
        }
        if ($current === $id) {
            $classes[] = 'selected';
        }
        if (!empty($source['readonly'])) {
            $classes[] = 'readonly';
        }
        if (!empty($source['virtual'])) {
            $classes[] = 'virtual';
        }
        if (!empty($source['class_name'])) {
            $classes[] = $source['class_name'];
        }

        $name = !empty($source['listname']) ? $source['listname'] : (!empty($source['name']) ? $source['name'] : $id);
        $label_id = 'kabt:' . $id;
        $inner = (
            !empty($source['virtual']) ?
            html::a(['tabindex' => '0'], $name) :
            html::a([
                    'href' => $this->rc->url(['_source' => $id]),
                    'rel' => $source['id'],
                    'id' => $label_id,
                    'class' => 'listname',
                    'onclick' => "return " . rcmail_output::JS_OBJECT_NAME . ".command('list','" . rcube::JQ($id) . "',this)",
                ], $name)
        );

        if ($this->driver instanceof kolab_contacts_driver && isset($source['subscribed'])) {
            $inner .= html::span([
                'class' => 'subscribed',
                'title' => $this->gettext('foldersubscribe'),
                'role' => 'checkbox',
                'aria-checked' => $source['subscribed'] ? 'true' : 'false',
            ], '');
        }

        // don't wrap in <li> but add a checkbox for search results listing
        if ($search_mode) {
            $jsdata[$id]['group'] = implode(' ', $classes);

            if (empty($source['virtual'])) {
                $inner .= html::tag('input', [
                    'type' => 'checkbox',
                    'name' => '_source[]',
                    'value' => $id,
                    'checked' => false,
                    'aria-labelledby' => $label_id,
                ]);
            }
            return html::div(null, $inner);
        }

        $out = html::tag(
            'li',
            [
                'id' => 'rcmli' . rcube_utils::html_identifier($id, true),
                'class' => implode(' ', $classes),
                'noclose' => true,
            ],
            html::div(!empty($source['subscribed']) ? 'subscribed' : null, $inner)
        );

        $groupdata = ['out' => '', 'jsdata' => $jsdata, 'source' => $id];
        if ($source['groups']) {
            if (function_exists('rcmail_contact_groups')) {
                $groupdata = rcmail_contact_groups($groupdata);
            } else {
                // Roundcube >= 1.5
                $groupdata = rcmail_action_contacts_index::contact_groups($groupdata);
            }
        }

        $jsdata = $groupdata['jsdata'];
        $out .= $groupdata['out'];

        return $out;
    }

    /**
     * Sets autocomplete_addressbooks option according to
     * kolab_addressbook_prio setting extending list of address sources
     * to be used for autocompletion.
     */
    public function config_get($args)
    {
        if ($args['name'] != 'autocomplete_addressbooks' || $this->recurrent) {
            return $args;
        }

        $abook_prio = $this->addressbook_prio();

        // Get the original setting, use temp flag to prevent from an infinite recursion
        $this->recurrent = true;
        $sources = $this->rc->config->get('autocomplete_addressbooks');
        $this->recurrent = false;

        // Disable all global address books
        // Assumes that all non-kolab_addressbook sources are global
        if ($abook_prio == self::PERSONAL_ONLY) {
            $sources = [];
        }

        if (!is_array($sources)) {
            $sources = [];
        }

        $kolab_sources = [];
        foreach (array_keys($this->_list_sources()) as $abook_id) {
            if (!in_array($abook_id, $sources)) {
                $kolab_sources[] = $abook_id;
            }
        }

        // Add personal address sources to the list
        if (!empty($kolab_sources)) {
            if ($abook_prio == self::PERSONAL_FIRST) {
                $sources = array_merge($kolab_sources, $sources);
            } else {
                $sources = array_merge($sources, $kolab_sources);
            }
        }

        $args['result'] = $sources;

        return $args;
    }

    /**
     * Getter for the rcube_addressbook instance
     *
     * @param array $p Hash array with hook parameters
     *
     * @return array Hash array with modified hook parameters
     */
    public function get_address_book($p)
    {
        if ($p['id']) {
            if ($source = $this->driver->get_address_book($p['id'])) {
                $p['instance'] = $source;

                // flag source as writeable if 'i' right is given
                if ($p['writeable'] && $this->rc->action == 'save' && strpos($p['instance']->rights, 'i') !== false) {
                    $p['instance']->readonly = false;
                } elseif ($this->rc->action == 'delete' && strpos($p['instance']->rights, 't') !== false) {
                    $p['instance']->readonly = false;
                }
            }
        }

        return $p;
    }

    /**
     * List addressbook sources list
     */
    private function _list_sources()
    {
        // already read sources
        if (isset($this->sources)) {
            return $this->sources;
        }

        $this->sources = [];

        $abook_prio = $this->addressbook_prio();

        // Personal address source(s) disabled?
        if ($abook_prio == kolab_addressbook::GLOBAL_ONLY) {
            return $this->sources;
        }

        $folders = $this->driver->list_folders();

        // get all folders that have "contact" type
        foreach ($folders as $id => $source) {
            $this->sources[$id] = $source;
        }

        return $this->sources;
    }

    /**
     * Plugin hook called before rendering the contact form or detail view
     *
     * @param array $p Hash array with hook parameters
     *
     * @return array Hash array with modified hook parameters
     */
    public function contact_form($p)
    {
        // none of our business
        if (empty($GLOBALS['CONTACTS']) || !($GLOBALS['CONTACTS'] instanceof kolab_contacts)) {
            return $p;
        }

        // extend the list of contact fields to be displayed in the 'personal' section
        if (is_array($p['form']['personal'])) {
            $p['form']['personal']['content']['profession']    = ['size' => 40];
            $p['form']['personal']['content']['children']      = ['size' => 40];
            $p['form']['personal']['content']['freebusyurl']   = ['size' => 40];
            $p['form']['personal']['content']['pgppublickey']  = ['size' => 70];
            $p['form']['personal']['content']['pkcs7publickey'] = ['size' => 70];

            // re-order fields according to the coltypes list
            $p['form']['contact']['content']  = $this->_sort_form_fields($p['form']['contact']['content'], $GLOBALS['CONTACTS']);
            $p['form']['personal']['content'] = $this->_sort_form_fields($p['form']['personal']['content'], $GLOBALS['CONTACTS']);

            /* define a separate section 'settings'
            $p['form']['settings'] = array(
                'name'    => $this->gettext('settings'),
                'content' => array(
                    'freebusyurl'  => array('size' => 40, 'visible' => true),
                    'pgppublickey' => array('size' => 70, 'visible' => true),
                    'pkcs7publickey' => array('size' => 70, 'visible' => false),
                )
            );
            */
        }

        if ($this->bonnie_api && $this->rc->action == 'show' && empty($p['record']['rev'])) {
            $this->rc->output->set_env('kolab_audit_trail', true);
        }

        return $p;
    }

    /**
     * Plugin hook for the contact photo image
     */
    public function contact_photo($p)
    {
        // add photo data from old revision inline as data url
        if (!empty($p['record']['rev']) && !empty($p['data'])) {
            $p['url'] = 'data:image/gif;base64,' . base64_encode($p['data']);
        }

        return $p;
    }

    /**
     * Handler for contact audit trail changelog requests
     */
    public function contact_changelog()
    {
        if (empty($this->bonnie_api)) {
            return false;
        }

        $contact = rcube_utils::get_input_value('cid', rcube_utils::INPUT_POST, true);
        $source = rcube_utils::get_input_value('source', rcube_utils::INPUT_POST);

        [$uid, $mailbox, $msguid] = $this->_resolve_contact_identity($contact, $source);

        $result = $uid && $mailbox ? $this->bonnie_api->changelog('contact', $uid, $mailbox, $msguid) : null;
        if (is_array($result) && $result['uid'] == $uid) {
            if (is_array($result['changes'])) {
                $rcmail = $this->rc;
                $dtformat = $this->rc->config->get('date_format') . ' ' . $this->rc->config->get('time_format');
                array_walk($result['changes'], function (&$change) use ($rcmail, $dtformat) {
                    if ($change['date']) {
                        $dt = rcube_utils::anytodatetime($change['date']);
                        if ($dt instanceof DateTime) {
                            $change['date'] = $rcmail->format_date($dt, $dtformat);
                        }
                    }
                });
            }
            $this->rc->output->command('contact_render_changelog', $result['changes']);
        } else {
            $this->rc->output->command('contact_render_changelog', false);
        }

        $this->rc->output->send();
    }

    /**
     * Handler for audit trail diff view requests
     */
    public function contact_diff()
    {
        if (empty($this->bonnie_api)) {
            return false;
        }

        $contact = rcube_utils::get_input_value('cid', rcube_utils::INPUT_POST, true);
        $source = rcube_utils::get_input_value('source', rcube_utils::INPUT_POST);
        $rev1 = rcube_utils::get_input_value('rev1', rcube_utils::INPUT_POST);
        $rev2 = rcube_utils::get_input_value('rev2', rcube_utils::INPUT_POST);

        [$uid, $mailbox, $msguid] = $this->_resolve_contact_identity($contact, $source);

        $result = $this->bonnie_api->diff('contact', $uid, $rev1, $rev2, $mailbox, $msguid);
        if (is_array($result) && $result['uid'] == $uid) {
            $result['rev1'] = $rev1;
            $result['rev2'] = $rev2;
            $result['cid'] = $contact;

            // convert some properties, similar to kolab_contacts::_to_rcube_contact()
            $keymap = [
                'lastmodified-date' => 'changed',
                'additional' => 'middlename',
                'fn' => 'name',
                'tel' => 'phone',
                'url' => 'website',
                'bday' => 'birthday',
                'note' => 'notes',
                'role' => 'profession',
                'title' => 'jobtitle',
            ];

            $propmap = ['email' => 'address', 'website' => 'url', 'phone' => 'number'];
            $date_format = $this->rc->config->get('date_format', 'Y-m-d');

            // map kolab object properties to keys and values the client expects
            array_walk($result['changes'], function (&$change, $i) use ($keymap, $propmap, $date_format) {
                if (array_key_exists($change['property'], $keymap)) {
                    $change['property'] = $keymap[$change['property']];
                }

                // format date-time values
                if ($change['property'] == 'created' || $change['property'] == 'changed') {
                    if ($old_ = rcube_utils::anytodatetime($change['old'])) {
                        $change['old_'] = $this->rc->format_date($old_);
                    }
                    if ($new_ = rcube_utils::anytodatetime($change['new'])) {
                        $change['new_'] = $this->rc->format_date($new_);
                    }
                }
                // format dates
                elseif ($change['property'] == 'birthday' || $change['property'] == 'anniversary') {
                    if ($old_ = rcube_utils::anytodatetime($change['old'])) {
                        $change['old_'] = $this->rc->format_date($old_, $date_format);
                    }
                    if ($new_ = rcube_utils::anytodatetime($change['new'])) {
                        $change['new_'] = $this->rc->format_date($new_, $date_format);
                    }
                }
                // convert email, website, phone values
                elseif (array_key_exists($change['property'], $propmap)) {
                    $propname = $propmap[$change['property']];
                    foreach (['old','new'] as $k) {
                        $k_ = $k . '_';
                        if (!empty($change[$k])) {
                            $change[$k_] = html::quote($change[$k][$propname] ?: '--');
                            if ($change[$k]['type']) {
                                $change[$k_] .= '&nbsp;' . html::span('subtype', $this->get_type_label($change[$k]['type']));
                            }
                            $change['ishtml'] = true;
                        }
                    }
                }
                // serialize address structs
                if ($change['property'] == 'address') {
                    foreach (['old','new'] as $k) {
                        $k_ = $k . '_';
                        $change[$k]['zipcode'] = $change[$k]['code'];
                        $template = $this->rc->config->get('address_template', '{' . implode('} {', array_keys($change[$k])) . '}');
                        $composite = [];
                        foreach ($change[$k] as $p => $val) {
                            if (strlen($val)) {
                                $composite['{' . $p . '}'] = $val;
                            }
                        }
                        $change[$k_] = preg_replace('/\{\w+\}/', '', strtr($template, $composite));
                        if ($change[$k]['type']) {
                            $change[$k_] .= html::div('subtype', $this->get_type_label($change[$k]['type']));
                        }
                        $change['ishtml'] = true;
                    }

                    $change['diff_'] = libkolab::html_diff($change['old_'], $change['new_'], true);
                }
                // localize gender values
                elseif ($change['property'] == 'gender') {
                    if ($change['old']) {
                        $change['old_'] = $this->rc->gettext($change['old']);
                    }
                    if ($change['new']) {
                        $change['new_'] = $this->rc->gettext($change['new']);
                    }
                }
                // translate 'key' entries in individual properties
                elseif ($change['property'] == 'key') {
                    $p = $change['old'] ?: $change['new'];
                    $t = $p['type'];
                    $change['property'] = $t . 'publickey';
                    $change['old'] = $change['old'] ? $change['old']['key'] : '';
                    $change['new'] = $change['new'] ? $change['new']['key'] : '';
                }
                // compute a nice diff of notes
                elseif ($change['property'] == 'notes') {
                    $change['diff_'] = libkolab::html_diff($change['old'], $change['new'], false);
                }
            });

            $this->rc->output->command('contact_show_diff', $result);
        } else {
            $this->rc->output->command('display_message', $this->gettext('objectdiffnotavailable'), 'error');
        }

        $this->rc->output->send();
    }

    /**
     * Handler for audit trail revision restore requests
     */
    public function contact_restore()
    {
        if (empty($this->bonnie_api)) {
            return false;
        }

        $success = false;
        $contact = rcube_utils::get_input_value('cid', rcube_utils::INPUT_POST, true);
        $source = rcube_utils::get_input_value('source', rcube_utils::INPUT_POST);
        $rev = rcube_utils::get_input_value('rev', rcube_utils::INPUT_POST);

        [$uid, $mailbox, $msguid] = $this->_resolve_contact_identity($contact, $source, $folder);

        if ($folder && ($raw_msg = $this->bonnie_api->rawdata('contact', $uid, $rev, $mailbox))) {
            $imap = $this->rc->get_storage();

            // insert $raw_msg as new message
            if ($imap->save_message($folder->name, $raw_msg, null, false)) {
                $success = true;

                // delete old revision from imap and cache
                $imap->delete_message($msguid, $folder->name);
                $folder->cache->set($msguid, false);
            }
        }

        if ($success) {
            $this->rc->output->command('display_message', $this->gettext(['name' => 'objectrestoresuccess', 'vars' => ['rev' => $rev]]), 'confirmation');
            $this->rc->output->command('close_contact_history_dialog', $contact);
        } else {
            $this->rc->output->command('display_message', $this->gettext('objectrestoreerror'), 'error');
        }

        $this->rc->output->send();
    }

    /**
     * Get a previous revision of the given contact record from the Bonnie API
     */
    public function get_revision($cid, $source, $rev)
    {
        if (empty($this->bonnie_api)) {
            return false;
        }

        [$uid, $mailbox, $msguid] = $this->_resolve_contact_identity($cid, $source);

        // call Bonnie API
        $result = $this->bonnie_api->get('contact', $uid, $rev, $mailbox, $msguid);
        if (is_array($result) && $result['uid'] == $uid && !empty($result['xml'])) {
            $format = kolab_format::factory('contact');
            $format->load($result['xml']);
            $rec = $format->to_array();

            if ($format->is_valid()) {
                $rec['rev'] = $result['rev'];
                return $rec;
            }
        }

        return false;
    }

    /**
     * Helper method to resolved the given contact identifier into uid and mailbox
     *
     * @return array (uid,mailbox,msguid) tuple
     */
    private function _resolve_contact_identity($id, $abook, &$folder = null)
    {
        $mailbox = $msguid = null;

        $source = $this->get_address_book(['id' => $abook]);
        if ($source['instance']) {
            $uid = $source['instance']->id2uid($id);
            $list = kolab_storage::id_decode($abook);
        } else {
            return [null, $mailbox, $msguid];
        }

        // get resolve message UID and mailbox identifier
        if ($folder = kolab_storage::get_folder($list)) {
            $mailbox = $folder->get_mailbox_id();
            $msguid = $folder->cache->uid2msguid($uid);
        }

        return [$uid, $mailbox, $msguid];
    }

    /**
     *
     */
    private function _sort_form_fields($contents, $source)
    {
        $block = [];

        foreach (array_keys($source->coltypes) as $col) {
            if (isset($contents[$col])) {
                $block[$col] = $contents[$col];
            }
        }

        return $block;
    }

    /**
     * Handler for user preferences form (preferences_list hook)
     *
     * @param array $args Hash array with hook parameters
     *
     * @return array Hash array with modified hook parameters
     */
    public function prefs_list($args)
    {
        if ($args['section'] != 'addressbook') {
            return $args;
        }

        $ldap_public = $this->rc->config->get('ldap_public');

        // Hide option if there's no global addressbook
        if (empty($ldap_public)) {
            return $args;
        }

        // Check that configuration is not disabled
        $dont_override = (array) $this->rc->config->get('dont_override', []);
        $prio          = $this->addressbook_prio();

        if (!in_array('kolab_addressbook_prio', $dont_override)) {
            // Load localization
            $this->add_texts('localization');

            $field_id = '_kolab_addressbook_prio';
            $select   = new html_select(['name' => $field_id, 'id' => $field_id]);

            $select->add($this->gettext('globalfirst'), self::GLOBAL_FIRST);
            $select->add($this->gettext('personalfirst'), self::PERSONAL_FIRST);
            $select->add($this->gettext('globalonly'), self::GLOBAL_ONLY);
            $select->add($this->gettext('personalonly'), self::PERSONAL_ONLY);

            $args['blocks']['main']['options']['kolab_addressbook_prio'] = [
                'title' => html::label($field_id, rcube::Q($this->gettext('addressbookprio'))),
                'content' => $select->show($prio),
            ];
        }

        return $args;
    }

    /**
     * Handler for user preferences save (preferences_save hook)
     *
     * @param array $args Hash array with hook parameters
     *
     * @return array Hash array with modified hook parameters
     */
    public function prefs_save($args)
    {
        if ($args['section'] != 'addressbook') {
            return $args;
        }

        // Check that configuration is not disabled
        $dont_override = (array) $this->rc->config->get('dont_override', []);
        $key           = 'kolab_addressbook_prio';

        if (!in_array('kolab_addressbook_prio', $dont_override) || !isset($_POST['_' . $key])) {
            $args['prefs'][$key] = (int) rcube_utils::get_input_value('_' . $key, rcube_utils::INPUT_POST);
        }

        return $args;
    }

    /**
     * Handler for plugin actions
     */
    public function book_actions()
    {
        $action = trim(rcube_utils::get_input_value('_act', rcube_utils::INPUT_GPC));

        if ($action == 'create') {
            $this->ui->book_edit();
        } elseif ($action == 'edit') {
            $this->ui->book_edit();
        } elseif ($action == 'delete') {
            $this->book_delete();
        }
    }

    /**
     * Handler for address book create/edit form submit
     */
    public function book_save()
    {
        $this->driver->folder_save();
        $this->rc->output->send('iframe');
    }

    /**
     * Search for addressbook folders not subscribed yet
     */
    public function book_search()
    {
        $query  = rcube_utils::get_input_value('q', rcube_utils::INPUT_GPC);
        $source = rcube_utils::get_input_value('source', rcube_utils::INPUT_GPC);

        $jsdata = [];
        $results = [];

        // build results list
        foreach ($this->driver->search_folders($query, $source) as $prop) {
            $html = $this->addressbook_list_item($prop['id'], $prop, $jsdata, true);
            unset($prop['group']);
            $prop += (array)$jsdata[$prop['id']];
            $prop['html'] = $html;

            $results[] = $prop;
        }

        // report more results available
        if ($this->driver->search_more_results) {
            $this->rc->output->show_message('autocompletemore', 'notice');
        }

        $this->rc->output->command('multi_thread_http_response', $results, rcube_utils::get_input_value('_reqid', rcube_utils::INPUT_GPC));
    }

    /**
     * Handler for address book subscription action
     */
    public function book_subscribe()
    {
        $id = rcube_utils::get_input_value('_source', rcube_utils::INPUT_GPC);
        $options = [
            'permanent' => $_POST['_permanent'] ?? null,
            'active' => $_POST['_active'] ?? null,
            'groups' => !empty($_POST['_groups']),
        ];

        if ($success = $this->driver->folder_subscribe($id, $options)) {
            // list groups for this address book
            if ($options['groups']) {
                $abook = $this->driver->get_address_book($id);
                foreach ((array) $abook->list_groups() as $prop) {
                    $prop['source'] = $id;
                    $prop['id'] = $prop['ID'];
                    unset($prop['ID']);
                    $this->rc->output->command('insert_contact_group', $prop);
                }
            }
        }

        if ($success) {
            $this->rc->output->show_message('successfullysaved', 'confirmation');
        } else {
            $this->rc->output->show_message($this->gettext('errorsaving'), 'error');
        }

        $this->rc->output->send();
    }

    /**
     * Handler for address book delete action (AJAX)
     */
    private function book_delete()
    {
        $source = trim(rcube_utils::get_input_value('_source', rcube_utils::INPUT_GPC, true));

        if ($source && $this->driver->folder_delete($source)) {
            $storage   = $this->rc->get_storage();
            $delimiter = $storage->get_hierarchy_delimiter();

            $this->rc->output->show_message('kolab_addressbook.bookdeleted', 'confirmation');
            $this->rc->output->set_env('pagecount', 0);
            $this->rc->output->command('set_rowcount', $this->rc->gettext('nocontactsfound'));
            $this->rc->output->command('set_env', 'delimiter', $delimiter);
            $this->rc->output->command('list_contacts_clear');
            $this->rc->output->command('book_delete_done', $source);
        } else {
            $this->rc->output->show_message('kolab_addressbook.bookdeleteerror', 'error');
        }

        $this->rc->output->send();
    }

    /**
     * Handle invitations to a shared folder
     */
    public function share_invitation()
    {
        $id = rcube_utils::get_input_value('id', rcube_utils::INPUT_POST);
        $invitation = rcube_utils::get_input_value('invitation', rcube_utils::INPUT_POST);

        if ($addressbook = $this->driver->accept_share_invitation($invitation)) {
            $this->rc->output->command('plugin.share-invitation', ['id' => $id, 'source' => $addressbook]);
        }
    }

    /**
     * Returns value of kolab_addressbook_prio setting
     */
    private function addressbook_prio()
    {
        $abook_prio = (int) $this->rc->config->get('kolab_addressbook_prio');

        // Make sure any global addressbooks are defined
        if ($abook_prio == 0 || $abook_prio == 2) {
            $ldap_public = $this->rc->config->get('ldap_public');

            if (empty($ldap_public)) {
                $abook_prio = 1;
            }
        }

        return $abook_prio;
    }

    /**
     * Hook for (contact) folder deletion
     */
    public function prefs_folder_delete($args)
    {
        // ignore...
        if ($args['abort'] && !$args['result']) {
            return $args;
        }

        $this->_contact_folder_rename($args['name'], false);
    }

    /**
     * Hook for (contact) folder renaming
     */
    public function prefs_folder_rename($args)
    {
        // ignore...
        if ($args['abort'] && !$args['result']) {
            return $args;
        }

        $this->_contact_folder_rename($args['oldname'], $args['newname']);
    }

    /**
     * Hook for (contact) folder updates. Forward to folder_rename handler if name was changed
     */
    public function prefs_folder_update($args)
    {
        // ignore...
        if ($args['abort'] && !$args['result']) {
            return $args;
        }

        if ($args['record']['name'] != $args['record']['oldname']) {
            $this->_contact_folder_rename($args['record']['oldname'], $args['record']['name']);
        }
    }

    /**
     * Apply folder renaming or deletion to the registered birthday calendar address books
     */
    private function _contact_folder_rename($oldname, $newname = false)
    {
        $update = false;
        $delimiter = $this->rc->get_storage()->get_hierarchy_delimiter();
        $bday_addressbooks = (array) $this->rc->config->get('calendar_birthday_adressbooks', []);

        foreach ($bday_addressbooks as $i => $id) {
            $folder_name = kolab_storage::id_decode($id);
            if ($oldname === $folder_name || strpos($folder_name, $oldname . $delimiter) === 0) {
                if ($newname) {  // rename
                    $new_folder = $newname . substr($folder_name, strlen($oldname));
                    $bday_addressbooks[$i] = kolab_storage::id_encode($new_folder);
                } else {  // delete
                    unset($bday_addressbooks[$i]);
                }
                $update = true;
            }
        }

        if ($update) {
            $this->rc->user->save_prefs(['calendar_birthday_adressbooks' => $bday_addressbooks]);
        }
    }

    /**
     * Get a localization label for specified field type
     */
    private function get_type_label($type)
    {
        // Roundcube < 1.5
        if (function_exists('rcmail_get_type_label')) {
            return rcmail_get_type_label($type);
        }

        // Roundcube >= 1.5
        return rcmail_action_contacts_index::get_type_label($type);
    }
}
