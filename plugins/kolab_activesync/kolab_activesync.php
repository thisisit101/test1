<?php

/**
 * ActiveSync configuration utility for Kolab accounts
 *
 * @version @package_version@
 * @author Aleksander Machniak <machniak@kolabsys.com>
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2011-2013, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_activesync extends rcube_plugin
{
    public $task = 'settings';
    public $urlbase;
    public $backend;

    private $rc;
    private $ui;
    private $folder_meta;
    private $root_meta;

    public const ROOT_MAILBOX = 'INBOX';
    public const ASYNC_KEY    = '/private/vendor/kolab/activesync';


    /**
     * Plugin initialization.
     */
    public function init()
    {
        $this->rc = rcmail::get_instance();

        $this->require_plugin('libkolab');

        $this->register_action('plugin.activesync', [$this, 'config_view']);
        $this->register_action('plugin.activesync-config', [$this, 'config_frame']);
        $this->register_action('plugin.activesync-json', [$this, 'json_command']);

        $this->add_hook('settings_actions', [$this, 'settings_actions']);
        $this->add_hook('folder_form', [$this, 'folder_form']);

        $this->add_texts('localization/');

        if (preg_match('/^(plugin.activesync|edit-folder|save-folder)/', $this->rc->action)) {
            $this->add_label('devicedeleteconfirm', 'savingdata');
            $this->include_script('kolab_activesync.js');
        }
    }

    /**
     * Adds Activesync section in Settings
     */
    public function settings_actions($args)
    {
        $args['actions'][] = [
            'action' => 'plugin.activesync',
            'class'  => 'activesync',
            'label'  => 'tabtitle',
            'domain' => 'kolab_activesync',
            'title'  => 'activesynctitle',
        ];

        return $args;
    }

    /**
     * Handler for folder info/edit form (folder_form hook).
     * Adds ActiveSync section.
     */
    public function folder_form($args)
    {
        $mbox_imap = $args['options']['name'] ?? '';

        // Edited folder name (empty in create-folder mode)
        if (!strlen($mbox_imap)) {
            return $args;
        }

        $devices = $this->list_devices();

        // no registered devices
        if (empty($devices)) {
            return $args;
        }

        [$type, ] = explode('.', (string) kolab_storage::folder_type($mbox_imap));
        if ($type && !in_array($type, ['mail', 'event', 'contact', 'task', 'note'])) {
            return $args;
        }

        require_once $this->home . '/kolab_activesync_ui.php';
        $this->ui = new kolab_activesync_ui($this);

        if ($content = $this->ui->folder_options_table($mbox_imap, $devices, $type)) {
            $args['form']['activesync'] = [
                'name'    => $this->gettext('tabtitle'),
                'content' => $content,
            ];
        }

        return $args;
    }

    /**
     * Handle JSON requests
     */
    public function json_command()
    {
        $cmd  = rcube_utils::get_input_value('cmd', rcube_utils::INPUT_POST);
        $imei = rcube_utils::get_input_value('id', rcube_utils::INPUT_POST);

        switch ($cmd) {
            case 'save':
                $devices       = $this->list_devices();
                $device        = $devices[$imei];
                $subscriptions = (array) rcube_utils::get_input_value('subscribed', rcube_utils::INPUT_POST);
                $devicealias   = rcube_utils::get_input_value('devicealias', rcube_utils::INPUT_POST, true);
                $device['ALIAS'] = $devicealias;

                $err = !$this->device_update($device, $imei);

                if (!$err) {
                    // iterate over folders list and update metadata if necessary
                    // old subscriptions
                    foreach (array_keys($this->folder_meta()) as $folder) {
                        $err |= !$this->folder_set($folder, $imei, intval($subscriptions[$folder] ?? 0));
                        unset($subscriptions[$folder]);
                    }
                    // new subscription
                    foreach ($subscriptions as $folder => $flag) {
                        $err |= !$this->folder_set($folder, $imei, intval($flag));
                    }

                    $this->rc->output->command('plugin.activesync_save_complete', [
                        'success' => !$err, 'id' => $imei, 'alias' => rcube::Q($devicealias)]);
                }

                if ($err) {
                    $this->rc->output->show_message($this->gettext('savingerror'), 'error');
                } else {
                    $this->rc->output->show_message($this->gettext('successfullysaved'), 'confirmation');
                }

                break;

            case 'delete':
                foreach ((array) $imei as $id) {
                    $success = $this->device_delete($id);
                }

                if (!empty($success)) {
                    $this->rc->output->show_message($this->gettext('successfullydeleted'), 'confirmation');
                    $this->rc->output->command('plugin.activesync_save_complete', [
                            'success' => true,
                            'delete'  => true,
                            'id'      => count($imei) > 1 ? 'ALL' : $imei[0],
                    ]);
                } else {
                    $this->rc->output->show_message($this->gettext('savingerror'), 'error');
                }

                break;

            case 'update':
                $subscription = (int) rcube_utils::get_input_value('flag', rcube_utils::INPUT_POST);
                $folder       = rcube_utils::get_input_value('folder', rcube_utils::INPUT_POST);

                $err = !$this->folder_set($folder, $imei, $subscription);

                if ($err) {
                    $this->rc->output->show_message($this->gettext('savingerror'), 'error');
                } else {
                    $this->rc->output->show_message($this->gettext('successfullysaved'), 'confirmation');
                }

                break;
        }

        $this->rc->output->send();
    }

    /**
     * Render main UI for devices configuration
     */
    public function config_view()
    {
        $storage = $this->rc->get_storage();

        // checks if IMAP server supports any of METADATA, ANNOTATEMORE, ANNOTATEMORE2
        if (!($storage->get_capability('METADATA') || $storage->get_capability('ANNOTATEMORE') || $storage->get_capability('ANNOTATEMORE2'))) {
            $this->rc->output->show_message($this->gettext('notsupported'), 'error');
        }

        require_once $this->home . '/kolab_activesync_ui.php';

        $this->ui = new kolab_activesync_ui($this);

        $this->register_handler('plugin.devicelist', [$this->ui, 'device_list']);

        $this->rc->output->send('kolab_activesync.config');
    }

    /**
     * Render device configuration form
     */
    public function config_frame()
    {
        $storage = $this->rc->get_storage();

        // checks if IMAP server supports any of METADATA, ANNOTATEMORE, ANNOTATEMORE2
        if (!($storage->get_capability('METADATA') || $storage->get_capability('ANNOTATEMORE') || $storage->get_capability('ANNOTATEMORE2'))) {
            $this->rc->output->show_message($this->gettext('notsupported'), 'error');
        }

        require_once $this->home . '/kolab_activesync_ui.php';

        $this->ui = new kolab_activesync_ui($this);

        if (!empty($_GET['_init'])) {
            return $this->ui->init_message();
        }

        $this->register_handler('plugin.deviceconfigform', [$this->ui, 'device_config_form']);
        $this->register_handler('plugin.foldersubscriptions', [$this->ui, 'folder_subscriptions']);

        $imei    = rcube_utils::get_input_value('_id', rcube_utils::INPUT_GPC);
        $devices = $this->list_devices();

        if ($device = $devices[$imei]) {
            $this->ui->device        = $device;
            $this->ui->device['_id'] = $imei;
            $this->rc->output->set_env('active_device', $imei);
            $this->rc->output->command('parent.enable_command', 'plugin.delete-device', true);
        } else {
            $this->rc->output->show_message($this->gettext('devicenotfound'), 'error');
        }

        $this->rc->output->send('kolab_activesync.configedit');
    }

    /**
     * Get list of all folders available for sync
     *
     * @return array List of mailbox folders
     */
    public function list_folders()
    {
        $storage = $this->rc->get_storage();

        return $storage->list_folders();
    }

    /**
     * List known devices
     *
     * @return array Device list as hash array
     */
    public function list_devices()
    {
        if ($this->root_meta === null) {
            $storage = $this->rc->get_storage();
            // @TODO: consider server annotation instead of INBOX
            if ($meta = $storage->get_metadata(self::ROOT_MAILBOX, self::ASYNC_KEY)) {
                $this->root_meta = $this->unserialize_metadata($meta[self::ROOT_MAILBOX][self::ASYNC_KEY]);
            } else {
                $this->root_meta = [];
            }
        }

        if (!empty($this->root_meta['DEVICE']) && is_array($this->root_meta['DEVICE'])) {
            return $this->root_meta['DEVICE'];
        }

        return [];
    }

    /**
     * Getter for folder metadata
     *
     * @return array Hash array with meta data for each folder
     */
    public function folder_meta()
    {
        if (!isset($this->folder_meta)) {
            $this->folder_meta = [];
            $storage = $this->rc->get_storage();

            // get folders activesync config
            $folderdata = $storage->get_metadata("*", self::ASYNC_KEY);

            foreach ($folderdata as $folder => $meta) {
                if ($asyncdata = $meta[self::ASYNC_KEY]) {
                    if ($metadata = $this->unserialize_metadata($asyncdata)) {
                        $this->folder_meta[$folder] = $metadata;
                    }
                }
            }
        }

        return $this->folder_meta;
    }

    /**
     * Sets ActiveSync subscription flag on a folder
     *
     * @param string $name      Folder name (UTF7-IMAP)
     * @param string $deviceid  Device identifier
     * @param int    $flag      Flag value (0|1|2)
     */
    public function folder_set($name, $deviceid, $flag)
    {
        if (empty($deviceid)) {
            return false;
        }

        // get folders activesync config
        $metadata = $this->folder_meta();
        $metadata = $metadata[$name] ?? null;

        if ($flag) {
            if (empty($metadata)) {
                $metadata = [];
            }

            if (empty($metadata['FOLDER'])) {
                $metadata['FOLDER'] = [];
            }

            if (empty($metadata['FOLDER'][$deviceid])) {
                $metadata['FOLDER'][$deviceid] = [];
            }

            // Z-Push uses:
            //  1 - synchronize, no alarms
            //  2 - synchronize with alarms
            $metadata['FOLDER'][$deviceid]['S'] = $flag;
        }

        if (!$flag) {
            unset($metadata['FOLDER'][$deviceid]['S']);

            if (empty($metadata['FOLDER'][$deviceid])) {
                unset($metadata['FOLDER'][$deviceid]);
            }

            if (empty($metadata['FOLDER'])) {
                unset($metadata['FOLDER']);
            }

            if (empty($metadata)) {
                $metadata = null;
            }
        }

        // Return if nothing's been changed
        if (!self::data_array_diff($this->folder_meta[$name] ?? null, $metadata)) {
            return true;
        }

        $this->folder_meta[$name] = $metadata;

        $storage = $this->rc->get_storage();

        return $storage->set_metadata($name, [
            self::ASYNC_KEY => $this->serialize_metadata($metadata)]);
    }

    /**
     * Device update
     *
     * @param array  $device Device data
     * @param string $id     Device ID
     *
     * @return bool True on success, False on failure
     */
    public function device_update($device, $id)
    {
        $devices_list = $this->list_devices();
        $old_device   = $devices_list[$id];

        if (!$old_device) {
            return false;
        }

        // Do nothing if nothing is changed
        if (!self::data_array_diff($old_device, $device)) {
            return true;
        }

        $device = array_merge($old_device, $device);

        $metadata = $this->root_meta;
        $metadata['DEVICE'][$id] = $device;
        $metadata = [self::ASYNC_KEY => $this->serialize_metadata($metadata)];
        $storage  = $this->rc->get_storage();

        $result = $storage->set_metadata(self::ROOT_MAILBOX, $metadata);

        if ($result) {
            // Update local cache
            $this->root_meta['DEVICE'][$id] = $device;
        }

        return $result;
    }

    /**
     * Device delete.
     *
     * @param string $id  Device ID
     *
     * @return bool True on success, False on failure
     */
    public function device_delete($id)
    {
        $devices_list = $this->list_devices();
        $old_device   = $devices_list[$id];

        if (!$old_device) {
            return false;
        }

        unset($this->root_meta['DEVICE'][$id], $this->root_meta['FOLDER'][$id]);

        if (empty($this->root_meta['DEVICE'])) {
            unset($this->root_meta['DEVICE']);
        }
        if (empty($this->root_meta['FOLDER'])) {
            unset($this->root_meta['FOLDER']);
        }

        $metadata = $this->serialize_metadata($this->root_meta);
        $metadata = [self::ASYNC_KEY => $metadata];
        $storage  = $this->rc->get_storage();

        // update meta data
        $result = $storage->set_metadata(self::ROOT_MAILBOX, $metadata);

        if ($result) {
            // remove device annotation for every folder
            foreach ($this->folder_meta() as $folder => $meta) {
                // skip root folder (already handled above)
                if ($folder == self::ROOT_MAILBOX) {
                    continue;
                }

                if (!empty($meta['FOLDER']) && isset($meta['FOLDER'][$id])) {
                    unset($meta['FOLDER'][$id]);

                    if (empty($meta['FOLDER'])) {
                        unset($this->folder_meta[$folder]['FOLDER']);
                        unset($meta['FOLDER']);
                    }
                    if (empty($meta)) {
                        unset($this->folder_meta[$folder]);
                        $meta = null;
                    }

                    $metadata = [self::ASYNC_KEY => $this->serialize_metadata($meta)];
                    $res = $storage->set_metadata($folder, $metadata);

                    if ($res && $meta) {
                        $this->folder_meta[$folder] = $meta;
                    }
                }
            }

            // remove device data from syncroton database
            $db    = $this->rc->get_dbh();
            $table = $db->table_name('syncroton_device');

            if (in_array($table, $db->list_tables())) {
                $db->query(
                    "DELETE FROM $table WHERE owner_id = ? AND deviceid = ?",
                    $this->rc->user->ID,
                    $id
                );
            }
        }

        return $result;
    }

    /**
     * Device information (from syncroton database)
     *
     * @param string $id  Device ID
     *
     * @return array|null Device data
     */
    public function device_info($id)
    {
        $db    = $this->rc->get_dbh();
        $table = $db->table_name('syncroton_device');

        if (in_array($table, $db->list_tables())) {
            $fields = ['devicetype', 'acsversion', 'useragent', 'friendlyname', 'os',
                'oslanguage', 'phonenumber'];

            $result = $db->query(
                "SELECT " . $db->array2list($fields, 'ident')
                . " FROM $table WHERE owner_id = ? AND id = ?",
                $this->rc->user->ID,
                $id
            );

            if ($result && ($sql_arr = $db->fetch_assoc($result))) {
                return $sql_arr;
            }
        }

        return null;
    }

    /**
     * Helper method to decode saved IMAP metadata
     */
    private function unserialize_metadata($str)
    {
        if (!empty($str)) {
            $data = @json_decode($str, true);
            return $data;
        }

        return null;
    }

    /**
     * Helper method to encode IMAP metadata for saving
     */
    private function serialize_metadata($data)
    {
        if (!empty($data) && is_array($data)) {
            $data = json_encode($data);
            return $data;
        }

        return null;
    }

    /**
     * Compares two arrays
     *
     * @param array $array1
     * @param array $array2
     *
     * @return bool True if arrays differs, False otherwise
     */
    private static function data_array_diff($array1, $array2)
    {
        if (!is_array($array1) || !is_array($array2)) {
            return $array1 != $array2;
        }

        if (count($array1) != count($array2)) {
            return true;
        }

        foreach ($array1 as $key => $val) {
            if (!array_key_exists($key, $array2)) {
                return true;
            }
            if ($val !== $array2[$key]) {
                return true;
            }
        }

        return false;
    }
}
