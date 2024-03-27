<?php

/**
 * Backend class for a custom address book using CardDAV service.
 *
 * @author Aleksander Machniak <machniak@apheleia-it.chm>
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

class carddav_contacts_driver
{
    public $search_more_results = false;

    protected $plugin;
    protected $rc;
    protected $sources;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
        $this->rc     = rcube::get_instance();
    }

    /**
     * List addressbook sources (folders)
     */
    public function list_folders()
    {
        if (isset($this->sources)) {
            return $this->sources;
        }

        $storage = self::get_storage();
        $this->sources = [];

        // get all folders that have "contact" type
        foreach ($storage->get_folders('contact') as $folder) {
            $this->sources[$folder->id] = new carddav_contacts($folder);
        }

        return $this->sources;
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
        $storage = self::get_storage();
        $result = [];

        // find addressbook folders, except other user's folders
        if ($source == 'folders') {
            foreach ((array) $storage->search_folders('event', $query, ['other']) as $folder) {
                $abook = new carddav_contacts($folder);
                $result[] = $this->abook_prop($folder->id, $abook);
            }
        }
        // find other user's addressbooks (invitations)
        elseif ($source == 'users') {
            // we have slightly more space, so display twice the number
            $limit = $this->rc->config->get('autocomplete_max', 15) * 2;

            foreach ($storage->get_share_invitations('contact', $query) as $invitation) {
                $abook = new carddav_contacts($invitation);
                $result[] = $this->abook_prop($invitation->id, $abook);

                if (count($result) > $limit) {
                    $this->search_more_results = true;
                }
            }
        }

        return $result;
    }

    /**
     * Getter for the rcube_addressbook instance
     *
     * @param string $id Addressbook (folder) ID
     *
     * @return ?carddav_contacts
     */
    public function get_address_book($id)
    {
        if (isset($this->sources[$id])) {
            return $this->sources[$id];
        }

        $storage = self::get_storage();
        $folder = $storage->get_folder($id, 'contact');

        if ($folder) {
            return new carddav_contacts($folder);
        }

        return null;
    }

    /**
     * Initialize kolab_storage_dav instance
     */
    protected static function get_storage()
    {
        $rcube = rcube::get_instance();
        $url   = $rcube->config->get('kolab_addressbook_carddav_server', 'http://localhost');

        return new kolab_storage_dav($url);
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
        $storage = self::get_storage();

        $this->sources = null;

        return $storage->folder_delete($folder, 'contact');
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
        $name = '';

        if ($source && ($book = $this->get_address_book($source))) {
            $name = $book->get_foldername();
            $folder = $book->storage;
        }

        $foldername = new html_inputfield(['name' => '_name', 'id' => '_name', 'size' => 30]);
        $foldername = $foldername->show($name);

        // General tab
        $form = [
            'properties' => [
                'name'   => $this->rc->gettext('properties'),
                'fields' => [
                    'name' => [
                        'label' => $this->plugin->gettext('bookname'),
                        'value' => $foldername,
                        'id'    => '_name',
                    ],
                ],
            ],
        ];

        $hidden_fields = [['name' => '_source', 'value' => $source]];

        return kolab_utils::folder_form($form, $folder ?? null, 'contacts', $hidden_fields);
    }

    /**
     * Handler for address book create/edit form submit
     */
    public function folder_save()
    {
        $storage = self::get_storage();

        $prop  = [
            'id'   => trim(rcube_utils::get_input_value('_source', rcube_utils::INPUT_POST)),
            'name' => trim(rcube_utils::get_input_value('_name', rcube_utils::INPUT_POST)),
            'type' => 'contact',
            'subscribed' => true,
        ];

        $type = !empty($prop['id']) ? 'update' : 'create';

        $this->sources = null;

        $result = $storage->folder_update($prop);

        if ($result && ($abook = $this->get_address_book($prop['id'] ?: $result))) {
            $abook_id = $prop['id'] ?: $result;
            $props = $this->abook_prop($abook_id, $abook);

            $this->rc->output->show_message('kolab_addressbook.book' . $type . 'd', 'confirmation');
            $this->rc->output->command('book_update', $props, $prop['id']);
        } else {
            $this->rc->output->show_message('kolab_addressbook.book' . $type . 'error', 'error');
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
        /*
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
        */

        return true;
    }

    /**
     * Accept an invitation to a shared folder
     *
     * @param string $href Invitation location href
     *
     * @return array|false
     */
    public function accept_share_invitation($href)
    {
        $storage = self::get_storage();

        $folder = $storage->accept_share_invitation('contact', $href);

        if ($folder === false) {
            return false;
        }

        $abook = new carddav_contacts($folder);

        return $this->abook_prop($folder->id, $abook);
    }

    /**
     * Helper method to build a hash array of address book properties
     */
    public function abook_prop($id, $abook)
    {
        /*
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
                        'carddav'  => true,
                    ];
                }
        */
        return [
            'id'         => $id,
            'name'       => $abook->get_name(),
            'listname'   => $abook->get_name(),
            'readonly'   => $abook->readonly,
            'rights'     => $abook->rights,
            'groups'     => $abook->groups,
            'undelete'   => $abook->undelete && $this->rc->config->get('undo_timeout'),
            'group'      => $abook->get_namespace(),
            'subscribed' => $abook->is_subscribed(),
            'carddavurl' => $abook->get_carddav_url(),
            'removable'  => true,
            'kolab'      => true,
            'carddav'    => true,
            'audittrail' => false, // !empty($this->plugin->bonnie_api),
            'share_invitation' => $abook->share_invitation,
        ];
    }
}
