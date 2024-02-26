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
            $name = $book->get_name();
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
            'carddav'    => true,
            'audittrail' => false, // !empty($this->plugin->bonnie_api),
        ];
    }
}
