<?php

/**
 * Backend class for a custom address book
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @author Aleksander Machniak <machniak@apheleia-it.ch>
 *
 * Copyright (C) 2011-2022, Apheleia IT AG <contact@apheleia-it.ch>
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
class kolab_contacts_driver
{
    public $search_more_results = false;

    protected $plugin;
    protected $rc;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
        $this->rc     = rcube::get_instance();
    }

    /**
     * List addressbook sources (folders)
     */
    public static function list_folders()
    {
        kolab_storage::$encode_ids = true;

        // get all folders that have "contact" type
        $folders = kolab_storage::sort_folders(kolab_storage::get_folders('contact'));

        // we need at least one folder to prevent from errors in Roundcube core
        // when there's also no sql nor ldap addressbook (Bug #2086)
        if (empty($folders)) {
            if ($folder = kolab_storage::create_default_folder('contact')) {
                $folders = [new kolab_storage_folder($folder, 'contact')];
            }
        }

        $sources = [];
        foreach ($folders as $folder) {
            $sources[$folder->id] = new kolab_contacts($folder->name);
        }

        return $sources;
    }

    /**
     * Search for shared or otherwise not listed addressbooks the user has access to
     *
     * @param string $query  Search string
     * @param string $source Section/source to search
     *
     * @return array List of addressbooks
     */
    public function search_folders($query, $source)
    {
        $this->search_more_results = false;
        $results = [];
        $sources = [];
        $folders = [];

        // find unsubscribed IMAP folders that have "event" type
        if ($source == 'folders') {
            foreach ((array) kolab_storage::search_folders('contact', $query, ['other']) as $folder) {
                $folders[$folder->id] = $folder;
                $sources[$folder->id] = new kolab_contacts($folder->name);
            }
        }
        // search other user's namespace via LDAP
        elseif ($source == 'users') {
            // We have slightly more space, so display twice the number
            $limit = $this->rc->config->get('autocomplete_max', 15) * 2;

            foreach (kolab_storage::search_users($query, 0, [], $limit * 10) as $user) {
                $folders = [];
                // search for contact folders shared by this user
                foreach (kolab_storage::list_user_folders($user, 'contact', false) as $foldername) {
                    $folders[] = new kolab_storage_folder($foldername, 'contact');
                }

                $count = 0;
                if (count($folders)) {
                    $userfolder = new kolab_storage_folder_user($user['kolabtargetfolder'], '', $user);
                    $folders[$userfolder->id] = $userfolder;
                    $sources[$userfolder->id] = $userfolder;

                    foreach ($folders as $folder) {
                        $folders[$folder->id] = $folder;
                        $sources[$folder->id] = new kolab_contacts($folder->name);
                        $count++;
                    }
                }

                if ($count >= $limit) {
                    $this->search_more_results = true;
                    break;
                }
            }
        }

        $delim = $this->rc->get_storage()->get_hierarchy_delimiter();

        // build results list
        foreach ($sources as $id => $source) {
            $folder = $folders[$id];
            $imap_path = explode($delim, $folder->name);

            // find parent
            do {
                array_pop($imap_path);
                $parent_id = kolab_storage::folder_id(implode($delim, $imap_path));
            } while (count($imap_path) > 1 && empty($folders[$parent_id]));

            // restore "real" parent ID
            if ($parent_id && empty($folders[$parent_id])) {
                $parent_id = kolab_storage::folder_id($folder->get_parent());
            }

            $prop = $this->abook_prop($id, $source);
            $prop['parent'] = $parent_id;

            $results[] = $prop;
        }

        return $results;
    }

    /**
     * Getter for the rcube_addressbook instance
     *
     * @param string $id Addressbook (folder) ID
     *
     * @return ?kolab_contacts
     */
    public static function get_address_book($id)
    {
        $folderId = kolab_storage::id_decode($id);
        $folder   = kolab_storage::get_folder($folderId);

        // try with unencoded (old-style) identifier
        if ((!$folder || $folder->type != 'contact') && $folderId != $id) {
            $folder = kolab_storage::get_folder($id);
        }

        if ($folder && $folder->type == 'contact') {
            return new kolab_contacts($folder->name);
        }

        return null;
    }

    /**
     * Delete address book folder
     *
     * @param string $folder Addressbook identifier
     *
     * @return bool
     */
    public function folder_delete($folder)
    {
        $folderId = kolab_storage::id_decode($folder);
        $folder   = kolab_storage::get_folder($folderId);

        if ($folder && kolab_storage::folder_delete($folder->name)) {
            return true;
        }

        return false;
    }

    /**
     * Address book folder form content for book create/edit
     *
     * @param string $action Action name (edit, create)
     * @param string $source Addressbook identifier
     *
     * @return string HTML output
     */
    public function folder_form($action, $source)
    {
        $hidden_fields[] = ['name' => '_source', 'value' => $source];

        $rcube   = rcube::get_instance();
        $storage = $rcube->get_storage();
        $delim   = $storage->get_hierarchy_delimiter();
        $folder  = '';

        if ($source && ($book = $this->get_address_book($source))) {
            $folder = $book->get_realname(); // UTF7-IMAP
        }

        if ($action == 'edit') {
            $path_imap = explode($delim, $folder);
            $name      = rcube_charset::convert(array_pop($path_imap), 'UTF7-IMAP');
            $path_imap = implode($delim, $path_imap);
        } else { // create
            $path_imap = $folder;
            $name      = '';
            $folder    = '';
        }

        // Store old name, get folder options
        if (strlen($folder)) {
            $hidden_fields[] = ['name' => '_oldname', 'value' => $folder];

            $options = $storage->folder_info($folder);
        }

        $form = [];

        // General tab
        $form['properties'] = [
            'name'   => $rcube->gettext('properties'),
            'fields' => [],
        ];

        if (!empty($options) && ($options['norename'] || $options['protected'])) {
            $foldername = rcube::Q(str_replace($delim, ' &raquo; ', kolab_storage::object_name($folder)));
        } else {
            $foldername = new html_inputfield(['name' => '_name', 'id' => '_name', 'size' => 30]);
            $foldername = $foldername->show($name);
        }

        $form['properties']['fields']['name'] = [
            'label' => $rcube->gettext('bookname', 'kolab_addressbook'),
            'value' => $foldername,
            'id'    => '_name',
        ];

        if (!empty($options) && ($options['norename'] || $options['protected'])) {
            // prevent user from moving folder
            $hidden_fields[] = ['name' => '_parent', 'value' => $path_imap];
        } else {
            $prop   = ['name' => '_parent', 'id' => '_parent'];
            $select = kolab_storage::folder_selector('contact', $prop, $folder);

            $form['properties']['fields']['parent'] = [
                'label' => $rcube->gettext('parentbook', 'kolab_addressbook'),
                'value' => $select->show(strlen($folder) ? $path_imap : ''),
                'id'    => '_parent',
            ];
        }

        return kolab_utils::folder_form($form, $folder, 'calendar', $hidden_fields);
    }

    /**
     * Handler for address book create/edit form submit
     */
    public function folder_save()
    {
        $prop  = [
            'name'    => trim(rcube_utils::get_input_value('_name', rcube_utils::INPUT_POST)),
            'oldname' => trim(rcube_utils::get_input_value('_oldname', rcube_utils::INPUT_POST, true)), // UTF7-IMAP
            'parent'  => trim(rcube_utils::get_input_value('_parent', rcube_utils::INPUT_POST, true)), // UTF7-IMAP
            'type'    => 'contact',
            'subscribed' => true,
        ];

        $result = $error = false;
        $folder = null;
        $type = strlen($prop['oldname']) ? 'update' : 'create';
        $prop = $this->rc->plugins->exec_hook('addressbook_' . $type, $prop);

        if (!$prop['abort']) {
            if ($newfolder = kolab_storage::folder_update($prop)) {
                $folder = $newfolder;
                $result = true;
            } else {
                $error = kolab_storage::$last_error;
            }
        } else {
            $result = $prop['result'];
            $folder = $prop['name'];
        }

        if ($result && $folder) {
            $kolab_folder = kolab_storage::get_folder($folder);

            // get folder/addressbook properties
            $abook = new kolab_contacts($folder);
            $props = $this->abook_prop(kolab_storage::folder_id($folder, true), $abook);
            $props['parent'] = kolab_storage::folder_id($kolab_folder->get_parent(), true);

            $this->rc->output->show_message('kolab_addressbook.book' . $type . 'd', 'confirmation');
            $this->rc->output->command('book_update', $props, kolab_storage::folder_id($prop['oldname'], true));
        } else {
            if (!$error) {
                $error = !empty($prop['message']) ? $prop['message'] : 'kolab_addressbook.book' . $type . 'error';
            }

            $this->rc->output->show_message($error, 'error');
        }
    }

    /**
     * Subscribe to a folder
     *
     * @param string $id      Folder identifier
     * @param array  $options Action options
     *
     * @return bool
     */
    public function folder_subscribe($id, $options = [])
    {
        $success = false;

        if ($id && ($folder = kolab_storage::get_folder(kolab_storage::id_decode($id)))) {
            if (isset($options['permanent'])) {
                $success |= $folder->subscribe(intval($options['permanent']));
            }

            if (isset($options['active'])) {
                $success |= $folder->activate(intval($options['active']));
            }
        }

        return $success;
    }

    /**
     * Helper method to build a hash array of address book properties
     */
    public function abook_prop($id, $abook)
    {
        if ($abook instanceof kolab_storage_folder_virtual) {
            return [
                'id'       => $id,
                'name'     => $abook->get_name(),
                'listname' => $abook->get_foldername(),
                'group'    => $abook instanceof kolab_storage_folder_user ? 'user' : $abook->get_namespace(),
                'readonly' => true,
                'rights'   => 'l',
                'kolab'    => true,
                'virtual'  => true,
            ];
        }

        return [
            'id'         => $id,
            'name'       => $abook->get_name(),
            'listname'   => $abook->get_foldername(),
            'readonly'   => $abook->readonly,
            'rights'     => $abook->rights,
            'groups'     => $abook->groups,
            'undelete'   => $abook->undelete && $this->rc->config->get('undo_timeout'),
            'realname'   => rcube_charset::convert($abook->get_realname(), 'UTF7-IMAP'), // IMAP folder name
            'group'      => $abook->get_namespace(),
            'subscribed' => $abook->is_subscribed(),
            'carddavurl' => $abook->get_carddav_url(),
            'removable'  => true,
            'kolab'      => true,
            'audittrail' => !empty($this->plugin->bonnie_api),
        ];
    }
}
