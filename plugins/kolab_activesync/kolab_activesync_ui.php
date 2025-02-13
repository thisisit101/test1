<?php

/**
 * ActiveSync configuration user interface builder
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @author Aleksander Machniak <machniak@kolabsys.com>
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

class kolab_activesync_ui
{
    public $device = [];

    private $rc;
    private $plugin;
    private $force_subscriptions = [];
    private $skin_path;

    public const SETUP_URL = 'https://kb.kolabenterprise.com/documentation/setting-up-an-activesync-client';


    public function __construct($plugin)
    {
        $this->plugin    = $plugin;
        $this->rc        = rcube::get_instance();
        $skin_path       = $this->plugin->local_skin_path() . '/';
        $this->skin_path = 'plugins/kolab_activesync/' . $skin_path;

        $this->plugin->load_config();
        $this->force_subscriptions = $this->rc->config->get('activesync_force_subscriptions', []);

        $this->plugin->include_stylesheet($skin_path . 'config.css');
    }

    public function device_list($attrib = [])
    {
        $attrib += ['id' => 'devices-list'];

        $devices = $this->plugin->list_devices();
        $table   = new html_table();

        foreach ($devices as $id => $device) {
            $name = $device['ALIAS'] ? $device['ALIAS'] : $id;
            $table->add_row(['id' => 'rcmrow' . $id]);
            $table->add(null, html::span('devicealias', rcube::Q($name))
                . ' ' . html::span('devicetype secondary', rcube::Q($device['TYPE'])));
        }

        $this->rc->output->add_gui_object('devicelist', $attrib['id']);
        $this->rc->output->set_env('devicecount', count($devices));

        $this->rc->output->include_script('list.js');

        return $table->show($attrib);
    }


    public function device_config_form($attrib = [])
    {
        $table = new html_table(['cols' => 2]);

        $field_id = 'config-device-alias';
        $input = new html_inputfield(['name' => 'devicealias', 'id' => $field_id, 'size' => 40]);
        $table->add('title', html::label($field_id, $this->plugin->gettext('devicealias')));
        $table->add(null, $input->show($this->device['ALIAS'] ? $this->device['ALIAS'] : $this->device['_id']));

        // read-only device information
        $info = $this->plugin->device_info($this->device['ID']);

        if (!empty($info)) {
            foreach ($info as $key => $value) {
                if ($value) {
                    $table->add('title', html::label(null, rcube::Q($this->plugin->gettext($key))));
                    $table->add(null, rcube::Q($value));
                }
            }
        }

        if ($attrib['form']) {
            $this->rc->output->add_gui_object('editform', $attrib['form']);
        }

        return $table->show($attrib);
    }


    private function is_protected($folder, $devicetype)
    {
        $devicetype = strtolower($devicetype);
        if (array_key_exists($devicetype, $this->force_subscriptions)) {
            return array_key_exists($folder, $this->force_subscriptions[$devicetype]);
        }
        return false;
    }

    public function folder_subscriptions($attrib = [])
    {
        if (empty($attrib['id'])) {
            $attrib['id'] = 'foldersubscriptions';
        }

        // group folders by type (show only known types)
        $folder_groups = ['mail' => [], 'contact' => [], 'event' => [], 'task' => [], 'note' => []];
        $folder_types  = kolab_storage::folders_typedata();
        $use_fieldsets = rcube_utils::get_boolean($attrib['use-fieldsets'] ?? '');
        $imei          = $this->device['_id'];
        $subscribed    = [];

        if ($imei) {
            $folder_meta = $this->plugin->folder_meta();
        }

        $devicetype = strtolower($this->device['TYPE']);
        $device_force_subscriptions = $this->force_subscriptions[$devicetype] ?? [];

        foreach ($this->plugin->list_folders() as $folder) {
            if (!empty($folder_types[$folder])) {
                [$type, ] = explode('.', $folder_types[$folder]);
            } else {
                $type = 'mail';
            }

            if (array_key_exists($type, $folder_groups)) {
                $folder_groups[$type][] = $folder;

                if ($device_force_subscriptions && array_key_exists($folder, $device_force_subscriptions)) {
                    $subscribed[$folder] = intval($device_force_subscriptions[$folder]);
                } elseif (!empty($folder_meta[$folder]['FOLDER'][$imei]['S'])) {
                    $subscribed[$folder] = intval($folder_meta[$folder]['FOLDER'][$imei]['S']);
                }
            }
        }

        // build block for every folder type
        $html = null;
        foreach ($folder_groups as $type => $group) {
            if (empty($group)) {
                continue;
            }

            $attrib['type'] = $type;
            $table = $this->folder_subscriptions_block($group, $attrib, $subscribed);
            $label = $this->plugin->gettext($type);

            if ($use_fieldsets) {
                $html .= html::tag('fieldset', 'subscriptionblock', html::tag('legend', $type, $label) . $table);
            } else {
                $html .= html::div('subscriptionblock', html::tag('h3', $type, $label) . $table);
            }
        }

        $this->rc->output->add_gui_object('subscriptionslist', $attrib['id']);

        return html::div($attrib, $html);
    }

    public function folder_subscriptions_block($a_folders, $attrib, $subscribed)
    {
        $alarms = ($attrib['type'] == 'event' || $attrib['type'] == 'task');

        $table = new html_table(['cellspacing' => 0, 'class' => 'table-striped']);
        $table->add_header(
            [
                'class'    => 'subscription checkbox-cell',
                'title'    => $this->plugin->gettext('synchronize'),
                'tabindex' => 0,
            ],
            !empty($attrib['syncicon']) ? html::img(['src' => $this->skin_path . $attrib['syncicon']]) : $this->plugin->gettext('synchronize')
        );

        if ($alarms) {
            $table->add_header(
                [
                    'class'    => 'alarm checkbox-cell',
                    'title'    => $this->plugin->gettext('withalarms'),
                    'tabindex' => 0,
                ],
                !empty($attrib['alarmicon']) ? html::img(['src' => $this->skin_path . $attrib['alarmicon']]) : $this->plugin->gettext('withalarms')
            );
        }

        $table->add_header('foldername', $this->plugin->gettext('folder'));

        $checkbox_sync  = new html_checkbox(['name' => 'subscribed[]', 'class' => 'subscription']);
        $checkbox_alarm = new html_checkbox(['name' => 'alarm[]', 'class' => 'alarm']);

        $names = [];
        foreach ($a_folders as $folder) {
            $foldername = $origname = kolab_storage::object_prettyname($folder);

            // find folder prefix to truncate (the same code as in kolab_addressbook plugin)
            for ($i = count($names) - 1; $i >= 0; $i--) {
                if (strpos($foldername, $names[$i] . ' &raquo; ') === 0) {
                    $length = strlen($names[$i] . ' &raquo; ');
                    $prefix = substr($foldername, 0, $length);
                    $count  = count(explode(' &raquo; ', $prefix));
                    $foldername = str_repeat('&nbsp;&nbsp;', $count - 1) . '&raquo; ' . substr($foldername, $length);
                    break;
                }
            }

            $folder_id = 'rcmf' . rcube_utils::html_identifier($folder);
            $names[] = $origname;
            $classes = ['mailbox'];

            if ($folder_class = $this->rc->folder_classname($folder)) {
                if ($this->rc->text_exists($folder_class)) {
                    $foldername = html::quote($this->rc->gettext($folder_class));
                }
                $classes[] = $folder_class;
            }

            $table->add_row();

            $disabled = $this->is_protected($folder, $this->device['TYPE']);

            $table->add('subscription checkbox-cell', $checkbox_sync->show(
                !empty($subscribed[$folder]) ? $folder : null,
                ['value' => $folder, 'id' => $folder_id, 'disabled' => $disabled]
            ));

            if ($alarms) {
                $table->add('alarm checkbox-cell', $checkbox_alarm->show(
                    intval($subscribed[$folder] ?? 0) > 1 ? $folder : null,
                    ['value' => $folder, 'id' => $folder_id . '_alarm', 'disabled' => $disabled]
                ));
            }

            $table->add(implode(' ', $classes), html::label($folder_id, $foldername));
        }

        return $table->show();
    }

    public function folder_options_table($folder_name, $devices, $type)
    {
        $alarms      = $type == 'event' || $type == 'task';
        $meta        = $this->plugin->folder_meta();
        $folder_data = (array) (isset($meta[$folder_name]) ? $meta[$folder_name]['FOLDER'] : null);

        $table = new html_table(['cellspacing' => 0, 'id' => 'folder-sync-options', 'class' => 'records-table']);

        // table header
        $table->add_header(['class' => 'device'], $this->plugin->gettext('devicealias'));
        $table->add_header(['class' => 'subscription'], $this->plugin->gettext('synchronize'));
        if ($alarms) {
            $table->add_header(['class' => 'alarm'], $this->plugin->gettext('withalarms'));
        }

        // table records
        foreach ($devices as $id => $device) {
            $info     = $this->plugin->device_info($device['ID']);
            $name     = $id;
            $title    = '';
            $checkbox = new html_checkbox(['name' => "_subscriptions[$id]", 'value' => 1,
                'onchange' => 'return activesync_object.update_sync_data(this)']);

            if (!empty($info)) {
                $_name = trim($info['friendlyname'] . ' ' . $info['os']);
                $title = $info['useragent'];

                if ($_name) {
                    $name .= " ($_name)";
                }
            }

            $disabled = $this->is_protected($folder_name, $device['TYPE']);

            $table->add_row();
            $table->add(['class' => 'device', 'title' => $title], $name);
            $table->add('subscription checkbox-cell', $checkbox->show(!empty($folder_data[$id]['S']) ? 1 : 0, ['disabled' => $disabled]));

            if ($alarms) {
                $checkbox_alarm = new html_checkbox(['name' => "_alarms[$id]", 'value' => 1,
                    'onchange' => 'return activesync_object.update_sync_data(this)']);

                $table->add('alarm checkbox-cell', $checkbox_alarm->show($folder_data[$id]['S'] > 1 ? 1 : 0, ['disabled' => $disabled]));
            }
        }

        return $table->show();
    }

    /**
     * Displays initial page (when no devices are registered)
     */
    public function init_message()
    {
        $this->plugin->load_config();

        $this->rc->output->add_handlers([
                'initmessage' => [$this, 'init_message_content'],
        ]);

        $this->rc->output->send('kolab_activesync.configempty');
    }

    /**
     * Handler for initmessage template object
     */
    public function init_message_content()
    {
        $url  = $this->rc->config->get('activesync_setup_url', self::SETUP_URL);
        $vars = ['url' => $url];
        $msg  = $this->plugin->gettext(['name' => 'nodevices', 'vars' => $vars]);

        return $msg;
    }
}
