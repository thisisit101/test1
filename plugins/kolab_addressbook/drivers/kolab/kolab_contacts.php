<?php

/**
 * Backend class for a custom address book
 *
 * This part of the Roundcube+Kolab integration and connects the
 * rcube_addressbook interface with the kolab_storage wrapper from libkolab
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2011, Kolab Systems AG <contact@kolabsys.com>
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
 *
 * @see rcube_addressbook
 */
class kolab_contacts extends rcube_addressbook
{
    public $primary_key = 'ID';
    public $rights   = 'lrs';
    public $readonly = true;
    public $undelete = true;
    public $groups = true;
    public $coltypes = [
        'name'         => ['limit' => 1],
        'firstname'    => ['limit' => 1],
        'surname'      => ['limit' => 1],
        'middlename'   => ['limit' => 1],
        'prefix'       => ['limit' => 1],
        'suffix'       => ['limit' => 1],
        'nickname'     => ['limit' => 1],
        'jobtitle'     => ['limit' => 1],
        'organization' => ['limit' => 1],
        'department'   => ['limit' => 1],
        'email'        => ['subtypes' => ['home','work','other']],
        'phone'        => [],
        'address'      => ['subtypes' => ['home','work','office']],
        'website'      => ['subtypes' => ['homepage','blog']],
        'im'           => ['subtypes' => null],
        'gender'       => ['limit' => 1],
        'birthday'     => ['limit' => 1],
        'anniversary'  => ['limit' => 1],
        'profession'   => [
            'type'      => 'text',
            'size'      => 40,
            'maxlength' => 80,
            'limit'     => 1,
            'label'     => 'kolab_addressbook.profession',
            'category'  => 'personal',
        ],
        'manager'      => ['limit' => null],
        'assistant'    => ['limit' => null],
        'spouse'       => ['limit' => 1],
        'children'     => [
            'type'      => 'text',
            'size'      => 40,
            'maxlength' => 80,
            'limit'     => null,
            'label'     => 'kolab_addressbook.children',
            'category'  => 'personal',
        ],
        'freebusyurl'  => [
            'type'  => 'text',
            'size'  => 40,
            'limit' => 1,
            'label' => 'kolab_addressbook.freebusyurl',
        ],
        'pgppublickey' => [
            'type' => 'textarea',
            'size' => 70,
            'rows' => 10,
            'limit' => 1,
            'label' => 'kolab_addressbook.pgppublickey',
        ],
        'pkcs7publickey' => [
            'type' => 'textarea',
            'size' => 70,
            'rows' => 10,
            'limit' => 1,
            'label' => 'kolab_addressbook.pkcs7publickey',
        ],
        'notes'        => ['limit' => 1],
        'photo'        => ['limit' => 1],
        // TODO: define more Kolab-specific fields such as: language, latitude, longitude, crypto settings
    ];

    /**
     * vCard additional fields mapping
     */
    public $vcard_map = [
        'profession'     => 'X-PROFESSION',
        'officelocation' => 'X-OFFICE-LOCATION',
        'initials'       => 'X-INITIALS',
        'children'       => 'X-CHILDREN',
        'freebusyurl'    => 'X-FREEBUSY-URL',
        'pgppublickey'   => 'KEY',
    ];

    /**
     * List of date type fields
     */
    public $date_cols = ['birthday', 'anniversary'];

    private $gid;
    private $storagefolder;
    private $dataset;
    private $sortindex;
    private $contacts;
    private $distlists;
    private $groupmembers;
    private $filter;
    private $result;
    private $namespace;
    private $imap_folder = 'INBOX/Contacts';
    private $action;

    // list of fields used for searching in "All fields" mode
    private $search_fields = [
        'name',
        'firstname',
        'surname',
        'middlename',
        'prefix',
        'suffix',
        'nickname',
        'jobtitle',
        'organization',
        'department',
        'email',
        'phone',
        'address',
        'profession',
        'manager',
        'assistant',
        'spouse',
        'children',
        'notes',
    ];


    public function __construct($imap_folder = null)
    {
        if ($imap_folder) {
            $this->imap_folder = $imap_folder;
        }

        // extend coltypes configuration
        /** @var kolab_format_contact $format */
        $format = kolab_format::factory('contact');

        $this->coltypes['phone']['subtypes']   = array_keys($format->phonetypes);
        $this->coltypes['address']['subtypes'] = array_keys($format->addresstypes);

        $rcube = rcmail::get_instance();

        // set localized labels for proprietary cols
        foreach ($this->coltypes as $col => $prop) {
            if (!empty($prop['label']) && is_string($prop['label'])) {
                $this->coltypes[$col]['label'] = $rcube->gettext($prop['label']);
            }
        }

        // fetch objects from the given IMAP folder
        $this->storagefolder = kolab_storage::get_folder($this->imap_folder);
        $this->ready = $this->storagefolder && !PEAR::isError($this->storagefolder);

        // Set readonly and rights flags according to folder permissions
        if ($this->ready) {
            if ($this->storagefolder->get_owner() == $_SESSION['username']) {
                $this->readonly = false;
                $this->rights = 'lrswikxtea';
            } else {
                $rights = $this->storagefolder->get_myrights();
                if ($rights && !PEAR::isError($rights)) {
                    $this->rights = $rights;
                    if (strpos($rights, 'i') !== false && strpos($rights, 't') !== false) {
                        $this->readonly = false;
                    }
                }
            }
        }

        $this->action = $rcube->action;
    }

