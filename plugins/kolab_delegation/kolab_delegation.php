<?php

/**
 * Delegation configuration utility for Kolab accounts
 *
 * @version @package_version@
 * @author Aleksander Machniak <machniak@kolabsys.com>
 * @author Thomas Bruederli <bruederli@kolabsys.com>
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

class kolab_delegation extends rcube_plugin
{
    public $task = 'login|mail|settings|calendar|tasks';

    private $rc;
    private $engine;
    private $skin_path;


    /**
     * Plugin initialization.
     */
    public function init()
    {
        $this->rc = rcmail::get_instance();

        $this->require_plugin('libkolab');
        $this->require_plugin('kolab_auth');

        // on-login delegation initialization
        $this->add_hook('login_after', [$this, 'login_hook']);
        // on-check-recent delegation support
        $this->add_hook('check_recent', [$this, 'check_recent_hook']);

        // on-message-send delegation support
        $this->add_hook('message_before_send', [$this, 'message_before_send']);

        // delegation support in Calendar and Tasklist plugins
        $this->add_hook('message_load', [$this, 'message_load']);
        $this->add_hook('calendar_user_emails', [$this, 'calendar_user_emails']);
        $this->add_hook('calendar_list_filter', [$this, 'calendar_list_filter']);
        $this->add_hook('calendar_load_itip', [$this, 'calendar_load_itip']);
        $this->add_hook('tasklist_list_filter', [$this, 'tasklist_list_filter']);

        // delegation support in kolab_auth plugin
        $this->add_hook('kolab_auth_emails', [$this, 'kolab_auth_emails']);

        // delegation support in Enigma plugin
        $this->add_hook('enigma_user_identities', [$this, 'user_identities']);

        if ($this->rc->task == 'settings') {
            // delegation management interface
            $this->register_action('plugin.delegation', [$this, 'controller_ui']);
            $this->register_action('plugin.delegation-delete', [$this, 'controller_action']);
            $this->register_action('plugin.delegation-save', [$this, 'controller_action']);
            $this->register_action('plugin.delegation-autocomplete', [$this, 'controller_action']);

            $this->add_hook('settings_actions', [$this, 'settings_actions']);

            if ($this->rc->output->type == 'html'
                && ($this->rc->action == 'plugin.delegation' || empty($_REQUEST['_framed']))
            ) {
                $this->add_texts('localization/', ['deleteconfirm', 'savingdata', 'yes', 'no']);

                if ($this->rc->action == 'plugin.delegation') {
                    $this->include_script('kolab_delegation.js');
                }

                $this->skin_path = $this->local_skin_path();
                $this->include_stylesheet($this->skin_path . '/style.css');
            }
        }
        // Calendar/Tasklist plugin UI bindings
        elseif (($this->rc->task == 'calendar' || $this->rc->task == 'tasks')
            && empty($_REQUEST['_framed'])
        ) {
            if ($this->rc->output->type == 'html') {
                $this->calendar_ui();
            }
        }
    }

    /**
     * Adds Delegation section in Settings
     */
    public function settings_actions($args)
    {
        $args['actions'][] = [
            'action' => 'plugin.delegation',
            'class'  => 'delegation',
            'label'  => 'tabtitle',
            'domain' => 'kolab_delegation',
            'title'  => 'delegationtitle',
        ];

        return $args;
    }

    /**
     * Engine object getter
     */
    private function engine()
    {
        if (!$this->engine) {
            require_once $this->home . '/kolab_delegation_engine.php';

            $this->load_config();
            $this->engine = new kolab_delegation_engine();
        }

        return $this->engine;
    }

    /**
     * On-login action
     */
    public function login_hook($args)
    {
        // Manage (create) identities for delegator's email addresses
        // and subscribe to delegator's folders. Also remove identities
        // after delegation is removed

        $engine = $this->engine();
        $engine->delegation_init();

        return $args;
    }

    /**
     * Check-recent action
     */
    public function check_recent_hook($args)
    {
        // Checking for new messages shall be extended to Inbox folders of all
        // delegators if 'check_all_folders' is set to false.

        if ($this->rc->task != 'mail') {
            return $args;
        }

        if (!empty($args['all'])) {
            return $args;
        }

        if (empty($_SESSION['delegators'])) {
            return $args;
        }

        $storage  = $this->rc->get_storage();
        $other_ns = $storage->get_namespace('other');
        $folders  = $storage->list_folders_subscribed('', '*', 'mail');

        foreach (array_keys($_SESSION['delegators']) as $uid) {
            foreach ($other_ns as $ns) {
                $folder = $ns[0] . $uid;
                if (in_array($folder, $folders) && !in_array($folder, $args['folders'])) {
                    $args['folders'][] = $folder;
                }
            }
        }

        return $args;
    }

    /**
     * Mail send action
     */
    public function message_before_send($args)
    {
        // Checking headers of email being send, we'll add
        // Sender: header if mail is send on behalf of someone else

        if (!empty($_SESSION['delegators'])) {
            $engine = $this->engine();
            $engine->delegator_delivery_filter($args);
        }

        return $args;
    }

    /**
     * E-mail message loading action
     */
    public function message_load($args)
    {
        // This is a place where we detect delegate context
        // So we can handle event invitations on behalf of delegator
        // @TODO: should we do this only in delegators' folders?

        // skip invalid messages or Kolab objects (for better performance)
        if (empty($args['object']->headers) || $args['object']->headers->get('x-kolab-type', false)) {
            return $args;
        }

        $engine  = $this->engine();
        $context = $engine->delegator_context_from_message($args['object']);

        if ($context) {
            $this->rc->output->set_env('delegator_context', $context);
            $this->include_script('kolab_delegation.js');
        }

        return $args;
    }

    /**
     * calendar::get_user_emails() handler
     */
    public function calendar_user_emails($args)
    {
        // In delegator context we'll use delegator's addresses
        // instead of current user addresses

        if (!empty($_SESSION['delegators'])) {
            $engine = $this->engine();
            $engine->delegator_emails_filter($args);
        }

        return $args;
    }

    /**
     * calendar_driver::list_calendars() handler
     */
    public function calendar_list_filter($args)
    {
        // In delegator context we'll use delegator's folders
        // instead of current user folders

        if (!empty($_SESSION['delegators'])) {
            $engine = $this->engine();
            $engine->delegator_folder_filter($args, 'calendars');
        }

        return $args;
    }

    /**
     * tasklist_driver::get_lists() handler
     */
    public function tasklist_list_filter($args)
    {
        // In delegator context we'll use delegator's folders
        // instead of current user folders

        if (!empty($_SESSION['delegators'])) {
            $engine = $this->engine();
            $engine->delegator_folder_filter($args, 'tasklists');
        }

        return $args;
    }

    /**
     * calendar::load_itip() handler
     */
    public function calendar_load_itip($args)
    {
        // In delegator context we'll use delegator's address/name
        // for invitation responses

        if (!empty($_SESSION['delegators'])) {
            $engine = $this->engine();
            $engine->delegator_identity_filter($args);
        }

        return $args;
    }

    /**
     * Delegation support in Calendar/Tasks plugin UI
     */
    public function calendar_ui()
    {
        // Initialize handling of delegators' identities in event form

        if (!empty($_SESSION['delegators'])) {
            $engine = $this->engine();
            $this->rc->output->set_env('namespace', $engine->namespace_js());
            $this->rc->output->set_env('delegators', $engine->list_delegators_js());
            $this->include_script('kolab_delegation.js');
        }
    }

    /**
     * Delegation support in kolab_auth plugin
     */
    public function kolab_auth_emails($args)
    {
        // Add delegators addresses to address selector in user identity form

        if (!empty($_SESSION['delegators'])) {
            // @TODO: Consider not adding all delegator addresses to the list.
            // Instead add only address of currently edited identity
            foreach ($_SESSION['delegators'] as $emails) {
                $args['emails'] = array_merge($args['emails'], $emails);
            }

            $args['emails'] = array_unique($args['emails']);
            sort($args['emails']);
        }

        return $args;
    }

    /**
     * Delegation support in Enigma plugin
     */
    public function user_identities($args)
    {
        // Remove delegators' identities from the key generation form

        if (!empty($_SESSION['delegators'])) {
            $args['identities'] = array_filter($args['identities'], function ($ident) {
                foreach ($_SESSION['delegators'] as $emails) {
                    if (in_array($ident['email'], $emails)) {
                        return false;
                    }
                }

                return true;
            });
        }

        return $args;
    }

    /**
     * Delegation UI handler
     */
    public function controller_ui()
    {
        // main interface (delegates list)
        if (empty($_REQUEST['_framed'])) {
            $this->register_handler('plugin.delegatelist', [$this, 'delegate_list']);

            $this->rc->output->include_script('list.js');
            $this->rc->output->send('kolab_delegation.settings');
        }
        // delegate frame
        else {
            $this->register_handler('plugin.delegateform', [$this, 'delegate_form']);
            $this->register_handler('plugin.delegatefolders', [$this, 'delegate_folders']);

            $this->rc->output->set_env('autocomplete_max', (int)$this->rc->config->get('autocomplete_max', 15));
            $this->rc->output->set_env('autocomplete_min_length', $this->rc->config->get('autocomplete_min_length'));
            $this->rc->output->add_label('autocompletechars', 'autocompletemore');

            $this->rc->output->send('kolab_delegation.editform');
        }
    }

    /**
     * Delegation action handler
     */
    public function controller_action()
    {
        $this->add_texts('localization/');

        $engine = $this->engine();

        // Delegate delete
        if ($this->rc->action == 'plugin.delegation-delete') {
            $id    = rcube_utils::get_input_value('id', rcube_utils::INPUT_GPC);
            $error = $engine->delegate_delete($id, (bool) rcube_utils::get_input_value('acl', rcube_utils::INPUT_GPC));

            if (!$error) {
                $this->rc->output->show_message($this->gettext('deletesuccess'), 'confirmation');
                $this->rc->output->command('plugin.delegate_save_complete', ['deleted' => $id]);
            } else {
                $this->rc->output->show_message($this->gettext($error), 'error');
            }
        }
        // Delegate add/update
        elseif ($this->rc->action == 'plugin.delegation-save') {
            $id  = rcube_utils::get_input_value('id', rcube_utils::INPUT_GPC);
            $acl = rcube_utils::get_input_value('folders', rcube_utils::INPUT_GPC);

            // update
            if ($id) {
                $delegate = $engine->delegate_get($id);
                $error    = $engine->delegate_acl_update($delegate['uid'], $acl);

                if (!$error) {
                    $this->rc->output->show_message($this->gettext('updatesuccess'), 'confirmation');
                    $this->rc->output->command('plugin.delegate_save_complete', ['updated' => $id]);
                } else {
                    $this->rc->output->show_message($this->gettext($error), 'error');
                }
            }
            // new
            else {
                $login    = rcube_utils::get_input_value('newid', rcube_utils::INPUT_GPC);
                $delegate = $engine->delegate_get_by_name($login);
                $error    = $engine->delegate_add($delegate, $acl);

                if (!$error) {
                    $this->rc->output->show_message($this->gettext('createsuccess'), 'confirmation');
                    $this->rc->output->command('plugin.delegate_save_complete', [
                        'created' => $delegate['ID'],
                        'name'    => $delegate['name'],
                    ]);
                } else {
                    $this->rc->output->show_message($this->gettext($error), 'error');
                }
            }
        }
        // Delegate autocompletion
        elseif ($this->rc->action == 'plugin.delegation-autocomplete') {
            $search = rcube_utils::get_input_value('_search', rcube_utils::INPUT_GPC, true);
            $reqid  = rcube_utils::get_input_value('_reqid', rcube_utils::INPUT_GPC);
            $users  = $engine->list_users($search);

            $this->rc->output->command('ksearch_query_results', $users, $search, $reqid);
        }

        $this->rc->output->send();
    }

    /**
     * Template object of delegates list
     */
    public function delegate_list($attrib = [])
    {
        $attrib += ['id' => 'delegate-list'];

        $engine = $this->engine();
        $list   = $engine->list_delegates();
        $table  = new html_table();

        // sort delegates list
        asort($list, SORT_LOCALE_STRING);

        foreach ($list as $id => $delegate) {
            $table->add_row(['id' => 'rcmrow' . $id]);
            $table->add(null, rcube::Q($delegate));
        }

        $this->rc->output->add_gui_object('delegatelist', $attrib['id']);
        $this->rc->output->set_env('delegatecount', count($list));

        return $table->show($attrib);
    }

    /**
     * Template object of delegate form
     */
    public function delegate_form($attrib = [])
    {
        $engine   = $this->engine();
        $table    = new html_table(['cols' => 2]);
        $id       = rcube_utils::get_input_value('_id', rcube_utils::INPUT_GPC);
        $field_id = 'delegate';

        if ($id) {
            $delegate = $engine->delegate_get($id);
        }

        if (!empty($delegate)) {
            $input = new html_hiddenfield(['name' => $field_id, 'id' => $field_id, 'size' => 40, 'value' => $id]);
            $input = rcube::Q($delegate['name']) . $input->show();

            $this->rc->output->set_env('active_delegate', $id);
            $this->rc->output->command('parent.enable_command', 'delegate-delete', true);
        } else {
            $input = new html_inputfield(['name' => $field_id, 'id' => $field_id, 'size' => 40]);
            $input = $input->show();
        }

        $table->add('title', html::label($field_id, $this->gettext('delegate')));
        $table->add(null, $input);

        if ($attrib['form']) {
            $this->rc->output->add_gui_object('editform', $attrib['form']);
        }

        return $table->show($attrib);
    }

    /**
     * Template object of folders list
     */
    public function delegate_folders($attrib = [])
    {
        if (!$attrib['id']) {
            $attrib['id'] = 'delegatefolders';
        }

        $engine = $this->engine();
        $id     = rcube_utils::get_input_value('_id', rcube_utils::INPUT_GPC);

        if ($id) {
            $delegate = $engine->delegate_get($id);
        }

        $folder_data   = $engine->list_folders(!empty($delegate) ? $delegate['uid'] : null);
        $use_fieldsets = rcube_utils::get_boolean($attrib['use-fieldsets']);
        $rights        = [];
        $folder_groups = [];

        foreach ($folder_data as $folder_name => $folder) {
            $folder_groups[$folder['type']][] = $folder_name;
            $rights[$folder_name] = $folder['rights'];
        }

        $html = '';

        // build block for every folder type
        foreach ($folder_groups as $type => $group) {
            $attrib['type'] = $type;
            $table = $this->delegate_folders_block($group, $attrib, $rights);
            $label = $this->gettext($type);

            if ($use_fieldsets) {
                $html .= html::tag('fieldset', 'foldersblock', html::tag('legend', $type, $label) . $table);
            } else {
                $html .= html::div('foldersblock', html::tag('h3', $type, $label) . $table);
            }
        }

        $this->rc->output->add_gui_object('folderslist', $attrib['id']);

        return html::div($attrib, $html);
    }

    /**
     * List of folders in specified group
     */
    private function delegate_folders_block($a_folders, $attrib, $rights)
    {
        $path      = 'plugins/kolab_delegation/' . $this->skin_path . '/';
        $read_ico  = !empty($attrib['readicon']) ? html::img(['src' =>  $path . $attrib['readicon'], 'title' => $this->gettext('read')]) : '';
        $write_ico = !empty($attrib['writeicon']) ? html::img(['src' => $path . $attrib['writeicon'], 'title' => $this->gettext('write')]) : '';

        $table = new html_table(['cellspacing' => 0, 'class' => 'table-striped']);
        $table->add_header(['class' => 'read checkbox-cell', 'title' => $this->gettext('read'), 'tabindex' => 0], $read_ico);
        $table->add_header(['class' => 'write checkbox-cell', 'title' => $this->gettext('write'), 'tabindex' => 0], $write_ico);
        $table->add_header('foldername', $this->rc->gettext('folder'));

        $checkbox_read  = new html_checkbox(['name' => 'read[]', 'class' => 'read']);
        $checkbox_write = new html_checkbox(['name' => 'write[]', 'class' => 'write']);

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
            $table->add('read checkbox-cell', $checkbox_read->show(
                $rights[$folder] >= kolab_delegation_engine::ACL_READ ? $folder : null,
                ['value' => $folder]
            ));
            $table->add('write checkbox-cell', $checkbox_write->show(
                $rights[$folder] >= kolab_delegation_engine::ACL_WRITE ? $folder : null,
                ['value' => $folder, 'id' => $folder_id]
            ));

            $table->add(implode(' ', $classes), html::label($folder_id, $foldername));
        }

        return $table->show();
    }
}
