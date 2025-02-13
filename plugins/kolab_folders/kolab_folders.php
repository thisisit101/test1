<?php

/**
 * Type-aware folder management/listing for Kolab
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2011-2017, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_folders extends rcube_plugin
{
    public $task = '?(?!login).*';

    public $types      = ['mail', 'event', 'journal', 'task', 'note', 'contact', 'configuration', 'file', 'freebusy'];
    public $subtypes   = [
        'mail'          => ['inbox', 'drafts', 'sentitems', 'outbox', 'wastebasket', 'junkemail'],
        'event'         => ['default'],
        'task'          => ['default'],
        'journal'       => ['default'],
        'note'          => ['default'],
        'contact'       => ['default'],
        'configuration' => ['default'],
        'file'          => ['default'],
        'freebusy'      => ['default'],
    ];
    public $act_types  = ['event', 'task'];

    private $rc;
    private static $instance;
    private $expire_annotation = '/shared/vendor/cmu/cyrus-imapd/expire';
    private $is_processing = false;


    /**
     * Plugin initialization.
     */
    public function init()
    {
        self::$instance = $this;
        $this->rc = rcube::get_instance();

        // load required plugin
        $this->require_plugin('libkolab');

        // Folder listing hooks
        $this->add_hook('storage_folders', [$this, 'mailboxes_list']);

        // Folder manager hooks
        $this->add_hook('folder_form', [$this, 'folder_form']);
        $this->add_hook('folder_update', [$this, 'folder_save']);
        $this->add_hook('folder_create', [$this, 'folder_save']);
        $this->add_hook('folder_delete', [$this, 'folder_save']);
        $this->add_hook('folder_rename', [$this, 'folder_save']);
        $this->add_hook('folders_list', [$this, 'folders_list']);

        // Special folders setting
        $this->add_hook('preferences_save', [$this, 'prefs_save']);

        // ACL plugin hooks
        $this->add_hook('acl_rights_simple', [$this, 'acl_rights_simple']);
        $this->add_hook('acl_rights_supported', [$this, 'acl_rights_supported']);

        // Resolving other user folder names
        $this->add_hook('render_mailboxlist', [$this, 'render_folderlist']);
        $this->add_hook('render_folder_selector', [$this, 'render_folderlist']);
        $this->add_hook('folders_list', [$this, 'render_folderlist']);
    }

    /**
     * Handler for mailboxes_list hook. Enables type-aware lists filtering.
     */
    public function mailboxes_list($args)
    {
        // infinite loop prevention
        if ($this->is_processing) {
            return $args;
        }

        if (!$this->metadata_support()) {
            return $args;
        }

        $this->is_processing = true;

        // get folders
        $folders = kolab_storage::list_folders($args['root'], $args['name'], $args['filter'], $args['mode'] == 'LSUB', $folderdata);

        $this->is_processing = false;

        if (!is_array($folders)) {
            return $args;
        }

        // Create default folders
        if ($args['root'] == '' && $args['name'] == '*') {
            $this->create_default_folders($folders, $args['filter'], $folderdata, $args['mode'] == 'LSUB');
        }

        $args['folders'] = $folders;

        return $args;
    }

    /**
     * Handler for folders_list hook. Add css classes to folder rows.
     */
    public function folders_list($args)
    {
        if (!$this->metadata_support()) {
            return $args;
        }

        // load translations
        $this->add_texts('localization/', false);

        // Add javascript script to the client
        $this->include_script('kolab_folders.js');

        $this->add_label('folderctype');
        foreach ($this->types as $type) {
            $this->add_label('foldertype' . $type);
        }

        $skip_namespace = $this->rc->config->get('kolab_skip_namespace');
        $skip_roots     = [];

        if (!empty($skip_namespace)) {
            $storage = $this->rc->get_storage();
            foreach ((array)$skip_namespace as $ns) {
                foreach((array)$storage->get_namespace($ns) as $root) {
                    $skip_roots[] = rtrim($root[0], $root[1]);
                }
            }
        }

        $this->rc->output->set_env('skip_roots', $skip_roots);
        $this->rc->output->set_env('foldertypes', $this->types);

        // get folders types
        $folderdata = kolab_storage::folders_typedata();

        if (!is_array($folderdata)) {
            return $args;
        }

        // Add type-based style for table rows
        // See kolab_folders::folder_class_name()
        if (!empty($args['table'])) {
            $table = $args['table'];

            for ($i = 1, $cnt = $table->size(); $i <= $cnt; $i++) {
                $attrib = $table->get_row_attribs($i);
                $folder = $attrib['foldername']; // UTF7-IMAP
                $type   = $folderdata[$folder];

                if (!$type) {
                    $type = 'mail';
                }

                $class_name = self::folder_class_name($type);
                $attrib['class'] = trim($attrib['class'] . ' ' . $class_name);
                $table->set_row_attribs($attrib, $i);
            }
        }

        // Add type-based class for list items
        if (!empty($args['list']) && is_array($args['list'])) {
            foreach ($args['list'] as $k => $item) {
                $folder = $item['folder_imap']; // UTF7-IMAP
                $type   = $folderdata[$folder] ?? null;

                if (!$type) {
                    $type = 'mail';
                }

                $class_name = self::folder_class_name($type);
                $args['list'][$k]['class'] = trim($item['class'] . ' ' . $class_name);
            }
        }

        return $args;
    }

    /**
     * Handler for folder info/edit form (folder_form hook).
     * Adds folder type selector.
     */
    public function folder_form($args)
    {
        if (!$this->metadata_support()) {
            return $args;
        }
        // load translations
        $this->add_texts('localization/', false);

        // INBOX folder is of type mail.inbox and this cannot be changed
        if ($args['name'] == 'INBOX') {
            $args['form']['props']['fieldsets']['settings']['content']['foldertype'] = [
                'label' => $this->gettext('folderctype'),
                'value' => sprintf('%s (%s)', $this->gettext('foldertypemail'), $this->gettext('inbox')),
            ];

            $this->add_expire_input($args['form'], 'INBOX');

            return $args;
        }

        if (!empty($args['options']['is_root'])) {
            return $args;
        }

        $mbox = strlen($args['name']) ? $args['name'] : $args['parent_name'];

        if (isset($_POST['_ctype'])) {
            $new_ctype   = trim(rcube_utils::get_input_value('_ctype', rcube_utils::INPUT_POST));
            $new_subtype = trim(rcube_utils::get_input_value('_subtype', rcube_utils::INPUT_POST));
        }

        // Get type of the folder or the parent
        $subtype = '';
        if (strlen($mbox)) {
            [$ctype, $subtype] = $this->get_folder_type($mbox);
            if (isset($args['parent_name']) && strlen($args['parent_name']) && $subtype == 'default') {
                $subtype = ''; // there can be only one
            }
        }

        if (empty($ctype)) {
            $ctype = 'mail';
        }

        $storage = $this->rc->get_storage();

        // Don't allow changing type of shared folder, according to ACL
        if (strlen($mbox)) {
            $options = $storage->folder_info($mbox);
            if ($options['namespace'] != 'personal' && !in_array('a', (array)($options['rights'] ?? null))) {
                if (in_array($ctype, $this->types)) {
                    $value = $this->gettext('foldertype' . $ctype);
                } else {
                    $value = $ctype;
                }
                if ($subtype) {
                    $value .= ' (' . ($subtype == 'default' ? $this->gettext('default') : $subtype) . ')';
                }

                $args['form']['props']['fieldsets']['settings']['content']['foldertype'] = [
                    'label' => $this->gettext('folderctype'),
                    'value' => $value,
                ];

                return $args;
            }
        }

        // Add javascript script to the client
        $this->include_script('kolab_folders.js');

        // build type SELECT fields
        $type_select = new html_select(['name' => '_ctype', 'id' => '_folderctype',
            'onchange' => "\$('[name=\"_expire\"]').attr('disabled', \$(this).val() != 'mail')",
        ]);
        $sub_select  = new html_select(['name' => '_subtype', 'id' => '_subtype']);
        $sub_select->add('', '');

        foreach ($this->types as $type) {
            $type_select->add($this->gettext('foldertype' . $type), $type);
        }
        // add non-supported type
        if (!in_array($ctype, $this->types)) {
            $type_select->add($ctype, $ctype);
        }

        $sub_types = [];
        foreach ($this->subtypes as $ftype => $subtypes) {
            $sub_types[$ftype] = array_combine($subtypes, array_map([$this, 'gettext'], $subtypes));

            // fill options for the current folder type
            if ($ftype == $ctype || (isset($new_ctype) && $ftype == $new_ctype)) {
                $sub_select->add(array_values($sub_types[$ftype]), $subtypes);
            }
        }

        $args['form']['props']['fieldsets']['settings']['content']['folderctype'] = [
            'label' => $this->gettext('folderctype'),
            'value' => html::div(
                'input-group',
                $type_select->show($new_ctype ?? $ctype)
                . $sub_select->show($new_subtype ?? $subtype)
            ),
        ];

        $this->rc->output->set_env('kolab_folder_subtypes', $sub_types);
        $this->rc->output->set_env('kolab_folder_subtype', $new_subtype ?? $subtype);

        $this->add_expire_input($args['form'], $args['name'], $ctype);

        return $args;
    }

    /**
     * Handler for folder update/create action (folder_update/folder_create hook).
     */
    public function folder_save($args)
    {
        // Folder actions from folders list
        if (empty($args['record'])) {
            return $args;
        }

        // Folder create/update with form
        $ctype     = trim(rcube_utils::get_input_value('_ctype', rcube_utils::INPUT_POST));
        $subtype   = trim(rcube_utils::get_input_value('_subtype', rcube_utils::INPUT_POST));
        $mbox      = $args['record']['name'];
        $old_mbox  = $args['record']['oldname'] ?? null;
        $subscribe = $args['record']['subscribe'] ?? true;

        if (empty($ctype)) {
            return $args;
        }

        // load translations
        $this->add_texts('localization/', false);

        // Skip folder creation/rename in core
        // @TODO: Maybe we should provide folder_create_after and folder_update_after hooks?
        //        Using create_mailbox/rename_mailbox here looks bad
        $args['abort']  = true;

        // There can be only one default folder of specified type
        if ($subtype == 'default') {
            $default = $this->get_default_folder($ctype);

            if ($default !== null && $old_mbox != $default) {
                $args['result'] = false;
                $args['message'] = $this->gettext('defaultfolderexists');
                return $args;
            }
        }
        // Subtype sanity-checks
        elseif ($subtype && (!($subtypes = $this->subtypes[$ctype]) || !in_array($subtype, $subtypes))) {
            $subtype = '';
        }

        $ctype .= $subtype ? '.' . $subtype : '';

        $storage = $this->rc->get_storage();

        // Create folder
        if (!strlen($old_mbox)) {
            $result = $storage->create_folder($mbox, $subscribe);

            // Set folder type
            if ($result) {
                $this->set_folder_type($mbox, $ctype);
            }
        }
        // Rename folder
        else {
            if ($old_mbox != $mbox) {
                $result = $storage->rename_folder($old_mbox, $mbox);
            } else {
                $result = true;
            }

            if ($result) {
                [$oldtype, $oldsubtype] = $this->get_folder_type($mbox);
                $oldtype .= $oldsubtype ? '.' . $oldsubtype : '';

                if ($ctype != $oldtype) {
                    $this->set_folder_type($mbox, $ctype);
                }
            }
        }

        // Set messages expiration in days
        if ($result && isset($_POST['_expire'])) {
            $expire = trim(rcube_utils::get_input_value('_expire', rcube_utils::INPUT_POST));
            $expire = intval($expire) && preg_match('/^mail/', $ctype) ? intval($expire) : null;

            $storage->set_metadata($mbox, [$this->expire_annotation => $expire]);
        }

        $args['record']['class']     = self::folder_class_name($ctype);
        $args['record']['subscribe'] = $subscribe;
        $args['result'] = $result;

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
        if ($args['section'] != 'folders') {
            return $args;
        }

        $dont_override = (array) $this->rc->config->get('dont_override', []);

        // map config option name to kolab folder type annotation
        $opts = [
            'drafts_mbox' => 'mail.drafts',
            'sent_mbox'   => 'mail.sentitems',
            'junk_mbox'   => 'mail.junkemail',
            'trash_mbox'  => 'mail.wastebasket',
        ];

        // check if any of special folders has been changed
        foreach ($opts as $opt_name => $type) {
            $new = $args['prefs'][$opt_name];
            $old = $this->rc->config->get($opt_name);
            if (!strlen($new) || $new === $old || in_array($opt_name, $dont_override)) {
                unset($opts[$opt_name]);
            }
        }

        if (empty($opts)) {
            return $args;
        }

        $folderdata = kolab_storage::folders_typedata();

        if (!is_array($folderdata)) {
            return $args;
        }

        foreach ($opts as $opt_name => $type) {
            $foldername = $args['prefs'][$opt_name];

            // get all folders of specified type
            $folders = array_intersect($folderdata, [$type]);

            // folder already annotated with specified type
            if (!empty($folders[$foldername])) {
                continue;
            }

            // set type to the new folder
            $this->set_folder_type($foldername, $type);

            // unset old folder(s) type annotation
            [$maintype, $subtype] = explode('.', $type);
            foreach (array_keys($folders) as $folder) {
                $this->set_folder_type($folder, $maintype);
            }
        }

        return $args;
    }

    /**
     * Handler for ACL permissions listing (acl_rights_simple hook)
     *
     * This shall combine the write and delete permissions into one item for
     * groupware folders as updating groupware objects is an insert + delete operation.
     *
     * @param array $args Hash array with hook parameters
     *
     * @return array Hash array with modified hook parameters
     */
    public function acl_rights_simple($args)
    {
        if ($args['folder']) {
            [$type, ] = $this->get_folder_type($args['folder']);

            // we're dealing with a groupware folder here...
            if ($type && $type !== 'mail') {
                if ($args['rights']['write'] && $args['rights']['delete']) {
                    $write_perms = $args['rights']['write'] . $args['rights']['delete'];
                    $rw_perms    = $write_perms . $args['rights']['read'];

                    $args['rights']['write'] = $write_perms;
                    $args['rights']['other'] = preg_replace("/[$rw_perms]/", '', $args['rights']['other']);

                    // add localized labels and titles for the altered items
                    $args['labels'] = [
                        'other'  => $this->rc->gettext('shortacla', 'acl'),
                    ];
                    $args['titles'] = [
                        'other'  => $this->rc->gettext('longaclother', 'acl'),
                    ];
                }
            }
        }

        return $args;
    }

    /**
     * Handler for ACL permissions listing (acl_rights_supported hook)
     *
     * @param array $args Hash array with hook parameters
     *
     * @return array Hash array with modified hook parameters
     */
    public function acl_rights_supported($args)
    {
        if ($args['folder']) {
            [$type, ] = $this->get_folder_type($args['folder']);

            // we're dealing with a groupware folder here...
            if ($type && $type !== 'mail') {
                // remove some irrelevant (for groupware objects) rights
                $args['rights'] = str_split(preg_replace('/[p]/', '', implode('', $args['rights'])));
            }
        }

        return $args;
    }

    /**
     * Checks if IMAP server supports any of METADATA, ANNOTATEMORE, ANNOTATEMORE2
     *
     * @return bool
     */
    public function metadata_support()
    {
        $storage = $this->rc->get_storage();

        return $storage->get_capability('METADATA') ||
            $storage->get_capability('ANNOTATEMORE') ||
            $storage->get_capability('ANNOTATEMORE2');
    }

    /**
     * Checks if IMAP server supports any of METADATA, ANNOTATEMORE, ANNOTATEMORE2
     *
     * @param string $folder Folder name
     *
     * @return array Folder content-type
     */
    public function get_folder_type($folder)
    {
        $type = explode('.', (string)kolab_storage::folder_type($folder));

        if (!isset($type[1])) {
            $type[1] = null;
        }

        return $type;
    }

    /**
     * Sets folder content-type.
     *
     * @param string $folder Folder name
     * @param string $type   Content type
     *
     * @return bool True on success
     */
    public function set_folder_type($folder, $type = 'mail')
    {
        return kolab_storage::set_folder_type($folder, $type);
    }

    /**
     * Returns the name of default folder
     *
     * @param string $type Folder type
     *
     * @return ?string Folder name
     */
    public function get_default_folder($type)
    {
        $folderdata = kolab_storage::folders_typedata();

        if (!is_array($folderdata)) {
            return null;
        }

        // get all folders of specified type
        $folderdata = array_intersect($folderdata, [$type . '.default']);

        return key($folderdata);
    }

    /**
     * Returns CSS class name for specified folder type
     *
     * @param string $type Folder type
     *
     * @return string Class name
     */
    public static function folder_class_name($type)
    {
        if ($type && strpos($type, '.')) {
            [$ctype, $subtype] = explode('.', $type);

            return 'type-' . $ctype . ' subtype-' . $subtype;
        }

        return 'type-' . ($type ? $type : 'mail');
    }

    /**
     * Creates default folders if they doesn't exist
     */
    private function create_default_folders(&$folders, $filter, $folderdata = null, $lsub = false)
    {
        $storage     = $this->rc->get_storage();
        $namespace   = $storage->get_namespace();
        $defaults    = [];
        $prefix      = '';

        // Find personal namespace prefix
        if (is_array($namespace['personal']) && count($namespace['personal']) == 1) {
            $prefix = $namespace['personal'][0][0];
        }

        $this->load_config();

        // get configured defaults
        foreach ($this->types as $type) {
            foreach ((array)$this->subtypes[$type] as $subtype) {
                $opt_name = 'kolab_folders_' . $type . '_' . $subtype;
                if ($folder = $this->rc->config->get($opt_name)) {
                    // convert configuration value to UTF7-IMAP charset
                    $folder = rcube_charset::convert($folder, RCUBE_CHARSET, 'UTF7-IMAP');
                    // and namespace prefix if needed
                    if ($prefix && strpos($folder, $prefix) === false && $folder != 'INBOX') {
                        $folder = $prefix . $folder;
                    }
                    $defaults[$type . '.' . $subtype] = $folder;
                }
            }
        }

        if (empty($defaults)) {
            return;
        }

        if ($folderdata === null) {
            $folderdata = kolab_storage::folders_typedata();
        }

        if (!is_array($folderdata)) {
            return;
        }

        // find default folders
        foreach ($defaults as $type => $foldername) {
            // get all folders of specified type
            $_folders = array_intersect($folderdata, [$type]);

            // default folder found
            if (!empty($_folders)) {
                continue;
            }

            [$type1, $type2] = explode('.', $type);

            $activate = in_array($type1, $this->act_types);
            $exists   = false;
            $result   = false;
            $subscribed = false;

            // check if folder exists
            if (!empty($folderdata[$foldername]) || $foldername == 'INBOX') {
                $exists = true;
            } elseif ((!$filter || $filter == $type1) && in_array($foldername, $folders)) {
                // this assumes also that subscribed folder exists
                $exists = true;
            } else {
                $exists = $storage->folder_exists($foldername);
            }

            // create folder
            if (!$exists) {
                $exists = $storage->create_folder($foldername);
            }

            // set type + subscribe + activate
            if ($exists) {
                if ($result = kolab_storage::set_folder_type($foldername, $type)) {
                    // check if folder is subscribed
                    if ((!$filter || $filter == $type1) && $lsub && in_array($foldername, $folders)) {
                        // already subscribed
                        $subscribed = true;
                    } else {
                        $subscribed = $storage->subscribe($foldername);
                    }

                    // activate folder
                    if ($activate) {
                        kolab_storage::folder_activate($foldername);
                    }
                }
            }

            // add new folder to the result
            if ($result && (!$filter || $filter == $type1) && (!$lsub || $subscribed)) {
                $folders[] = $foldername;
            }
        }
    }

    /**
     * Static getter for default folder of the given type
     *
     * @param string $type Folder type
     *
     * @return string Folder name
     */
    public static function default_folder($type)
    {
        return self::$instance->get_default_folder($type);
    }

    /**
     * Get /shared/vendor/cmu/cyrus-imapd/expire value
     *
     * @param string $folder IMAP folder name
     *
     * @return int|false The annotation value or False if not supported
     */
    private function get_expire_annotation($folder)
    {
        $storage = $this->rc->get_storage();

        if ($storage->get_vendor() != 'cyrus') {
            return false;
        }

        if (!strlen($folder)) {
            return 0;
        }

        $value = $storage->get_metadata($folder, $this->expire_annotation);

        if (is_array($value)) {
            return !empty($value[$folder]) ? intval($value[$folder][$this->expire_annotation] ?? 0) : 0;
        }

        return false;
    }

    /**
     * Add expiration time input to the form if supported
     */
    private function add_expire_input(&$form, $folder, $type = null)
    {
        if (($expire = $this->get_expire_annotation($folder)) !== false) {
            $post    = trim(rcube_utils::get_input_value('_expire', rcube_utils::INPUT_POST));
            $is_mail = empty($type) || preg_match('/^mail/i', $type);
            $label   = $this->gettext('xdays');
            $input   = new html_inputfield([
                    'id'       => '_kolabexpire',
                    'name'     => '_expire',
                    'size'     => 3,
                    'disabled' => !$is_mail,
            ]);

            if ($post && $is_mail) {
                $expire = (int) $post;
            }

            if (strpos($label, '$') === 0) {
                $label = str_replace('$x', '', $label);
                $html  = $input->show($expire ?: '')
                    . html::span('input-group-append', html::span('input-group-text', rcube::Q($label)));
            } else {
                $label = str_replace('$x', '', $label);
                $html  = html::span('input-group-prepend', html::span('input-group-text', rcube::Q($label)))
                    . $input->show($expire ?: '');
            }

            $form['props']['fieldsets']['settings']['content']['kolabexpire'] = [
                'label' => $this->gettext('folderexpire'),
                'value' => html::div('input-group', $html),
            ];
        }
    }

    /**
     * Handler for various folders list widgets (hooks)
     *
     * @param array $args Hash array with hook parameters
     *
     * @return array Hash array with modified hook parameters
     */
    public function render_folderlist($args)
    {
        $storage  = $this->rc->get_storage();
        $ns_other = $storage->get_namespace('other');
        $is_fl    = $this->rc->plugins->is_processing('folders_list');

        foreach ((array) $ns_other as $root) {
            $delim  = $root[1];
            $prefix = rtrim($root[0], $delim);
            $length = strlen($prefix);

            if (!$length) {
                continue;
            }

            // folders_list hook mode
            if ($is_fl) {
                foreach ((array) $args['list'] as $folder_name => $folder) {
                    if (strpos($folder_name, $root[0]) === 0 && !substr_count($folder_name, $root[1], $length + 1)) {
                        if ($name = kolab_storage::folder_id2user(substr($folder_name, $length + 1), true)) {
                            $old     = $args['list'][$folder_name]['display'];
                            $content = $args['list'][$folder_name]['content'];

                            $name    = rcube::Q($name);
                            $content = str_replace(">$old<", ">$name<", $content);

                            $args['list'][$folder_name]['display'] = $name;
                            $args['list'][$folder_name]['content'] = $content;
                        }
                    }
                }

                // TODO: Re-sort the list
            }
            // render_* hooks mode
            elseif (!empty($args['list'][$prefix]) && !empty($args['list'][$prefix]['folders'])) {
                $map = [];
                foreach ($args['list'][$prefix]['folders'] as $folder_name => $folder) {
                    if ($name = kolab_storage::folder_id2user($folder_name, true)) {
                        $args['list'][$prefix]['folders'][$folder_name]['name'] = $name;
                    }

                    $map[$folder_name] = $name ?: $args['list'][$prefix]['folders'][$folder_name]['name'];
                }

                // Re-sort the list
                uasort($map, 'strcoll');
                $args['list'][$prefix]['folders'] = array_replace($map, $args['list'][$prefix]['folders']);
            }
        }

        return $args;
    }
}