    /**
     * Getter for the address book name to be displayed
     *
     * @return string Name of this address book
     */
    public function get_name()
    {
        return $this->storagefolder->get_name();
    }

    /**
     * Wrapper for kolab_storage_folder::get_foldername()
     */
    public function get_foldername()
    {
        return $this->storagefolder->get_foldername();
    }

    /**
     * Getter for the IMAP folder name
     *
     * @return string Name of the IMAP folder
     */
    public function get_realname()
    {
        return $this->imap_folder;
    }

    /**
     * Getter for the name of the namespace to which the IMAP folder belongs
     *
     * @return string Name of the namespace (personal, other, shared)
     */
    public function get_namespace()
    {
        if ($this->namespace === null && $this->ready) {
            $this->namespace = $this->storagefolder->get_namespace();
        }

        return $this->namespace;
    }

    /**
     * Getter for parent folder path
     *
     * @return string Full path to parent folder
     */
    public function get_parent()
    {
        return $this->storagefolder->get_parent();
    }

    /**
     * Check subscription status of this folder
     *
     * @return boolean True if subscribed, false if not
     */
    public function is_subscribed()
    {
        return kolab_storage::folder_is_subscribed($this->imap_folder);
    }

    /**
     * Compose an URL for CardDAV access to this address book (if configured)
     */
    public function get_carddav_url()
    {
        $rcmail = rcmail::get_instance();
        if ($template = $rcmail->config->get('kolab_addressbook_carddav_url', null)) {
            return strtr($template, [
                    '%h' => $_SERVER['HTTP_HOST'],
                    '%u' => urlencode($rcmail->get_user_name()),
                    '%i' => urlencode($this->storagefolder->get_uid()),
                    '%n' => urlencode($this->imap_folder),
            ]);
        }

        return false;
    }

    /**
     * Setter for the current group
     */
    public function set_group($gid)
    {
        $this->gid = $gid;
    }

    /**
     * Save a search string for future listings
     *
     * @param mixed $filter Search params to use in listing method, obtained by get_search_set()
     */
    public function set_search_set($filter): void
    {
        $this->filter = $filter;
    }

    /**
     * Getter for saved search properties
     *
     * @return mixed Search properties used by this class
     */
    public function get_search_set()
    {
        return $this->filter;
    }

    /**
     * Reset saved results and search parameters
     */
    public function reset(): void
    {
        $this->result = null;
        $this->filter = null;
    }

    /**
     * List all active contact groups of this source
     *
     * @param string $search Optional search string to match group name
     * @param int    $mode   Search mode. Sum of self::SEARCH_*
     *
     * @return array Indexed list of contact groups, each a hash array
     */
    public function list_groups($search = null, $mode = 0)
    {
        $this->_fetch_groups();
        $groups = [];

        foreach ((array)$this->distlists as $group) {
            if (!$search || strstr(mb_strtolower($group['name']), mb_strtolower($search))) {
                $groups[$group['ID']] = ['ID' => $group['ID'], 'name' => $group['name']];
            }
        }

        // sort groups by name
        uasort($groups, function ($a, $b) { return strcoll($a['name'], $b['name']); });

        return array_values($groups);
    }

