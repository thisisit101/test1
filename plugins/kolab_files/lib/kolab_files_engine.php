<?php

/**
 * Kolab files storage engine
 *
 * @version @package_version@
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2013-2015, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_files_engine
{
    private $plugin;
    private $rc;
    private $url;
    private $url_srv;
    private $filetypes_style;
    private $timeout = 600;
    private $file_data;
    private $files_sort_cols    = ['name', 'mtime', 'size'];
    private $request;
    private $sessions_sort_cols = ['name'];
    private $mimetypes = null;

    public const API_VERSION = 4;


    /**
     * Class constructor
     */
    public function __construct($plugin, $client_url, $server_url = null)
    {
        $this->url     = rtrim(rcube_utils::resolve_url($client_url), '/ ');
        $this->url_srv = $server_url ? rtrim(rcube_utils::resolve_url($server_url), '/ ') : $this->url;
        $this->plugin  = $plugin;
        $this->rc      = $plugin->rc;
        $this->timeout = $this->rc->config->get('session_lifetime') * 60;
    }

    /**
     * User interface initialization
     */
    public function ui()
    {
        $this->plugin->add_texts('localization/');

        $templates = [];
        $list_widget = false;

        // set templates of Files UI and widgets
        if ($this->rc->task == 'mail') {
            if (in_array($this->rc->action, ['', 'show', 'compose'])) {
                $templates[] = 'compose_plugin';
            }
            if (in_array($this->rc->action, ['show', 'preview', 'get'])) {
                $templates[] = 'message_plugin';

                if ($this->rc->action == 'get') {
                    // add "Save as" button into attachment toolbar
                    $this->plugin->add_button([
                        'id'         => 'saveas',
                        'name'       => 'saveas',
                        'type'       => 'link',
                        'onclick'    => 'kolab_directory_selector_dialog()',
                        'class'      => 'button buttonPas saveas',
                        'classact'   => 'button saveas',
                        'label'      => 'kolab_files.save',
                        'title'      => 'kolab_files.saveto',
                        ], 'toolbar');
                } else {
                    // add "Save as" button into attachment menu
                    $this->plugin->add_button([
                        'id'         => 'attachmenusaveas',
                        'name'       => 'attachmenusaveas',
                        'type'       => 'link',
                        'wrapper'    => 'li',
                        'onclick'    => 'return false',
                        'class'      => 'icon active saveas',
                        'classact'   => 'icon active saveas',
                        'innerclass' => 'icon active saveas',
                        'label'      => 'kolab_files.saveto',
                        ], 'attachmentmenu');
                }
            }

            $list_widget = true;
        } elseif (!$this->rc->action && in_array($this->rc->task, ['calendar', 'tasks'])) {
            $list_widget = true;
            $templates[] = 'compose_plugin';
        } elseif ($this->rc->task == 'files') {
            $templates[] = 'files';

            // get list of external sources
            $this->get_external_storage_drivers();

            // these labels may be needed even if fetching ext sources failed
            $this->plugin->add_label('folderauthtitle', 'authenticating', 'foldershare', 'saving');
        }

        if ($list_widget) {
            $this->folder_list_env();

            $this->plugin->add_label(
                'save',
                'cancel',
                'saveto',
                'saveall',
                'fromcloud',
                'attachsel',
                'selectfiles',
                'attaching',
                'collection_audio',
                'collection_video',
                'collection_image',
                'collection_document',
                'folderauthtitle',
                'authenticating'
            );
        }

        // add taskbar button
        if (empty($_REQUEST['framed'])) {
            $this->plugin->add_button([
                'command'    => 'files',
                'class'      => 'button-files',
                'classsel'   => 'button-files button-selected',
                'innerclass' => 'button-inner',
                'label'      => 'kolab_files.files',
                'type'       => 'link',
                ], 'taskbar');
        }

        $caps = $this->capabilities();

        $this->plugin->include_stylesheet($this->plugin->local_skin_path() . '/style.css');
        $this->plugin->include_script($this->url . '/js/files_api.js');
        $this->plugin->include_script('kolab_files.js');

        $this->rc->output->set_env('files_url', $this->url . '/api/');
        $this->rc->output->set_env('files_token', $this->get_api_token());
        $this->rc->output->set_env('files_caps', $caps);
        $this->rc->output->set_env('files_api_version', $caps['VERSION'] ?? 3);
        $this->rc->output->set_env('files_user', $this->rc->get_user_name());

        if (!empty($caps['DOCEDIT'])) {
            $this->plugin->add_label(
                'declinednotice',
                'invitednotice',
                'acceptedownernotice',
                'declinedownernotice',
                'requestednotice',
                'acceptednotice',
                'declinednotice',
                'more',
                'accept',
                'decline',
                'join',
                'status',
                'when',
                'file',
                'comment',
                'statusaccepted',
                'statusinvited',
                'statusdeclined',
                'statusrequested',
                'invitationaccepting',
                'invitationdeclining',
                'invitationrequesting',
                'close',
                'invitationtitle',
                'sessions',
                'saving'
            );
        }

        if (!empty($templates)) {
            $collapsed_folders = (string) $this->rc->config->get('kolab_files_collapsed_folders');

            $this->rc->output->include_script('treelist.js');
            $this->rc->output->set_env('kolab_files_collapsed_folders', $collapsed_folders);

            // register template objects for dialogs (and main interface)
            $this->rc->output->add_handlers([
                'folder-create-form' => [$this, 'folder_create_form'],
                'folder-edit-form'   => [$this, 'folder_edit_form'],
                'folder-mount-form'  => [$this, 'folder_mount_form'],
                'folder-auth-options' => [$this, 'folder_auth_options'],
                'file-search-form'   => [$this, 'file_search_form'],
                'file-rename-form'   => [$this, 'file_rename_form'],
                'file-create-form'   => [$this, 'file_create_form'],
                'file-edit-dialog'   => [$this, 'file_edit_dialog'],
                'file-session-dialog' => [$this, 'file_session_dialog'],
                'filelist'           => [$this, 'file_list'],
                'sessionslist'       => [$this, 'sessions_list'],
                'filequotadisplay'   => [$this, 'quota_display'],
                'document-editors-dialog' => [$this, 'document_editors_dialog'],
            ]);

            if ($this->rc->task != 'files') {
                // add dialog(s) content at the end of page body
                foreach ($templates as $template) {
                    $this->rc->output->add_footer(
                        $this->rc->output->parse('kolab_files.' . $template, false, false)
                    );
                }
            }
        }
    }

    /**
     * Engine actions handler
     */
    public function actions()
    {
        if ($this->rc->task == 'files' && $this->rc->action) {
            $action = $this->rc->action;
        } elseif ($this->rc->task != 'files' && $_POST['act']) {
            $action = $_POST['act'];
        } else {
            $action = 'index';
        }

        $method = 'action_' . str_replace('-', '_', $action);

        if (method_exists($this, $method)) {
            $this->plugin->add_texts('localization/');
            $this->{$method}();
        }
    }

    /**
     * Template object for folder creation form
     */
    public function folder_create_form($attrib)
    {
        $attrib['name'] = 'folder-create-form';
        if (empty($attrib['id'])) {
            $attrib['id'] = 'folder-create-form';
        }

        $input_name    = new html_inputfield(['id' => 'folder-name', 'name' => 'name', 'size' => 30]);
        $select_parent = new html_select(['id' => 'folder-parent', 'name' => 'parent']);
        $table         = new html_table(['cols' => 2, 'class' => 'propform']);

        $table->add('title', html::label('folder-name', rcube::Q($this->plugin->gettext('foldername'))));
        $table->add(null, $input_name->show());
        $table->add('title', html::label('folder-parent', rcube::Q($this->plugin->gettext('folderinside'))));
        $table->add(null, $select_parent->show());

        $out = $table->show();

        // add form tag around text field
        if (empty($attrib['form'])) {
            $out = $this->rc->output->form_tag($attrib, $out);
        }

        $this->plugin->add_label('foldercreating', 'foldercreatenotice', 'create', 'foldercreate', 'cancel', 'addfolder');
        $this->rc->output->add_gui_object('folder-create-form', $attrib['id']);

        return $out;
    }

    /**
     * Template object for folder editing form
     */
    public function folder_edit_form($attrib)
    {
        $attrib['name'] = 'folder-edit-form';
        if (empty($attrib['id'])) {
            $attrib['id'] = 'folder-edit-form';
        }

        $input_name    = new html_inputfield(['id' => 'folder-edit-name', 'name' => 'name', 'size' => 30]);
        $select_parent = new html_select(['id' => 'folder-edit-parent', 'name' => 'parent']);
        $table         = new html_table(['cols' => 2, 'class' => 'propform']);

        $table->add('title', html::label('folder-edit-name', rcube::Q($this->plugin->gettext('foldername'))));
        $table->add(null, $input_name->show());
        $table->add('title', html::label('folder-edit-parent', rcube::Q($this->plugin->gettext('folderinside'))));
        $table->add(null, $select_parent->show());

        $out = $table->show();

        // add form tag around text field
        if (empty($attrib['form'])) {
            $out = $this->rc->output->form_tag($attrib, $out);
        }

        $this->plugin->add_label('folderupdating', 'folderupdatenotice', 'save', 'folderedit', 'cancel');
        $this->rc->output->add_gui_object('folder-edit-form', $attrib['id']);

        return $out;
    }

    /**
     * Template object for folder mounting form
     */
    public function folder_mount_form($attrib)
    {
        $sources = $this->rc->output->get_env('external_sources');

        if (empty($sources) || !is_array($sources)) {
            return '';
        }

        $attrib['name'] = 'folder-mount-form';
        if (empty($attrib['id'])) {
            $attrib['id'] = 'folder-mount-form';
        }

        // build form content
        $table        = new html_table(['cols' => 2, 'class' => 'propform']);
        $input_name   = new html_inputfield(['id' => 'folder-mount-name', 'name' => 'name', 'size' => 30]);
        $input_driver = new html_radiobutton(['name' => 'driver', 'size' => 30]);

        $table->add('title', html::label('folder-mount-name', rcube::Q($this->plugin->gettext('name'))));
        $table->add(null, $input_name->show());

        foreach ($sources as $key => $source) {
            $id    = 'source-' . $key;
            $form  = new html_table(['cols' => 2, 'class' => 'propform driverform']);

            foreach ((array) $source['form'] as $idx => $label) {
                $iid = $id . '-' . $idx;
                $type  = stripos($idx, 'pass') !== false ? 'html_passwordfield' : 'html_inputfield';
                $input = new $type(['size' => 30]);

                $form->add('title', html::label($iid, rcube::Q($label)));
                $form->add(null, $input->show('', [
                        'id'   => $iid,
                        'name' => $key . '[' . $idx . ']',
                ]));
            }

            $row = $input_driver->show(null, ['value' => $key])
                . html::img(['src' => $source['image'], 'alt' => $key, 'title' => $source['name']])
                . html::div(
                    null,
                    html::span('name', rcube::Q($source['name']))
                    . html::br()
                    . html::span('description hint', rcube::Q($source['description']))
                    . $form->show()
                );

            $table->add(['id' => $id, 'colspan' => 2, 'class' => 'source'], $row);
        }

        $out = $table->show() . $this->folder_auth_options(['suffix' => '-form']);

        // add form tag around text field
        if (empty($attrib['form'])) {
            $out = $this->rc->output->form_tag($attrib, $out);
        }

        $this->plugin->add_label(
            'foldermounting',
            'foldermountnotice',
            'foldermount',
            'save',
            'cancel',
            'folderauthtitle',
            'authenticating'
        );
        $this->rc->output->add_gui_object('folder-mount-form', $attrib['id']);

        return $out;
    }

    /**
     * Template object for folder authentication options
     */
    public function folder_auth_options($attrib)
    {
        $checkbox = new html_checkbox([
            'name'  => 'store_passwords',
            'value' => '1',
            'class' => 'pretty-checkbox',
        ]);

        return html::div(
            'auth-options',
            html::label(null, $checkbox->show() . ' ' . $this->plugin->gettext('storepasswords'))
            . html::p('description hint', $this->plugin->gettext('storepasswordsdesc'))
        );
    }

    /**
     * Template object for sharing form
     */
    public function folder_share_form($attrib)
    {
        $folder = rcube_utils::get_input_value('_folder', rcube_utils::INPUT_GET, true);

        $info = $this->get_share_info($folder);

        if (empty($info) || empty($info['form'])) {
            $msg = $this->plugin->gettext($info === false ? 'sharepermissionerror' : 'sharestorageerror');
            return html::div(['class' => 'boxerror', 'id' => 'share-notice'], rcube::Q($msg));
        }

        if (empty($attrib['id'])) {
            $attrib['id'] = 'foldershareform';
        }

        $out = '';

        foreach ($info['form'] as $mode => $tab) {
            $table  = new html_table([
                    'cols'        => ($tab['list_column'] ? 1 : count($tab['form'])) + 1,
                    'data-mode'   => $mode,
                    'data-single' => $tab['single'] ? 1 : 0,
            ]);
            $submit = new html_button(['class' => 'btn btn-secondary submit']);
            $delete = new html_button(['class' => 'btn btn-secondary btn-danger delete']);
            $fields = [];

            // Table header
            if (!empty($tab['list_column'])) {
                $table->add_header(null, rcube::Q($tab['list_column_label']));
            } else {
                foreach ($tab['form'] as $field) {
                    $table->add_header(null, rcube::Q($field['title']));
                }
            }
            $table->add_header(null, '');

            // Submit form
            $record = '';
            foreach ($tab['form'] as $index => $field) {
                $add = '';
                if ($field['type'] == 'select') {
                    $ff = new html_select(['name' => $index]);
                    foreach ($field['options'] as $opt_idx => $opt) {
                        $ff->add($opt, $opt_idx);
                    }
                } elseif ($field['type'] == 'password') {
                    $ff = new html_passwordfield([
                            'name'        => $index,
                            'placeholder' => $this->rc->gettext('password'),
                    ]);
                    $add = new html_passwordfield([
                            'name'        => $index . 'confirm',
                            'placeholder' => $this->plugin->gettext('confirmpassword'),
                    ]);
                    $add = $add->show();
                } else {
                    $ff = new html_inputfield([
                            'name'              => $index,
                            'data-autocomplete' => $field['autocomplete'],
                            'placeholder'       => $field['placeholder'],
                    ]);
                }

                if (!empty($tab['list_column'])) {
                    $record .= $ff->show() . $add;
                } else {
                    $table->add(null, $ff->show() . $add);
                }
                $fields[$index] = $ff;
            }

            if (!empty($tab['list_column'])) {
                $table->add('form', $record);
            }

            $hidden = '';
            foreach ((array) $tab['extra_fields'] as $key => $default) {
                $h = new html_hiddenfield(['name' => $key, 'value' => $default]);
                $hidden .= $h->show();
            }

            $table->add(null, $hidden . $submit->show(rcube::Q($tab['label'] ?: $this->plugin->gettext('submit'))));

            // Existing entries
            foreach ((array) $info['rights'] as $entry) {
                if ($entry['mode'] == $mode) {
                    if (!empty($tab['list_column'])) {
                        $table->add(null, html::span(['title' => $entry['title'], 'class' => 'name'], rcube::Q($entry[$tab['list_column']])));
                    } else {
                        foreach ($tab['form'] as $index => $field) {
                            if ($fields[$index] instanceof html_select) {
                                $table->add(null, $fields[$index]->show($entry[$index]));
                            } elseif ($fields[$index] instanceof html_inputfield) {
                                $table->add(null, html::span(['title' => $entry['title'], 'class' => 'name'], rcube::Q($entry[$index])));
                            }
                        }
                    }

                    $hidden = '';
                    foreach ((array) $tab['extra_fields'] as $key => $default) {
                        if (isset($entry[$key])) {
                            $h = new html_hiddenfield(['name' => $key, 'value' => $entry[$key]]);
                            $hidden .= $h->show();
                        }
                    }

                    $table->add(null, $hidden . $delete->show(rcube::Q($this->rc->gettext('delete'))));
                }
            }

            $this->rc->output->add_label('kolab_files.updatingfolder' . $mode);

            $out .= html::tag('fieldset', $mode, html::tag('legend', null, rcube::Q($tab['title'])) . $table->show()) . "\n";
        }

        $this->rc->autocomplete_init();

        $this->rc->output->set_env('folder', $folder);
        $this->rc->output->set_env('form_info', $info['form']);
        $this->rc->output->add_gui_object('shareform', $attrib['id']);
        $this->rc->output->add_label('kolab_files.submit', 'kolab_files.passwordconflict', 'delete');

        return html::div($attrib, $out);
    }

    /**
     * Template object for file edit dialog/warnings
     */
    public function file_edit_dialog($attrib)
    {
        $this->plugin->add_label(
            'select',
            'create',
            'cancel',
            'editfiledialog',
            'editfilesessions',
            'newsession',
            'ownedsession',
            'invitedsession',
            'joinsession',
            'editfilero',
            'editfilerotitle',
            'newsessionro'
        );

        return '<div></div>';
    }

    /**
     * Template object for file session dialog
     */
    public function file_session_dialog($attrib)
    {
        $this->plugin->add_label(
            'join',
            'open',
            'close',
            'request',
            'cancel',
            'sessiondialog',
            'sessiondialogcontent'
        );

        return '<div></div>';
    }

    /**
     * Template object for dcument editors dialog
     */
    public function document_editors_dialog($attrib)
    {
        $table = new html_table($attrib + ['cols' => 3, 'border' => 0, 'cellpadding' => 0]);

        $table->add_header('username', $this->plugin->gettext('participant'));
        $table->add_header('status', $this->plugin->gettext('status'));
        $table->add_header('options', null);

        $input    = new html_inputfield(['name' => 'participant', 'id' => 'invitation-editor-name', 'size' => 30, 'class' => 'form-control']);
        $textarea = new html_textarea(['name' => 'comment', 'id' => 'invitation-comment',
            'rows' => 4, 'cols' => 55, 'class' => 'form-control', 'title' => $this->plugin->gettext('invitationtexttitle')]);
        $button   = new html_inputfield(['type' => 'button', 'class' => 'button', 'id' => 'invitation-editor-add',
            'value' => $this->plugin->gettext('addparticipant')]);

        $this->plugin->add_label('manageeditors', 'statusorganizer', 'addparticipant');

        // initialize attendees autocompletion
        $this->rc->autocomplete_init();

        return html::div(null, $table->show() . html::div(
            null,
            html::div('form-searchbar', $input->show() . " " . $button->show())
            . html::p(
                'attendees-commentbox',
                html::label(
                    null,
                    $this->plugin->gettext('invitationtextlabel') . $textarea->show()
                )
            )
        ));
    }

    /**
     * Template object for file_rename form
     */
    public function file_rename_form($attrib)
    {
        $attrib['name'] = 'file-rename-form';
        if (empty($attrib['id'])) {
            $attrib['id'] = 'file-rename-form';
        }

        $input_name = new html_inputfield(['id' => 'file-rename-name', 'name' => 'name', 'size' => 50]);
        $table      = new html_table(['cols' => 2, 'class' => 'propform']);

        $table->add('title', html::label('file-rename-name', rcube::Q($this->plugin->gettext('filename'))));
        $table->add(null, $input_name->show());

        $out = $table->show();

        // add form tag around text field
        if (empty($attrib['form'])) {
            $out = $this->rc->output->form_tag($attrib, $out);
        }

        $this->plugin->add_label('save', 'cancel', 'fileupdating', 'renamefile');
        $this->rc->output->add_gui_object('file-rename-form', $attrib['id']);

        return $out;
    }

    /**
     * Template object for file_create form
     */
    public function file_create_form($attrib)
    {
        $attrib['name'] = 'file-create-form';
        if (empty($attrib['id'])) {
            $attrib['id'] = 'file-create-form';
        }

        $input_name    = new html_inputfield(['id' => 'file-create-name', 'name' => 'name', 'size' => 30]);
        $select_parent = new html_select(['id' => 'file-create-parent', 'name' => 'parent']);
        $select_type   = new html_select(['id' => 'file-create-type', 'name' => 'type']);
        $table         = new html_table(['cols' => 2, 'class' => 'propform']);

        $types = [];

        foreach ($this->get_mimetypes('edit') as $type => $mimetype) {
            $types[$type] = $mimetype['ext'];
            $select_type->add($mimetype['label'], $type);
        }

        $table->add('title', html::label('file-create-name', rcube::Q($this->plugin->gettext('filename'))));
        $table->add(null, $input_name->show());
        $table->add('title', html::label('file-create-type', rcube::Q($this->plugin->gettext('type'))));
        $table->add(null, $select_type->show());
        $table->add('title', html::label('file-create-parent', rcube::Q($this->plugin->gettext('folderinside'))));
        $table->add(null, $select_parent->show());

        $out = $table->show();

        // add form tag around text field
        if (empty($attrib['form'])) {
            $out = $this->rc->output->form_tag($attrib, $out);
        }

        $this->plugin->add_label(
            'create',
            'cancel',
            'filecreating',
            'createfile',
            'createandedit',
            'copyfile',
            'copyandedit'
        );
        $this->rc->output->add_gui_object('file-create-form', $attrib['id']);
        $this->rc->output->set_env('file_extensions', $types);

        return $out;
    }

    /**
     * Template object for file search form in "From cloud" dialog
     */
    public function file_search_form($attrib)
    {
        $attrib += [
            'name'          => '_q',
            'gui-object'    => 'filesearchbox',
            'form-name'     => 'filesearchform',
            'command'       => 'files-search',
            'reset-command' => 'files-search-reset',
        ];

        // add form tag around text field
        return $this->rc->output->search_form($attrib);
    }

    /**
     * Template object for files list
     */
    public function file_list($attrib)
    {
        return $this->list_handler($attrib, 'files');
    }

    /**
     * Template object for sessions list
     */
    public function sessions_list($attrib)
    {
        return $this->list_handler($attrib, 'sessions');
    }

    /**
     * Creates unified template object for files|sessions list
     */
    protected function list_handler($attrib, $type = 'files')
    {
        $prefix   = 'kolab_' . $type . '_';
        $c_prefix = 'kolab_files' . ($type != 'files' ? '_' . $type : '') . '_';

        // define list of cols to be displayed based on parameter or config
        if (empty($attrib['columns'])) {
            $list_cols     = $this->rc->config->get($c_prefix . 'list_cols');
            $dont_override = $this->rc->config->get('dont_override');
            $a_show_cols = is_array($list_cols) ? $list_cols : ['name'];
            $this->rc->output->set_env($type . '_col_movable', !in_array($c_prefix . 'list_cols', (array)$dont_override));
        } else {
            $columns     = str_replace(["'", '"'], '', $attrib['columns']);
            $a_show_cols = preg_split('/[\s,;]+/', $columns);
        }

        // make sure 'name' and 'options' column is present
        if (!in_array('name', $a_show_cols)) {
            array_unshift($a_show_cols, 'name');
        }
        if (!in_array('options', $a_show_cols)) {
            array_unshift($a_show_cols, 'options');
        }

        $attrib['columns'] = $a_show_cols;

        // save some variables for use in ajax list
        $_SESSION[$prefix . 'list_attrib'] = $attrib;

        // For list in dialog(s) remove all option-like columns
        if ($this->rc->task != 'files') {
            $a_show_cols = array_intersect($a_show_cols, $this->{$type . '_sort_cols'});
        }

        // set default sort col/order to session
        if (!isset($_SESSION[$prefix . 'sort_col'])) {
            $_SESSION[$prefix . 'sort_col'] = $this->rc->config->get($c_prefix . 'sort_col') ?: 'name';
        }
        if (!isset($_SESSION[$prefix . 'sort_order'])) {
            $_SESSION[$prefix . 'sort_order'] = strtoupper($this->rc->config->get($c_prefix . 'sort_order') ?: 'asc');
        }

        // set client env
        $this->rc->output->add_gui_object($type . 'list', $attrib['id']);
        $this->rc->output->set_env($type . '_sort_col', $_SESSION[$prefix . 'sort_col']);
        $this->rc->output->set_env($type . '_sort_order', $_SESSION[$prefix . 'sort_order']);
        $this->rc->output->set_env($type . '_coltypes', $a_show_cols);

        $this->rc->output->include_script('list.js');

        $this->rc->output->add_label('kolab_files.abort', 'searching');

        // attach css rules for mimetype icons
        if (!$this->filetypes_style) {
            $this->plugin->include_stylesheet($this->url . '/skins/default/images/mimetypes/style.css');
            $this->filetypes_style = true;
        }

        $thead = '';
        foreach ($this->list_head($attrib, $a_show_cols, $type) as $cell) {
            $thead .= html::tag('th', ['class' => $cell['className'], 'id' => $cell['id']], $cell['html']);
        }

        return html::tag(
            'table',
            $attrib,
            html::tag('thead', null, html::tag('tr', null, $thead)) . html::tag('tbody', null, ''),
            ['style', 'class', 'id', 'cellpadding', 'cellspacing', 'border', 'summary']
        );
    }

    /**
     * Creates <THEAD> for message list table
     */
    protected function list_head($attrib, $a_show_cols, $type = 'files')
    {
        $prefix    = 'kolab_' . $type . '_';
        $c_prefix  = 'kolab_files_' . ($type != 'files' ? $type : '') . '_';
        $skin_path = $_SESSION['skin_path'] ?? null;

        // check to see if we have some settings for sorting
        $sort_col   = $_SESSION[$prefix . 'sort_col'];
        $sort_order = $_SESSION[$prefix . 'sort_order'];

        $dont_override  = (array)$this->rc->config->get('dont_override');
        $disabled_sort  = in_array($c_prefix . 'sort_col', $dont_override);
        $disabled_order = in_array($c_prefix . 'sort_order', $dont_override);

        $this->rc->output->set_env($prefix . 'disabled_sort_col', $disabled_sort);
        $this->rc->output->set_env($prefix . 'disabled_sort_order', $disabled_order);

        // define sortable columns
        if ($disabled_sort) {
            $a_sort_cols = $sort_col && !$disabled_order ? [$sort_col] : [];
        } else {
            $a_sort_cols = $this->{$type . '_sort_cols'};
        }

        if (!empty($attrib['optionsmenuicon'])) {
            $onclick = 'return ' . rcmail_output::JS_OBJECT_NAME . ".command('menu-open', '{$type}listmenu', this, event)";
            $inner   = $this->rc->gettext('listoptions');

            if (is_string($attrib['optionsmenuicon']) && $attrib['optionsmenuicon'] != 'true') {
                $inner = html::img(['src' => $skin_path . $attrib['optionsmenuicon'], 'alt' => $this->rc->gettext('listoptions')]);
            }

            $list_menu = html::a([
                'href'     => '#list-options',
                'onclick'  => $onclick,
                'class'    => 'listmenu',
                'id'       => $type . 'listmenulink',
                'title'    => $this->rc->gettext('listoptions'),
                'tabindex' => '0',
            ], $inner);
        } else {
            $list_menu = '';
        }

        $cells = [];

        foreach ($a_show_cols as $col) {
            // sanity check
            if (!preg_match('/^[a-zA-Z_-]+$/', $col)) {
                continue;
            }

            // get column name
            switch ($col) {
                case 'options':
                    $col_name = $list_menu;
                    break;
                default:
                    $col_name = rcube::Q($this->plugin->gettext($col));
            }

            // make sort links
            if (in_array($col, $a_sort_cols)) {
                $col_name = html::a([
                        'href'    => "#sort",
                        'onclick' => 'return ' . rcmail_output::JS_OBJECT_NAME . ".command('$type-sort','$col',this)",
                        'title'   => $this->plugin->gettext('sortby'),
                    ], $col_name);
            } elseif (empty($col_name) || $col_name[0] != '<') {
                $col_name = '<span class="' . $col . '">' . $col_name . '</span>';
            }

            $sort_class = $col == $sort_col && !$disabled_order ? " sorted$sort_order" : '';
            $class_name = $col . $sort_class;

            // put it all together
            $cells[] = ['className' => $class_name, 'id' => "rcm$col", 'html' => $col_name];
        }

        return $cells;
    }

    /**
     * Update files|sessions list object
     */
    protected function list_update($prefs, $type = 'files')
    {
        $prefix   = 'kolab_' . $type . '_list_';
        $c_prefix = 'kolab_files' . ($type != 'files' ? '_' . $type : '') . '_list_';
        $attrib   = $_SESSION[$prefix . 'attrib'];

        if (!empty($prefs[$c_prefix . 'cols'])) {
            $attrib['columns'] = $prefs[$c_prefix . 'cols'];
            $_SESSION[$prefix . 'attrib'] = $attrib;
        }

        $a_show_cols = $attrib['columns'];
        $head        = '';

        foreach ($this->list_head($attrib, $a_show_cols, $type) as $cell) {
            $head .= html::tag('th', ['class' => $cell['className'], 'id' => $cell['id']], $cell['html']);
        }

        $head = html::tag('tr', null, $head);

        $this->rc->output->set_env($type . '_coltypes', $a_show_cols);
        $this->rc->output->command($type . '_list_update', $head);
    }

    /**
     * Template object for file info box
     */
    public function file_info_box($attrib)
    {
        // print_r($this->file_data, true);
        $table = new html_table(['cols' => 2, 'class' => $attrib['class']]);

        // file name
        $table->add('title', $this->plugin->gettext('name') . ':');
        $table->add('data filename', $this->file_data['name']);

        // file type
        // @TODO: human-readable type name
        $table->add('title', $this->plugin->gettext('type') . ':');
        $table->add('data filetype', $this->file_data['type']);

        // file size
        $table->add('title', $this->plugin->gettext('size') . ':');
        $table->add('data filesize', $this->rc->show_bytes($this->file_data['size']));

        // file modification time
        $table->add('title', $this->plugin->gettext('mtime') . ':');
        $table->add('data filemtime', $this->file_data['mtime']);

        // @TODO: for images: width, height, color depth, etc.
        // @TODO: for text files: count of characters, lines, words

        return $table->show();
    }

    /**
     * Template object for file preview frame
     */
    public function file_preview_frame($attrib)
    {
        if (empty($attrib['id'])) {
            $attrib['id'] = 'filepreviewframe';
        }

        if ($frame = ($this->file_data['viewer']['frame'] ?? null)) {
            return $frame;
        }

        if ($href = ($this->file_data['viewer']['href'] ?? null)) {
            // file href attribute must be an absolute URL (Bug #2063)
            if (!preg_match('|^https?://|', $href)) {
                $href = $this->url . '/api/' . $href;
            }
        } else {
            $token = $this->get_api_token();
            $href  = $this->url . '/api/?method=file_get'
                . '&file=' . urlencode($this->file_data['filename'])
                . '&token=' . urlencode($token);
        }

        $this->rc->output->add_gui_object('preview_frame', $attrib['id']);

        $attrib['allowfullscreen'] = true;
        $attrib['src']             = $href;
        $attrib['onload']          = 'kolab_files_frame_load(this)';

        $form = '';

        // editor requires additional arguments via POST
        if (!empty($this->file_data['viewer']['post'])) {
            $attrib['src'] = 'program/resources/blank.gif';

            $form_content = new html_hiddenfield();
            $form_attrib  = [
                'action' => $href,
                'id'     => $attrib['id'] . '-form',
                'target' => $attrib['name'],
                'method' => 'post',
            ];

            foreach ($this->file_data['viewer']['post'] as $name => $value) {
                $form_content->add(['name' => $name, 'value' => $value]);
            }

            $form = html::tag('form', $form_attrib, $form_content->show())
                . html::script([], "\$('#{$attrib['id']}-form').submit()");
        }

        return html::iframe($attrib) . $form;
    }

    /**
     * Template object for quota display
     */
    public function quota_display($attrib)
    {
        if (empty($attrib['id'])) {
            $attrib['id'] = 'rcmquotadisplay';
        }

        $quota_type = !empty($attrib['display']) ? $attrib['display'] : 'text';

        $this->rc->output->add_gui_object('quotadisplay', $attrib['id']);
        $this->rc->output->set_env('quota_type', $quota_type);

        // get quota
        $token   = $this->get_api_token();
        $request = $this->get_request(['method' => 'quota'], $token);

        // send request to the API
        try {
            $response = $request->send();
            $status   = $response->getStatus();
            $body     = @json_decode($response->getBody(), true);

            if ($status == 200 && $body['status'] == 'OK') {
                $quota = $body['result'];
            } else {
                throw new Exception($body['reason'] ?: "Failed to get quota. Status: $status");
            }
        } catch (Exception $e) {
            rcube::raise_error($e, true, false);
            $quota = ['total' => 0, 'percent' => 0];
        }

        $quota = rcube_output::json_serialize($quota);

        $this->rc->output->add_script(rcmail_output::JS_OBJECT_NAME . ".files_set_quota($quota);", 'docready');

        return html::span($attrib, '');
    }

    /**
     * Get API token for current user session, authenticate if needed
     */
    public function get_api_token($configure = true)
    {
        $token = $_SESSION['kolab_files_token'] ?? null;
        $time  = $_SESSION['kolab_files_time'] ?? null;

        if ($token && time() - $this->timeout < $time) {
            if (time() - $time <= $this->timeout / 2) {
                return $token;
            }
        }

        $request = $this->get_request(['method' => 'ping'], $token);

        try {
            $url = $request->getUrl();

            // Send ping request
            if ($token) {
                $url->setQueryVariables(['method' => 'ping']);
                $request->setUrl($url);
                $response = $request->send();
                $status   = $response->getStatus();

                if ($status == 200 && ($body = json_decode($response->getBody(), true))) {
                    if ($body['status'] == 'OK') {
                        $_SESSION['kolab_files_time']  = time();
                        return $token;
                    }
                }
            }

            // Go with authenticate request
            $url->setQueryVariables(['method' => 'authenticate', 'version' => self::API_VERSION]);
            $request->setUrl($url);
            $request->setAuth($this->rc->user->get_username(), $this->rc->decrypt($_SESSION['password']));

            // Allow plugins (e.g. kolab_sso) to modify the request
            $this->rc->plugins->exec_hook('chwala_authenticate', ['request' => $request]);

            $response = $request->send();
            $status   = $response->getStatus();

            if ($status == 200 && ($body = json_decode($response->getBody(), true))) {
                $token = $body['result']['token'];

                if ($token) {
                    $_SESSION['kolab_files_token'] = $token;
                    $_SESSION['kolab_files_time']  = time();
                    $_SESSION['kolab_files_caps']  = $body['result']['capabilities'];
                }
            } else {
                throw new Exception(sprintf("Authenticate error (Status: %d)", $status));
            }

            // Configure session
            if ($configure && $token) {
                $this->configure($token);
            }
        } catch (Exception $e) {
            rcube::raise_error($e, true, false);
        }

        return $token;
    }

    protected function capabilities()
    {
        if (empty($_SESSION['kolab_files_caps'])) {
            $token = $this->get_api_token();

            if (empty($_SESSION['kolab_files_caps'])) {
                $request = $this->get_request(['method' => 'capabilities'], $token);

                // send request to the API
                try {
                    $response = $request->send();
                    $status   = $response->getStatus();
                    $body     = @json_decode($response->getBody(), true);

                    if (!$body) {
                        throw new Exception("Failed to get capabilities. No body returned");
                    }

                    if ($status == 200 && $body['status'] == 'OK') {
                        $_SESSION['kolab_files_caps'] = $body['result'];
                    } else {
                        throw new Exception($body['reason'] ?: "Failed to get capabilities. Status: $status");
                    }
                } catch (Exception $e) {
                    rcube::raise_error($e, true, false);
                    return [];
                }
            }
        }

        if (!empty($_SESSION['kolab_files_caps']['MANTICORE']) || !empty($_SESSION['kolab_files_caps']['WOPI'])) {
            $_SESSION['kolab_files_caps']['DOCEDIT'] = true;
            $_SESSION['kolab_files_caps']['DOCTYPE'] = !empty($_SESSION['kolab_files_caps']['WOPI']) ? 'wopi' : 'manticore';
        }

        if (!empty($_SESSION['kolab_files_caps']) && !isset($_SESSION['kolab_files_caps']['MOUNTPOINTS'])) {
            $_SESSION['kolab_files_caps']['MOUNTPOINTS'] = [];
        }

        return $_SESSION['kolab_files_caps'];
    }

    /**
     * Initialize HTTP_Request object
     */
    protected function get_request($get = null, $token = null)
    {
        $url = $this->url_srv . '/api/';

        if (empty($this->request)) {
            $config = [
                'store_body'       => true,
                'follow_redirects' => true,
            ];

            $this->request = libkolab::http_request($url, 'GET', $config);
        } else {
            // cleanup
            try {
                $this->request->setBody('');
                $this->request->setUrl($url);
                $this->request->setMethod(HTTP_Request2::METHOD_GET);
            } catch (Exception $e) {
                rcube::raise_error($e, true, true);
            }
        }

        if ($token) {
            $this->request->setHeader('X-Session-Token', $token);
        }

        if (!empty($get)) {
            $url = $this->request->getUrl();
            $url->setQueryVariables($get);
            $this->request->setUrl($url);
        }

        // some HTTP server configurations require this header
        $this->request->setHeader('accept', "application/json,text/javascript,*/*");

        // Localization
        $this->request->setHeader('accept-language', $_SESSION['language']);

        // set Referer which is used as an origin for cross-window
        // communication with document editor iframe
        $host = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'];
        $this->request->setHeader('referer', $host);

        return $this->request;
    }

    /**
     * Configure chwala session
     */
    public function configure($token = null, $prefs = [])
    {
        if (!$token) {
            $token = $this->get_api_token(false);
        }

        try {
            // Configure session
            $query = [
                'method'      => 'configure',
                'timezone'    => $prefs['timezone'] ?? $this->rc->config->get('timezone'),
                'date_format' => $prefs['date_long'] ?? $this->rc->config->get('date_long', 'Y-m-d H:i'),
            ];

            $request  = $this->get_request($query, $token);
            $response = $request->send();
            $status   = $response->getStatus();

            if ($status != 200) {
                throw new Exception(sprintf("Failed to configure chwala session (Status: %d)", $status));
            }
        } catch (Exception $e) {
            rcube::raise_error($e, true, false);
        }
    }

    /**
     * Handler for main files interface (Files task)
     */
    protected function action_index()
    {
        $this->plugin->add_label(
            'uploading',
            'attaching',
            'uploadsizeerror',
            'filedeleting',
            'filedeletenotice',
            'filedeleteconfirm',
            'filemoving',
            'filemovenotice',
            'filemoveconfirm',
            'filecopying',
            'filecopynotice',
            'fileskip',
            'fileskipall',
            'fileoverwrite',
            'fileoverwriteall'
        );

        $this->folder_list_env();

        if ($this->rc->task == 'files') {
            $this->rc->output->set_env('folder', rcube_utils::get_input_value('folder', rcube_utils::INPUT_GET));
            $this->rc->output->set_env('collection', rcube_utils::get_input_value('collection', rcube_utils::INPUT_GET));
        }

        $caps = $this->capabilities();

        $this->rc->output->add_label('uploadprogress', 'GB', 'MB', 'KB', 'B');
        $this->rc->output->set_pagetitle($this->plugin->gettext('files'));
        $this->rc->output->set_env('file_mimetypes', $this->get_mimetypes());
        $this->rc->output->set_env('files_quota', $caps['QUOTA'] ?? null);
        $this->rc->output->set_env('files_max_upload', $caps['MAX_UPLOAD'] ?? null);
        $this->rc->output->set_env('files_progress_name', $caps['PROGRESS_NAME'] ?? null);
        $this->rc->output->set_env('files_progress_time', $caps['PROGRESS_TIME'] ?? null);
        $this->rc->output->send('kolab_files.files');
    }

    /**
     * Handler for resetting some session/cached information
     */
    protected function action_reset()
    {
        $this->rc->session->remove('kolab_files_caps');
        $caps = $this->capabilities();
        if (!empty($caps)) {
            $this->rc->output->set_env('files_caps', $caps);
        }
    }

    /**
     * Handler for preferences save action
     */
    protected function action_prefs()
    {
        $dont_override = (array)$this->rc->config->get('dont_override');
        $prefs = [];
        $type  = rcube_utils::get_input_value('type', rcube_utils::INPUT_POST);
        $opts  = [
            'kolab_files_sort_col'   => true,
            'kolab_files_sort_order' => true,
            'kolab_files_list_cols'  => false,
        ];

        foreach ($opts as $o => $sess) {
            if (isset($_POST[$o])) {
                $value       = rcube_utils::get_input_value($o, rcube_utils::INPUT_POST);
                $session_key = $o;
                $config_key  = $o;

                if ($type != 'files') {
                    $config_key = str_replace('files', 'files_' . $type, $config_key);
                }

                if (in_array($config_key, $dont_override)) {
                    continue;
                }

                if ($o == 'kolab_files_list_cols') {
                    $update_list = true;
                }

                $prefs[$config_key] = $value;
                if ($sess) {
                    $_SESSION[$session_key] = $prefs[$config_key];
                }
            }
        }

        // save preference values
        if (!empty($prefs)) {
            $this->rc->user->save_prefs($prefs);
        }

        if (!empty($update_list)) {
            $this->list_update($prefs, $type);
        }

        $this->rc->output->send();
    }

    /**
     * Handler for file open action
     */
    protected function action_open()
    {
        $this->rc->output->set_env('file_mimetypes', $this->get_mimetypes());

        $this->file_opener(intval($_GET['_viewer']) & ~4);
    }

    /**
     * Handler for file open action
     */
    protected function action_edit()
    {
        $this->plugin->add_label(
            'sessionterminating',
            'unsavedchanges',
            'documentinviting',
            'documentcancelling',
            'removeparticipant',
            'sessionterminated',
            'sessionterminatedtitle'
        );

        $this->file_opener(intval($_GET['_viewer']));
    }

    /**
     * Handler for folder sharing action
     */
    protected function action_share()
    {
        $this->rc->output->add_handler('share-form', [$this, 'folder_share_form']);

        $this->rc->output->send('kolab_files.share');
    }

    /**
     * Handler for "save all attachments into cloud" action
     */
    protected function action_save_file()
    {
        //        $source = rcube_utils::get_input_value('source', rcube_utils::INPUT_POST);
        $uid    = rcube_utils::get_input_value('uid', rcube_utils::INPUT_POST);
        $dest   = rcube_utils::get_input_value('dest', rcube_utils::INPUT_POST);
        $id     = rcube_utils::get_input_value('id', rcube_utils::INPUT_POST);
        $name   = rcube_utils::get_input_value('name', rcube_utils::INPUT_POST);

        $temp_dir = unslashify($this->rc->config->get('temp_dir'));
        $message  = new rcube_message($uid);
        $request  = $this->get_request();
        $url      = $request->getUrl();
        $files    = [];
        $errors   = [];
        $attachments = [];

        $request->setMethod(HTTP_Request2::METHOD_POST);
        $request->setHeader('X-Session-Token', $this->get_api_token());
        $url->setQueryVariables(['method' => 'file_upload', 'folder' => $dest]);
        $request->setUrl($url);

        foreach ($message->attachments as $attach_prop) {
            if (empty($id) || $id == $attach_prop->mime_id) {
                $filename = strlen($name) ? $name : rcmail_action_mail_index::attachment_name($attach_prop, true);
                $attachments[$filename] = $attach_prop;
            }
        }

        // @TODO: handle error
        // @TODO: implement file upload using file URI instead of body upload

        foreach ($attachments as $attach_name => $attach_prop) {
            $path = tempnam($temp_dir, 'rcmAttmnt');

            // save attachment to file
            if ($fp = fopen($path, 'w+')) {
                $message->get_part_body($attach_prop->mime_id, false, 0, $fp);
            } else {
                $errors[] = true;
                rcube::raise_error(
                    [
                    'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                    'message' => "Unable to save attachment into file $path"],
                    true,
                    false
                );
                continue;
            }

            fclose($fp);

            // send request to the API
            try {
                $request->setBody('');
                $request->addUpload('file[]', $path, $attach_name, $attach_prop->mimetype);
                $response = $request->send();
                $status   = $response->getStatus();
                $body     = @json_decode($response->getBody(), true);

                if ($status == 200 && $body['status'] == 'OK') {
                    $files[] = $attach_name;
                } else {
                    throw new Exception($body['reason'] ?: "Failed to post file_upload. Status: $status");
                }
            } catch (Exception $e) {
                unlink($path);
                $errors[] = $e->getMessage();
                rcube::raise_error(
                    [
                    'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                    'message' => $e->getMessage()],
                    true,
                    false
                );
                continue;
            }

            // clean up
            unlink($path);
            $request->setBody('');
        }

        if ($count = count($files)) {
            $msg = $this->plugin->gettext(['name' => 'saveallnotice', 'vars' => ['n' => $count]]);
            $this->rc->output->show_message($msg, 'confirmation');
        }
        if ($count = count($errors)) {
            $msg = $this->plugin->gettext(['name' => 'saveallerror', 'vars' => ['n' => $count]]);
            $this->rc->output->show_message($msg, 'error');
        }

        // @TODO: update quota indicator, make this optional in case files aren't stored in IMAP

        $this->rc->output->send();
    }

    /**
     * Handler for "add attachments from the cloud" action
     */
    protected function action_attach_file()
    {
        $files       = rcube_utils::get_input_value('files', rcube_utils::INPUT_POST);
        $uploadid    = rcube_utils::get_input_value('uploadid', rcube_utils::INPUT_POST);
        $COMPOSE_ID  = rcube_utils::get_input_value('id', rcube_utils::INPUT_POST);
        $COMPOSE     = null;
        $errors      = [];
        $attachments = [];

        if ($this->rc->task == 'mail') {
            if ($COMPOSE_ID && $_SESSION['compose_data_' . $COMPOSE_ID]) {
                $COMPOSE = & $_SESSION['compose_data_' . $COMPOSE_ID];
            }

            if (!$COMPOSE) {
                die("Invalid session var!");
            }

            // attachment upload action
            if (!is_array($COMPOSE['attachments'])) {
                $COMPOSE['attachments'] = [];
            }
        }

        // clear all stored output properties (like scripts and env vars)
        $this->rc->output->reset();

        $temp_dir = unslashify($this->rc->config->get('temp_dir'));
        $request  = $this->get_request();
        $url      = $request->getUrl();

        // Use observer object to store HTTP response into a file
        require_once $this->plugin->home . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'kolab_files_observer.php';
        $observer = new kolab_files_observer();

        $request->setHeader('X-Session-Token', $this->get_api_token());

        // download files from the API and attach them
        foreach ($files as $file) {
            // decode filename
            $file = urldecode($file);

            // get file information
            try {
                $url->setQueryVariables(['method' => 'file_info', 'file' => $file]);
                $request->setUrl($url);
                $response = $request->send();
                $status   = $response->getStatus();
                $body     = @json_decode($response->getBody(), true);

                if ($status == 200 && $body['status'] == 'OK') {
                    $file_params = $body['result'];
                } else {
                    throw new Exception($body['reason'] ?: "Failed to get file_info. Status: $status");
                }
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
                rcube::raise_error(
                    [
                    'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                    'message' => $e->getMessage()],
                    true,
                    false
                );
                continue;
            }

            // set location of downloaded file
            $path = tempnam($temp_dir, 'rcmAttmnt');
            $observer->set_file($path);

            // download file
            try {
                $url->setQueryVariables(['method' => 'file_get', 'file' => $file]);
                $request->setUrl($url);
                $request->attach($observer);
                $response = $request->send();
                $status   = $response->getStatus();
                $response->getBody(); // returns nothing
                $request->detach($observer);

                if ($status != 200 || !file_exists($path)) {
                    throw new Exception("Unable to save file");
                }
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
                rcube::raise_error(
                    [
                    'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                    'message' => $e->getMessage()],
                    true,
                    false
                );
                continue;
            }

            $attachment = [
                'path'     => $path,
                'size'     => $file_params['size'],
                'name'     => $file_params['name'],
                'mimetype' => $file_params['type'],
                'group'    => $COMPOSE_ID,
            ];

            if ($this->rc->task != 'mail') {
                $attachments[] = $attachment;
                continue;
            }

            $attachment = $this->rc->plugins->exec_hook('attachment_save', $attachment);

            if ($attachment['status'] && !$attachment['abort']) {
                $this->compose_attach_success($attachment, $COMPOSE, $COMPOSE_ID, $uploadid);
            } elseif ($attachment['error']) {
                $errors[] = $attachment['error'];
            } else {
                $errors[] = $this->plugin->gettext('attacherror');
            }
        }

        if (!empty($errors)) {
            $this->rc->output->command('display_message', $this->plugin->gettext('attacherror'), 'error');
            $this->rc->output->command('remove_from_attachment_list', $uploadid);
        } elseif ($this->rc->task == 'calendar' || $this->rc->task == 'tasks') {
            // for uploads in events/tasks we'll use its standard upload handler,
            // for this we have to fake $_FILES and some other POST args
            foreach ($attachments as $attach) {
                $_FILES['_attachments']['tmp_name'][] = $attach['path'];
                $_FILES['_attachments']['name'][]     = $attach['name'];
                $_FILES['_attachments']['size'][]     = $attach['size'];
                $_FILES['_attachments']['type'][]     = $attach['mimetype'];
                $_FILES['_attachments']['error'][]    = null;
            }

            $_GET['_uploadid'] = $uploadid;
            $_GET['_id']       = $COMPOSE_ID;

            switch ($this->rc->task) {
                case 'tasks':
                    $handler = new kolab_attachments_handler();
                    $handler->attachment_upload(tasklist::SESSION_KEY);
                    break;

                case 'calendar':
                    $handler = new kolab_attachments_handler();
                    $handler->attachment_upload(calendar::SESSION_KEY, 'cal-');
                    break;
            }
        }

        // send html page with JS calls as response
        $this->rc->output->command('auto_save_start', false);
        $this->rc->output->send();
    }

    protected function compose_attach_success($attachment, $COMPOSE, $COMPOSE_ID, $uploadid)
    {
        if (empty($attachment['id'])) {
            return;
        }

        $id = $attachment['id'];

        // store new attachment in session
        unset($attachment['data'], $attachment['status'], $attachment['abort']);
        $this->rc->session->append('compose_data_' . $COMPOSE_ID . '.attachments', $id, $attachment);

        if (($icon = $COMPOSE['deleteicon']) && is_file($icon)) {
            $button = html::img([
                'src' => $icon,
                'alt' => $this->rc->gettext('delete'),
            ]);
        } elseif ($COMPOSE['textbuttons']) {
            $button = rcube::Q($this->rc->gettext('delete'));
        } else {
            $button = '';
        }

        if (version_compare(version_parse(RCMAIL_VERSION), '1.3.0', '>=')) {
            $link_content = sprintf(
                '%s <span class="attachment-size"> (%s)</span>',
                rcube::Q($attachment['name']),
                $this->rc->show_bytes($attachment['size'])
            );

            $content_link = html::a([
                    'href'    => "#load",
                    'class'   => 'filename',
                    'onclick' => sprintf("return %s.command('load-attachment','rcmfile%s', this, event)", rcmail_output::JS_OBJECT_NAME, $id),
                ], $link_content);

            $delete_link = html::a([
                    'href'    => "#delete",
                    'onclick' => sprintf("return %s.command('remove-attachment','rcmfile%s', this, event)", rcmail_output::JS_OBJECT_NAME, $id),
                    'title'   => $this->rc->gettext('delete'),
                    'class'   => 'delete',
                    'aria-label' => $this->rc->gettext('delete') . ' ' . $attachment['name'],
                ], $button);

            $content = $COMPOSE['icon_pos'] == 'left' ? $delete_link . $content_link : $content_link . $delete_link;
        } else {
            $content = html::a([
                    'href'    => "#delete",
                    'onclick' => sprintf("return %s.command('remove-attachment','rcmfile%s', this)", rcmail_output::JS_OBJECT_NAME, $id),
                    'title'   => $this->rc->gettext('delete'),
                    'class'   => 'delete',
            ], $button);

            $content .= rcube::Q($attachment['name']);
        }

        $this->rc->output->command('add2attachment_list', "rcmfile$id", [
            'html'      => $content,
            'name'      => $attachment['name'],
            'mimetype'  => $attachment['mimetype'],
            'classname' => rcube_utils::file2class($attachment['mimetype'], $attachment['name']),
            'complete'  => true], $uploadid);
    }

    /**
     * Handler for file open/edit action
     */
    protected function file_opener($viewer)
    {
        $file    = rcube_utils::get_input_value('_file', rcube_utils::INPUT_GET);
        $session = rcube_utils::get_input_value('_session', rcube_utils::INPUT_GET);

        // get file info
        $token   = $this->get_api_token();
        $request = $this->get_request([
            'method'  => 'file_info',
            'file'    => $file,
            'viewer'  => $viewer,
            'session' => $session,
            ], $token);

        // send request to the API
        try {
            $response = $request->send();
            $status   = $response->getStatus();
            $body     = @json_decode($response->getBody(), true);

            if ($status == 200 && $body['status'] == 'OK') {
                $this->file_data = $body['result'];
            } else {
                throw new Exception($body['reason'] ?: "Failed to get file_info. Status: $status");
            }
        } catch (Exception $e) {
            rcube::raise_error(
                [
                'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                'message' => $e->getMessage()],
                true,
                true
            );
        }

        if ($file === null || $file === '') {
            $file = $this->file_data['file'];
        }

        $this->file_data['filename'] = $file;

        $this->plugin->add_label('filedeleteconfirm', 'filedeleting', 'filedeletenotice', 'terminate');

        // register template objects for dialogs (and main interface)
        $this->rc->output->add_handlers([
            'fileinfobox'      => [$this, 'file_info_box'],
            'filepreviewframe' => [$this, 'file_preview_frame'],
        ]);

        $placeholder = $this->rc->output->asset_url('program/resources/blank.gif');

        $editor_type = null;
        $got_editor = null;
        if (!empty($this->file_data['viewer']['wopi'])) {
            $editor_type = 'wopi';
            $got_editor  = ($viewer & 4);
        } elseif (!empty($this->file_data['viewer']['manticore'])) {
            $editor_type = 'manticore';
            $got_editor = ($viewer & 4);
        }

        // this one is for styling purpose
        $this->rc->output->set_env('extwin', true);
        $this->rc->output->set_env('file', $file);
        $this->rc->output->set_env('file_data', $this->file_data);
        $this->rc->output->set_env('mimetype', $this->file_data['type']);
        $this->rc->output->set_env('filename', pathinfo($file, PATHINFO_BASENAME));
        $this->rc->output->set_env('editor_type', $editor_type);
        $this->rc->output->set_env('photo_placeholder', $placeholder);
        $this->rc->output->set_pagetitle(rcube::Q($file));
        $this->rc->output->send('kolab_files.' . ($got_editor ? 'docedit' : 'filepreview'));
    }

    /**
     * Returns mimetypes supported by File API viewers
     */
    protected function get_mimetypes($type = 'view')
    {
        $mimetypes = [];

        // send request to the API
        try {
            if ($this->mimetypes === null) {
                $this->mimetypes = false;

                $token    = $this->get_api_token();
                $caps     = $this->capabilities();
                $request  = $this->get_request(['method' => 'mimetypes'], $token);
                $response = $request->send();
                $status   = $response->getStatus();
                $body     = @json_decode($response->getBody(), true);

                if ($status == 200 && $body['status'] == 'OK') {
                    $this->mimetypes = $body['result'];
                } else {
                    throw new Exception($body['reason'] ?: "Failed to get mimetypes. Status: $status");
                }
            }

            if (is_array($this->mimetypes)) {
                if (array_key_exists($type, $this->mimetypes)) {
                    $mimetypes = $this->mimetypes[$type];
                }
                // fallback to static definition if old Chwala is used
                elseif ($type == 'edit') {
                    $mimetypes = [
                        'text/plain' => 'txt',
                        'text/html'  => 'html',
                    ];
                    if (!empty($caps['MANTICORE'])) {
                        $mimetypes = array_merge(['application/vnd.oasis.opendocument.text' => 'odt'], $mimetypes);
                    }

                    foreach (array_keys($mimetypes) as $type) {
                        [$app, $label] = explode('/', $type);
                        $label = preg_replace('/[^a-z]/', '', $label);
                        $mimetypes[$type] = [
                            'ext'   => $mimetypes[$type],
                            'label' => $this->plugin->gettext('type.' . $label),
                        ];
                    }
                } else {
                    $mimetypes = $this->mimetypes;
                }
            }
        } catch (Exception $e) {
            rcube::raise_error(
                [
                'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                'message' => $e->getMessage()],
                true,
                false
            );
        }

        return $mimetypes;
    }

    /**
     * Get list of available external storage drivers
     */
    protected function get_external_storage_drivers()
    {
        // first get configured sources from Chwala
        $token   = $this->get_api_token();
        $request = $this->get_request(['method' => 'folder_types'], $token);

        // send request to the API
        try {
            $response = $request->send();
            $status   = $response->getStatus();
            $body     = @json_decode($response->getBody(), true);

            if ($status == 200 && $body['status'] == 'OK') {
                $sources = $body['result'];
            } else {
                throw new Exception($body['reason'] ?: "Failed to get folder_types. Status: $status");
            }
        } catch (Exception $e) {
            rcube::raise_error($e, true, false);
            return;
        }

        $this->rc->output->set_env('external_sources', $sources);
    }

    /**
     * Get folder share dialog data
     */
    protected function get_share_info($folder)
    {
        // first get configured sources from Chwala
        $token   = $this->get_api_token();
        $request = $this->get_request(['method' => 'sharing', 'folder' => $folder], $token);

        // send request to the API
        try {
            $response = $request->send();
            $status   = $response->getStatus();
            $body     = @json_decode($response->getBody(), true);

            if ($status == 200 && $body['status'] == 'OK') {
                $info = $body['result'];
            } elseif ($body['code'] == 530) {
                return false;
            } else {
                throw new Exception($body['reason'] ?: "Failed to get sharing form information. Status: $status");
            }
        } catch (Exception $e) {
            rcube::raise_error($e, true, false);
            return;
        }

        return $info;
    }

    /**
     * Registers translation labels for folder lists in UI
     */
    protected function folder_list_env()
    {
        // folder list and actions
        $this->plugin->add_label(
            'folderdeleting',
            'folderdeleteconfirm',
            'folderdeletenotice',
            'collection_audio',
            'collection_video',
            'collection_image',
            'collection_document',
            'additionalfolders',
            'listpermanent',
            'storageautherror'
        );
        $this->rc->output->add_label(
            'foldersubscribing',
            'foldersubscribed',
            'folderunsubscribing',
            'folderunsubscribed',
            'searching'
        );
    }
}