    /**
     * List the current set of contact records
     *
     * @param array $cols    List of cols to show
     * @param int   $subset  Only return this number of records, use negative values for tail
     * @param bool  $nocount True to skip the count query (select only)
     *
     * @return rcube_result_set Indexed list of contact records, each a hash array
     */
    public function list_records($cols = null, $subset = 0, $nocount = false)
    {
        $this->result = new rcube_result_set(0, ($this->list_page - 1) * $this->page_size);

        $fetch_all = false;
        $fast_mode = !empty($cols) && is_array($cols);

        // list member of the selected group
        if ($this->gid) {
            $this->_fetch_groups();

            $this->sortindex = [];
            $this->contacts  = [];
            $local_sortindex = [];
            $uids            = [];

            // get members with email specified
            foreach ((array)$this->distlists[$this->gid]['member'] as $member) {
                // skip member that don't match the search filter
                if (!empty($this->filter['ids']) && array_search($member['ID'], $this->filter['ids']) === false) {
                    continue;
                }

                if (!empty($member['uid'])) {
                    $uids[] = $member['uid'];
                } elseif (!empty($member['email'])) {
                    $this->contacts[$member['ID']] = $member;
                    $local_sortindex[$member['ID']] = $this->_sort_string($member);
                    $fetch_all = true;
                }
            }

            // get members by UID
            if (!empty($uids)) {
                $this->_fetch_contacts($query = [['uid', '=', $uids]], $fetch_all ? false : count($uids), $fast_mode);
                $this->sortindex = array_merge($this->sortindex, $local_sortindex);
            }
        } elseif ($this->filter && is_array($this->filter['ids'])) {
            $ids = $this->filter['ids'];
            if (count($ids)) {
                $uids = array_map([$this, 'id2uid'], $this->filter['ids']);
                $this->_fetch_contacts($query = [['uid', '=', $uids]], count($ids), $fast_mode);
            }
        } else {
            $this->_fetch_contacts($query = 'contact', true, $fast_mode);
        }

        if ($fetch_all) {
            // sort results (index only)
            asort($this->sortindex, SORT_LOCALE_STRING);
            $ids = array_keys($this->sortindex);

            // fill contact data into the current result set
            $this->result->count = count($ids);
            $start_row = $subset < 0 ? $this->result->first + $this->page_size + $subset : $this->result->first;
            $last_row = min($subset != 0 ? $start_row + abs($subset) : $this->result->first + $this->page_size, $this->result->count);

            for ($i = $start_row; $i < $last_row; $i++) {
                if (array_key_exists($i, $ids)) {
                    $idx = $ids[$i];
                    $this->result->add($this->contacts[$idx] ?: $this->_to_rcube_contact($this->dataset[$idx]));
                }
            }
        } elseif (!empty($this->dataset)) {
            // get all records count, skip the query if possible
            if (!isset($query) || count($this->dataset) < $this->page_size) {
                $this->result->count = count($this->dataset) + $this->page_size * ($this->list_page - 1);
            } else {
                $this->result->count = $this->storagefolder->count($query);
            }

            $start_row = $subset < 0 ? $this->page_size + $subset : 0;
            $last_row  = min($subset != 0 ? $start_row + abs($subset) : $this->page_size, $this->result->count);

            for ($i = $start_row; $i < $last_row; $i++) {
                $this->result->add($this->_to_rcube_contact($this->dataset[$i]));
            }
        }

        return $this->result;
    }

    /**
     * Search records
     *
     * @param mixed $fields   The field name of array of field names to search in
     * @param mixed $value    Search value (or array of values when $fields is array)
     * @param int   $mode     Matching mode:
     *                        0 - partial (*abc*),
     *                        1 - strict (=),
     *                        2 - prefix (abc*)
     *                        4 - include groups (if supported)
     * @param bool  $select   True if results are requested, False if count only
     * @param bool  $nocount  True to skip the count query (select only)
     * @param array $required List of fields that cannot be empty
     *
     * @return rcube_result_set List of contact records and 'count' value
     */
    public function search($fields, $value, $mode = 0, $select = true, $nocount = false, $required = [])
    {
        // search by ID
        if ($fields == $this->primary_key) {
            $ids    = !is_array($value) ? explode(',', $value) : $value;
            $result = new rcube_result_set();

            foreach ($ids as $id) {
                if ($rec = $this->get_record($id, true)) {
                    $result->add($rec);
                    $result->count++;
                }
            }
            return $result;
        } elseif ($fields == '*') {
            $fields = $this->search_fields;
        }

        if (!is_array($fields)) {
            $fields = [$fields];
        }
        if (!is_array($required) && !empty($required)) {
            $required = [$required];
        }

        // advanced search
        $advanced = false;
        if (is_array($value)) {
            $advanced = true;
            $value = array_map('mb_strtolower', $value);
        } else {
            $value = mb_strtolower($value);
        }

        $scount = count($fields);
        // build key name regexp
        $regexp = '/^(' . implode('|', $fields) . ')(?:.*)$/';

        // pass query to storage if only indexed cols are involved
        // NOTE: this is only some rough pre-filtering but probably includes false positives
        $squery = $this->_search_query($fields, $value, $mode);

        // add magic selector to select contacts with birthday dates only
        if (in_array('birthday', $required)) {
            $squery[] = ['tags', '=', 'x-has-birthday'];
        }

        $squery[] = ['type', '=', 'contact'];

        // get all/matching records
        $this->_fetch_contacts($squery);

        // save searching conditions
        $this->filter = ['fields' => $fields, 'value' => $value, 'mode' => $mode, 'ids' => []];

        // search by iterating over all records in dataset
        foreach ($this->dataset as $record) {
            $contact = $this->_to_rcube_contact($record);
            $id = $contact['ID'];

            // check if current contact has required values, otherwise skip it
            if ($required) {
                foreach ($required as $f) {
                    // required field might be 'email', but contact might contain 'email:home'
                    $v = rcube_addressbook::get_col_values($f, $contact, true);
                    if (count($v) === 0) {
                        continue 2;
                    }
                }
            }

            $found = [];
            $contents = '';
            foreach (preg_grep($regexp, array_keys($contact)) as $col) {
                $pos     = strpos($col, ':');
                $colname = $pos ? substr($col, 0, $pos) : $col;

                foreach ((array)$contact[$col] as $val) {
                    if ($advanced) {
                        $found[$colname] = $this->compare_search_value($colname, $val, $value[array_search($colname, $fields)], $mode);
                    } else {
                        $contents .= ' ' . implode(' ', (array)$val);
                    }
                }
            }

            // compare matches
            if (($advanced && count($found) >= $scount) ||
                (!$advanced && rcube_utils::words_match(mb_strtolower($contents), $value))) {
                $this->filter['ids'][] = $id;
            }
        }

        // dummy result with contacts count
        if (!$select) {
            return new rcube_result_set(count($this->filter['ids']), ($this->list_page - 1) * $this->page_size);
        }

        // list records (now limited by $this->filter)
        return $this->list_records();
    }

    /**
     * Refresh saved search results after data has changed
     */
    public function refresh_search()
    {
        if ($this->filter) {
            $this->search($this->filter['fields'], $this->filter['value'], $this->filter['mode']);
        }

        return $this->get_search_set();
    }

    /**
     * Count number of available contacts in database
     *
     * @return rcube_result_set Result set with values for 'count' and 'first'
     */
    public function count()
    {
        if ($this->gid) {
            $this->_fetch_groups();
            $count = count($this->distlists[$this->gid]['member']);
        } elseif (isset($this->filter['ids']) && is_array($this->filter['ids'])) {
            $count = count($this->filter['ids']);
        } else {
            $count = $this->storagefolder->count('contact');
        }

        return new rcube_result_set($count, ($this->list_page - 1) * $this->page_size);
    }

    /**
     * Return the last result set
     *
     * @return ?rcube_result_set Current result set or NULL if nothing selected yet
     */
    public function get_result()
    {
        return $this->result;
    }

    /**
     * Get a specific contact record
     *
     * @param mixed $id    Record identifier(s)
     * @param bool  $assoc True to return record as associative array, otherwise a result set is returned
     *
     * @return false|rcube_result_set Result object with all record fields or False if not found
     */
    public function get_record($id, $assoc = false)
    {
        $rec = null;
        $uid = $this->id2uid($id);
        $rev = rcube_utils::get_input_value('_rev', rcube_utils::INPUT_GPC);

        if (strpos($uid, 'mailto:') === 0) {
            $this->_fetch_groups(true);
            $rec = $this->contacts[$id];
            $this->readonly = true;  // set source to read-only
        } elseif (!empty($rev)) {
            $rcmail = rcube::get_instance();
            /** @var ?kolab_addressbook $plugin */
            $plugin = $rcmail->plugins->get_plugin('kolab_addressbook');
            if ($plugin && ($object = $plugin->get_revision($id, kolab_storage::id_encode($this->imap_folder), $rev))) {
                $rec = $this->_to_rcube_contact($object);
                $rec['rev'] = $rev;
            }
            $this->readonly = true;  // set source to read-only
        } elseif ($object = $this->storagefolder->get_object($uid)) {
            $rec = $this->_to_rcube_contact($object);
        }

        if ($rec) {
            $this->result = new rcube_result_set(1);
            $this->result->add($rec);
            return $assoc ? $rec : $this->result;
        }

        return false;
    }

    /**
     * Get group assignments of a specific contact record
     *
     * @param mixed $id Record identifier
     *
     * @return array List of assigned groups as ID=>Name pairs
     */
    public function get_record_groups($id)
    {
        $out = [];
        $this->_fetch_groups();

        if (!empty($this->groupmembers[$id])) {
            foreach ((array) $this->groupmembers[$id] as $gid) {
                if (!empty($this->distlists[$gid])) {
                    $group = $this->distlists[$gid];
                    $out[$gid] = $group['name'];
                }
            }
        }

        return $out;
    }

    /**
     * Create a new contact record
     *
     * @param array $save_data Associative array with save data
     *  Keys:   Field name with optional section in the form FIELD:SECTION
     *  Values: Field value. Can be either a string or an array of strings for multiple values
     * @param bool  $check     True to check for duplicates first
     *
     * @return mixed The created record ID on success, False on error
     */
    public function insert($save_data, $check = false)
    {
        if (!is_array($save_data)) {
            return false;
        }

        $insert_id = $existing = false;

        // check for existing records by e-mail comparison
        if ($check) {
            foreach ($this->get_col_values('email', $save_data, true) as $email) {
                if (($res = $this->search('email', $email, true, false)) && $res->count) {
                    $existing = true;
                    break;
                }
            }
        }

        if (!$existing) {
            // remove existing id attributes (#1101)
            unset($save_data['ID'], $save_data['uid']);

            // generate new Kolab contact item
            $object = $this->_from_rcube_contact($save_data);
            $saved  = $this->storagefolder->save($object, 'contact');

            if (!$saved) {
                rcube::raise_error(
                    [
                  'code' => 600, 'type' => 'php',
                  'file' => __FILE__, 'line' => __LINE__,
                  'message' => "Error saving contact object to Kolab server"],
                    true,
                    false
                );
            } else {
                $insert_id = $this->uid2id($object['uid']);
            }
        }

        return $insert_id;
    }

    /**
     * Update a specific contact record
     *
     * @param mixed $id        Record identifier
     * @param array $save_data Associative array with save data
     *  Keys:   Field name with optional section in the form FIELD:SECTION
     *  Values: Field value. Can be either a string or an array of strings for multiple values
     *
     * @return bool True on success, False on error
     */
    public function update($id, $save_data)
    {
        $updated = false;
        if ($old = $this->storagefolder->get_object($this->id2uid($id))) {
            $object = $this->_from_rcube_contact($save_data, $old);

            if (!$this->storagefolder->save($object, 'contact', $old['uid'])) {
                rcube::raise_error(
                    [
                        'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
                        'message' => "Error saving contact object to Kolab server",
                    ],
                    true,
                    false
                );
            } else {
                $updated = true;

                // TODO: update data in groups this contact is member of
            }
        }

        return $updated;
    }

    /**
     * Mark one or more contact records as deleted
     *
     * @param array $ids   Record identifiers
     * @param bool  $force Remove record(s) irreversible (mark as deleted otherwise)
     *
     * @return int Number of records deleted
     */
    public function delete($ids, $force = true)
    {
        $this->_fetch_groups();

        if (!is_array($ids)) {
            $ids = explode(',', $ids);
        }

        $count = 0;
        foreach ($ids as $id) {
            if ($uid = $this->id2uid($id)) {
                $is_mailto = strpos($uid, 'mailto:') === 0;
                $deleted = $is_mailto || $this->storagefolder->delete($uid, $force);

                if (!$deleted) {
                    rcube::raise_error(
                        [
                            'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
                            'message' => "Error deleting a contact object $uid from the Kolab server",
                        ],
                        true,
                        false
                    );
                } else {
                    // remove from distribution lists
                    if (!empty($this->groupmembers[$id])) {
                        foreach ((array) $this->groupmembers[$id] as $gid) {
                            if (!$is_mailto || $gid == $this->gid) {
                                $this->remove_from_group($gid, $id);
                            }
                        }

                        // clear internal cache
                        unset($this->groupmembers[$id]);
                    }

                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Undelete one or more contact records.
     * Only possible just after delete (see 2nd argument of delete() method).
     *
     * @param array $ids Record identifiers
     *
     * @return int Number of records restored
     */
    public function undelete($ids)
    {
        if (!is_array($ids)) {
            $ids = explode(',', $ids);
        }

        $count = 0;
        foreach ($ids as $id) {
            $uid = $this->id2uid($id);
            if ($this->storagefolder->undelete($uid)) {
                $count++;
            } else {
                rcube::raise_error(
                    [
                        'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
                        'message' => "Error undeleting a contact object $uid from the Kolab server",
                    ],
                    true,
                    false
                );
            }
        }

        return $count;
    }

    /**
     * Remove all records from the database
     *
     * @param bool $with_groups Remove also groups
     */
    public function delete_all($with_groups = false)
    {
        if ($this->storagefolder->delete_all()) {
            $this->contacts  = [];
            $this->sortindex = [];
            $this->dataset   = null;
            $this->result    = null;
        }
    }

    /**
     * Close connection to source
     * Called on script shutdown
     */
    public function close()
    {
    }

    /**
     * Create a contact group with the given name
     *
     * @param string $name The group name
     *
     * @return array|false False on error, array with record props in success
     */
    public function create_group($name)
    {
        $this->_fetch_groups();
        $result = false;

        $list = [
            'name' => $name,
            'member' => [],
        ];
        $saved = $this->storagefolder->save($list, 'distribution-list');

        if (!$saved) {
            rcube::raise_error(
                [
                    'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Error saving distribution-list object to Kolab server",
                ],
                true,
                false
            );
            return false;
        } else {
            // @phpstan-ignore-next-line FIXME: this one might be valid error
            $id = $this->uid2id($list['uid']);
            $this->distlists[$id] = $list;
            $result = ['id' => $id, 'name' => $name];
        }

        return $result;
    }

    /**
     * Delete the given group and all linked group members
     *
     * @param string $gid Group identifier
     *
     * @return bool True on success, false if no data was changed
     */
    public function delete_group($gid)
    {
        $this->_fetch_groups();
        $result = false;

        if ($list = $this->distlists[$gid]) {
            $deleted = $this->storagefolder->delete($list['uid']);
        }

        if (empty($deleted)) {
            rcube::raise_error(
                [
                    'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Error deleting distribution-list object from the Kolab server",
                ],
                true,
                false
            );
        } else {
            $result = true;
        }

        return $result;
    }

    /**
     * Rename a specific contact group
     *
     * @param string $gid     Group identifier
     * @param string $newname New name to set for this group
     * @param string $newid   New group identifier (if changed, otherwise don't set)
     *
     * @return string|bool New name on success, false if no data was changed
     */
    public function rename_group($gid, $newname, &$newid)
    {
        $this->_fetch_groups();
        $list = $this->distlists[$gid];

        if ($newname != $list['name']) {
            $list['name'] = $newname;
            $saved = $this->storagefolder->save($list, 'distribution-list', $list['uid']);
        }

        if (empty($saved)) {
            rcube::raise_error(
                [
                    'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Error saving distribution-list object to Kolab server",
                ],
                true,
                false
            );
            return false;
        }

        return $newname;
    }

    /**
     * Add the given contact records the a certain group
     *
     * @param string $gid Group identifier
     * @param array  $ids List of contact identifiers to be added
     *
     * @return int Number of contacts added
     */
    public function add_to_group($gid, $ids)
    {
        if (!is_array($ids)) {
            $ids = explode(',', $ids);
        }

        $this->_fetch_groups(true);

        $list   = $this->distlists[$gid];
        $added  = 0;
        $uids   = [];
        $exists = [];

        foreach ((array)$list['member'] as $member) {
            $exists[] = $member['ID'];
        }

        // substract existing assignments from list
        $ids = array_unique(array_diff($ids, $exists));

        // add mailto: members
        foreach ($ids as $contact_id) {
            $uid = $this->id2uid($contact_id);
            if (strpos($uid, 'mailto:') === 0 && ($contact = $this->contacts[$contact_id])) {
                $list['member'][] = [
                    'email' => $contact['email'],
                    'name'  => $contact['name'],
                ];
                $this->groupmembers[$contact_id][] = $gid;
                $added++;
            } else {
                $uids[$uid] = $contact_id;
            }
        }

        // add members with UID
        if (!empty($uids)) {
            foreach ($uids as $uid => $contact_id) {
                $list['member'][] = ['uid' => $uid];
                $this->groupmembers[$contact_id][] = $gid;
                $added++;
            }
        }

        if ($added) {
            $saved = $this->storagefolder->save($list, 'distribution-list', $list['uid']);
        } else {
            $saved = true;
        }

        if (!$saved) {
            rcube::raise_error(
                [
                    'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Error saving distribution-list to Kolab server",
                ],
                true,
                false
            );

            $added = 0;
            $this->set_error(self::ERROR_SAVING, 'errorsaving');
        } else {
            $this->distlists[$gid] = $list;
        }

        return $added;
    }

    /**
     * Remove the given contact records from a certain group
     *
     * @param string $gid Group identifier
     * @param array  $ids List of contact identifiers to be removed
     *
     * @return int Number of deleted group members
     */
    public function remove_from_group($gid, $ids)
    {
        if (!is_array($ids)) {
            $ids = explode(',', $ids);
        }

        $this->_fetch_groups();
        if (!($list = $this->distlists[$gid])) {
            return 0;
        }

        $new_member = [];
        $removed = 0;

        foreach ((array)$list['member'] as $member) {
            if (!in_array($member['ID'], $ids)) {
                $new_member[] = $member;
            } else {
                $removed++;
            }
        }

        // write distribution list back to server
        $list['member'] = $new_member;
        $saved = $this->storagefolder->save($list, 'distribution-list', $list['uid']);

        if (!$saved) {
            rcube::raise_error(
                [
                    'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Error saving distribution-list object to Kolab server",
                ],
                true,
                false
            );

            return 0;
        }

        // remove group assigments in local cache
        foreach ($ids as $id) {
            $j = array_search($gid, $this->groupmembers[$id]);
            unset($this->groupmembers[$id][$j]);
        }

        $this->distlists[$gid] = $list;

        return $removed;
    }

    /**
     * Check the given data before saving.
     * If input not valid, the message to display can be fetched using get_error()
     *
     * @param array $save_data Associative array with contact data to save
     * @param bool  $autofix   Attempt to fix/complete data automatically
     *
     * @return bool True if input is valid, False if not.
     */
    public function validate(&$save_data, $autofix = false)
    {
        // validate e-mail addresses
        $valid = parent::validate($save_data);

        // require at least one e-mail address if there's no name
        // (syntax check is already done)
        if ($valid) {
            if (!strlen($save_data['name'])
                && !strlen($save_data['organization'])
                && !array_filter($this->get_col_values('email', $save_data, true))
            ) {
                $this->set_error('warning', 'kolab_addressbook.noemailnamewarning');
                $valid = false;
            }
        }

        return $valid;
    }

    /**
     * Query storage layer and store records in private member var
     */
    private function _fetch_contacts($query = [], $limit = false, $fast_mode = false)
    {
        if (!isset($this->dataset) || !empty($query)) {
            if ($limit) {
                $size = is_int($limit) && $limit < $this->page_size ? $limit : $this->page_size;
                $this->storagefolder->set_order_and_limit($this->_sort_columns(), $size, ($this->list_page - 1) * $this->page_size);
            }

            $this->sortindex = [];
            $this->dataset   = $this->storagefolder->select($query, $fast_mode);

            foreach ($this->dataset as $idx => $record) {
                $contact = $this->_to_rcube_contact($record);
                $this->sortindex[$idx] = $this->_sort_string($contact);
            }
        }
    }

    /**
     * Extract a string for sorting from the given contact record
     */
    private function _sort_string($rec)
    {
        $str = '';

        switch ($this->sort_col) {
            case 'name':
                $str = ($rec['name'] ?? '') . ($rec['prefix'] ?? '');
                // no break
            case 'firstname':
                $str .= ($rec['firstname'] ?? '') . ($rec['middlename'] ?? '') . ($rec['surname'] ?? '');
                break;

            case 'surname':
                $str = ($rec['surname'] ?? '') . ($rec['firstname'] ?? '') . ($rec['middlename'] ?? '');
                break;

            default:
                $str = $rec[$this->sort_col];
                break;
        }

        if (!empty($rec['email'])) {
            $str .= is_array($rec['email']) ? $rec['email'][0] : $rec['email'];
        }

        return mb_strtolower($str);
    }

    /**
     * Return the cache table columns to order by
     */
    private function _sort_columns()
    {
        $sortcols = [];

        switch ($this->sort_col) {
            case 'name':
                $sortcols[] = 'name';
                // no break
            case 'firstname':
                $sortcols[] = 'firstname';
                break;

            case 'surname':
                $sortcols[] = 'surname';
                break;
        }

        $sortcols[] = 'email';
        return $sortcols;
    }

    /**
     * Read distribution-lists AKA groups from server
     */
    private function _fetch_groups($with_contacts = false)
    {
        if (!isset($this->distlists)) {
            $this->distlists = $this->groupmembers = [];
            foreach ($this->storagefolder->select('distribution-list', true) as $record) {
                $record['ID'] = $this->uid2id($record['uid']);
                foreach ((array)$record['member'] as $i => $member) {
                    $mid = $this->uid2id($member['uid'] ? $member['uid'] : 'mailto:' . $member['email']);
                    $record['member'][$i]['ID'] = $mid;
                    $record['member'][$i]['readonly'] = empty($member['uid']);
                    $this->groupmembers[$mid][] = $record['ID'];

                    if ($with_contacts && empty($member['uid'])) {
                        $this->contacts[$mid] = $record['member'][$i];
                    }
                }
                $this->distlists[$record['ID']] = $record;
            }
        }
    }

    /**
     * Encode object UID into a safe identifier
     */
    public function uid2id($uid)
    {
        return rtrim(strtr(base64_encode($uid), '+/', '-_'), '=');
    }

    /**
     * Convert Roundcube object identifier back into the original UID
     */
    public function id2uid($id)
    {
        return base64_decode(str_pad(strtr($id, '-_', '+/'), strlen($id) % 4, '=', STR_PAD_RIGHT));
    }

    /**
     * Build SQL query for fulltext matches
     */
    private function _search_query($fields, $value, $mode)
    {
        $query = [];
        $cols  = [];

        // $fulltext_cols might contain composite field names e.g. 'email:address' while $fields not
        foreach (kolab_format_contact::$fulltext_cols as $col) {
            if ($pos = strpos($col, ':')) {
                $col = substr($col, 0, $pos);
            }
            if (in_array($col, $fields)) {
                $cols[] = $col;
            }
        }

        if (count($cols) == count($fields)) {
            if ($mode & rcube_addressbook::SEARCH_STRICT) {
                $prefix = '^';
                $suffix = '$';
            } elseif ($mode & rcube_addressbook::SEARCH_PREFIX) {
                $prefix = '^';
                $suffix = '';
            } else {
                $prefix = '';
                $suffix = '';
            }

            $search_string = is_array($value) ? implode(' ', $value) : $value;
            foreach (rcube_utils::normalize_string($search_string, true) as $word) {
                $query[] = ['words', 'LIKE', $prefix . $word . $suffix];
            }
        }

        return $query;
    }

    /**
     * Map fields from internal Kolab_Format to Roundcube contact format
     */
    private function _to_rcube_contact($record)
    {
        $record['ID'] = $this->uid2id($record['uid']);

        // convert email, website, phone values
        foreach (['email' => 'address', 'website' => 'url', 'phone' => 'number'] as $col => $propname) {
            if (isset($record[$col]) && is_array($record[$col])) {
                $values = $record[$col];
                unset($record[$col]);
                foreach ((array)$values as $i => $val) {
                    $key = $col . ($val['type'] ? ':' . $val['type'] : '');
                    $record[$key][] = $val[$propname];
                }
            }
        }

        if (isset($record['address']) && is_array($record['address'])) {
            $addresses = $record['address'];
            unset($record['address']);
            foreach ($addresses as $i => $adr) {
                $key = 'address' . ($adr['type'] ? ':' . $adr['type'] : '');
                $record[$key][] = [
                    'street'   => $adr['street'],
                    'locality' => $adr['locality'],
                    'zipcode'  => $adr['code'],
                    'region'   => $adr['region'],
                    'country'  => $adr['country'],
                ];
            }
        }

        // photo is stored as separate attachment
        if (!empty($record['photo']) && strlen($record['photo']) < 255 && !empty($record['_attachments'][$record['photo']])) {
            $att = $record['_attachments'][$record['photo']];
            // only fetch photo content if requested
            if ($this->action == 'photo') {
                if (!empty($att['content'])) {
                    $record['photo'] = $att['content'];
                } else {
                    $record['photo'] = $this->storagefolder->get_attachment($record['uid'], $att['id']);
                }
            }
        }

        // truncate publickey value for display
        if (!empty($record['pgppublickey']) && $this->action == 'show') {
            $record['pgppublickey'] = substr($record['pgppublickey'], 0, 140) . '...';
        }

        // remove empty fields
        $record = array_filter($record);

        // remove kolab_storage internal data
        unset($record['_msguid'], $record['_formatobj'], $record['_mailbox'], $record['_type'], $record['_size']);

        return $record;
    }

    /**
     * Map fields from Roundcube format to internal kolab_format_contact properties
     */
    private function _from_rcube_contact($contact, $old = [])
    {
        if (empty($contact['uid']) && !empty($contact['ID'])) {
            $contact['uid'] = $this->id2uid($contact['ID']);
        } elseif (empty($contact['uid']) && !empty($old['uid'])) {
            $contact['uid'] = $old['uid'];
        }

        $contact['im'] = array_filter($this->get_col_values('im', $contact, true));

        // convert email, website, phone values
        foreach (['email' => 'address', 'website' => 'url', 'phone' => 'number'] as $col => $propname) {
            $col_values = $this->get_col_values($col, $contact);
            $contact[$col] = [];
            foreach ($col_values as $type => $values) {
                foreach ((array)$values as $val) {
                    if (!empty($val)) {
                        $contact[$col][] = [$propname => $val, 'type' => $type];
                    }
                }
                unset($contact[$col . ':' . $type]);
            }
        }

        $addresses = [];
        foreach ($this->get_col_values('address', $contact) as $type => $values) {
            foreach ((array)$values as $adr) {
                // skip empty address
                $adr = array_filter($adr);
                if (empty($adr)) {
                    continue;
                }

                $addresses[] = [
                    'type' => $type,
                    'street' => $adr['street'],
                    'locality' => $adr['locality'],
                    'code' => $adr['zipcode'],
                    'region' => $adr['region'],
                    'country' => $adr['country'],
                ];
            }

            unset($contact['address:' . $type]);
        }

        $contact['address'] = $addresses;

        // categories are not supported in the web client but should be preserved (#2608)
        $contact['categories'] = $old['categories'] ?? null;

        // copy meta data (starting with _) from old object
        foreach ((array)$old as $key => $val) {
            if (!isset($contact[$key]) && $key[0] == '_') {
                $contact[$key] = $val;
            }
        }

        // convert one-item-array elements into string element
        // this is needed e.g. to properly import birthday field
        foreach ($this->coltypes as $type => $col_def) {
            if (($col_def['limit'] ?? 0) == 1 && !empty($contact[$type]) && is_array($contact[$type])) {
                $contact[$type] = array_shift(array_filter($contact[$type]));
            }
        }

        // When importing contacts 'vcard' data is added, we don't need it (Bug #1711)
        unset($contact['vcard']);

        // add empty values for some fields which can be removed in the UI
        return array_filter($contact) + [
                'nickname' => '',
                'birthday' => '',
                'anniversary' => '',
                'freebusyurl' => '',
                'photo' => $contact['photo'] ?? null,
            ];
    }
}
