<?php

class kolab_notes_ui
{
    private $folder;
    private $rc;
    private $plugin;
    private $list;
    private $ready = false;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
        $this->rc = $plugin->rc;
    }

    /**
    * Calendar UI initialization and requests handlers
    */
    public function init()
    {
        if ($this->ready) {  // already done
            return;
        }

        // add taskbar button
        $this->plugin->add_button([
            'command'    => 'notes',
            'class'      => 'button-notes',
            'classsel'   => 'button-notes button-selected',
            'innerclass' => 'button-inner',
            'label'      => 'kolab_notes.navtitle',
            'type'       => 'link',
        ], 'taskbar');

        $this->plugin->include_stylesheet($this->plugin->local_skin_path() . '/notes.css');

        $this->ready = true;
    }

    /**
    * Register handler methods for the template engine
    */
    public function init_templates()
    {
        $this->plugin->register_handler('plugin.notebooks', [$this, 'folders']);
        #$this->plugin->register_handler('plugin.folders_select', array($this, 'folders_select'));
        $this->plugin->register_handler('plugin.searchform', [$this->rc->output, 'search_form']);
        $this->plugin->register_handler('plugin.listing', [$this, 'listing']);
        $this->plugin->register_handler('plugin.editform', [$this, 'editform']);
        $this->plugin->register_handler('plugin.notetitle', [$this, 'notetitle']);
        $this->plugin->register_handler('plugin.detailview', [$this, 'detailview']);
        $this->plugin->register_handler('plugin.attachments_list', [$this, 'attachments_list']);
        $this->plugin->register_handler('plugin.object_changelog_table', ['libkolab', 'object_changelog_table']);

        $this->rc->output->include_script('list.js');
        $this->rc->output->include_script('treelist.js');
        $this->plugin->include_script('notes.js');
        $this->plugin->api->include_script('libkolab/libkolab.js');

        // load config options and user prefs relevant for the UI
        $settings = [
            'sort_col' => $this->rc->config->get('kolab_notes_sort_col', 'changed'),
        ];

        if ($list = rcube_utils::get_input_value('_list', rcube_utils::INPUT_GPC)) {
            $settings['selected_list'] = $list;
        }
        if ($uid = rcube_utils::get_input_value('_id', rcube_utils::INPUT_GPC)) {
            $settings['selected_uid'] = $uid;
        }

        $this->rc->html_editor();

        $this->rc->output->set_env('kolab_notes_settings', $settings);
        $this->rc->output->add_label('save', 'cancel', 'delete', 'close', 'listoptionstitle');
    }

    public function folders($attrib)
    {
        $attrib += ['id' => 'rcmkolabnotebooks'];
        $is_select = ($attrib['type'] ?? null) == 'select';

        if ($is_select) {
            $attrib['is_escaped'] = true;
            $select = new html_select($attrib);
        }

        $tree  = !$is_select ? true : null;
        $lists = $this->plugin->get_lists($tree);
        $jsenv = [];

        // @phpstan-ignore-next-line
        if (is_object($tree)) {
            $html = $this->folder_tree_html($tree, $lists, $jsenv, $attrib);
        } else {
            $html = '';
            foreach ($lists as $prop) {
                $id = $prop['id'];

                if (empty($prop['virtual'])) {
                    unset($prop['user_id']);
                    $jsenv[$id] = $prop;
                }

                if ($is_select) {
                    if ($prop['editable'] || strpos($prop['rights'], 'i') !== false) {
                        $select->add($prop['name'], $prop['id']);
                    }
                } else {
                    $html .= html::tag(
                        'li',
                        ['id' => 'rcmliknb' . rcube_utils::html_identifier($id), 'class' => $prop['group']],
                        $this->folder_list_item($id, $prop, $jsenv)
                    );
                }
            }
        }

        $this->rc->output->set_env('kolab_notebooks', $jsenv);
        $this->rc->output->add_gui_object('notebooks', $attrib['id']);

        return $is_select ? $select->show() : html::tag('ul', $attrib, $html, html::$common_attrib);
    }

    /**
     * Return html for a structured list <ul> for the folder tree
     */
    public function folder_tree_html($node, $data, &$jsenv, $attrib)
    {
        $out = '';
        foreach ($node->children as $folder) {
            $id = $folder->id;
            $prop = $data[$id];
            $is_collapsed = false; // TODO: determine this somehow?

            $content = $this->folder_list_item($id, $prop, $jsenv);

            if (!empty($folder->children)) {
                $content .= html::tag(
                    'ul',
                    ['style' => ($is_collapsed ? "display:none;" : null)], // @phpstan-ignore-line
                    $this->folder_tree_html($folder, $data, $jsenv, $attrib)
                );
            }

            if (strlen($content)) {
                $out .= html::tag(
                    'li',
                    [
                      'id' => 'rcmliknb' . rcube_utils::html_identifier($id),
                      'class' => $prop['group'] . (!empty($prop['virtual']) ? ' virtual' : ''),
                    ],
                    $content
                );
            }
        }

        return $out;
    }

    /**
     * Helper method to build a tasklist item (HTML content and js data)
     */
    public function folder_list_item($id, $prop, &$jsenv, $checkbox = false)
    {
        if (empty($prop['virtual'])) {
            unset($prop['user_id']);
            $jsenv[$id] = $prop;
        }

        $classes = ['folder'];
        if (!empty($prop['virtual'])) {
            $classes[] = 'virtual';
        } elseif (!$prop['editable']) {
            $classes[] = 'readonly';
        }
        if ($prop['subscribed']) {
            $classes[] = 'subscribed';
        }
        if ($prop['class']) {
            $classes[] = $prop['class'];
        }

        $title = !empty($prop['title']) ? $prop['title'] : ($prop['name'] != $prop['listname'] || strlen($prop['name']) > 25 ?
          html_entity_decode($prop['name'], ENT_COMPAT, RCUBE_CHARSET) : '');

        $label_id = 'nl:' . $id;
        $attr = !empty($prop['virtual']) ? ['tabindex' => '0'] : ['href' => $this->rc->url(['_list' => $id])];

        return html::div(
            implode(' ', $classes),
            html::a($attr + ['class' => 'listname', 'title' => $title, 'id' => $label_id], $prop['listname'] ?: $prop['name']) .
            (
                !empty($prop['virtual']) ? '' :
                (
                    $checkbox ?
                    html::tag('input', ['type' => 'checkbox', 'name' => '_list[]', 'value' => $id, 'checked' => $prop['active'], 'aria-labelledby' => $label_id]) :
                    ''
                ) .
                html::span('handle', '') .
                html::span(
                    'actions',
                    (
                        empty($prop['default']) ?
                        html::a(['href' => '#', 'class' => 'remove', 'title' => $this->plugin->gettext('removelist')], ' ') :
                        ''
                    ) .
                    (
                        isset($prop['subscribed']) ?
                        html::a(['href' => '#', 'class' => 'subscribed', 'title' => $this->plugin->gettext('foldersubscribe'), 'role' => 'checkbox', 'aria-checked' => $prop['subscribed'] ? 'true' : 'false'], ' ') :
                        ''
                    )
                )
            )
        );
    }

    public function listing($attrib)
    {
        $attrib += ['id' => 'rcmkolabnoteslist'];
        $this->rc->output->add_gui_object('noteslist', $attrib['id']);
        return html::tag('table', $attrib, '<tbody></tbody>', html::$common_attrib);
    }

    public function editform($attrib)
    {
        $attrib += ['action' => '#', 'id' => 'rcmkolabnoteseditform'];

        $textarea = new html_textarea([
                'name'     => 'content',
                'id'       => 'notecontent',
                'cols'     => 60,
                'rows'     => 20,
                'tabindex' => 0,
                'class'    => 'mce_editor form-control',
        ]);

        $this->rc->output->add_gui_object('noteseditform', $attrib['id']);

        return html::tag('form', $attrib, $textarea->show(), array_merge(html::$common_attrib, ['action']));
    }

    public function detailview($attrib)
    {
        $attrib += ['id' => 'rcmkolabnotesdetailview'];
        $this->rc->output->add_gui_object('notesdetailview', $attrib['id']);
        return html::div($attrib, '');
    }

    public function notetitle($attrib)
    {
        $attrib += ['id' => 'rcmkolabnotestitle'];
        $this->rc->output->add_gui_object('noteviewtitle', $attrib['id']);

        $summary = new html_inputfield([
                'name'     => 'summary',
                'class'    => 'notetitle inline-edit form-control',
                'size'     => 60,
                'id'       => 'notetitleinput',
                'tabindex' => 0,
        ]);

        $html = html::div(
            'form-group row',
            html::label(['class' => 'col-sm-2 col-form-label', 'for' => 'notetitleinput'], $this->plugin->gettext('kolab_notes.title'))
                    . html::span('col-sm-10', $summary->show())
        )
            . html::div(
                'form-group row',
                html::label(['class' => 'col-sm-2 col-form-label'], $this->plugin->gettext('kolab_notes.tags'))
                    . html::div(['class' => 'tagline tagedit col-sm-10'], '&nbsp;')
            )
            . html::div(
                ['class' => 'dates text-only', 'style' => 'display:none'],
                html::div(
                    'form-group row',
                    html::label(['class' => 'col-sm-2 col-form-label'], $this->plugin->gettext('created'))
                    . html::span('col-sm-10', html::span('notecreated form-control-plaintext', ''))
                )
                . html::div(
                    'form-group row',
                    html::label(['class' => 'col-sm-2 col-form-label'], $this->plugin->gettext('changed'))
                    . html::span('col-sm-10', html::span('notechanged form-control-plaintext', ''))
                )
            );

        return html::div($attrib, $html);
    }

    public function attachments_list($attrib)
    {
        $attrib += ['id' => 'rcmkolabnotesattachmentslist'];
        $this->rc->output->add_gui_object('notesattachmentslist', $attrib['id']);
        return html::tag('ul', $attrib, '', html::$common_attrib);
    }

    /**
     * Render create/edit form for notes lists (folders)
     */
    public function list_editform($action, $list, $folder)
    {
        $this->list   = $list;
        $this->folder = is_object($folder) ? $folder->name : ''; // UTF7;

        $this->rc->output->set_env('pagetitle', $this->plugin->gettext('arialabelnotebookform'));
        $this->rc->output->add_handler('folderform', [$this, 'notebookform']);
        $this->rc->output->send('libkolab.folderform');
    }

    /**
     * Render create/edit form for notes lists (folders)
     */
    public function notebookform($attrib)
    {
        $folder_name     = $this->folder;
        $hidden_fields[] = ['name' => 'oldname', 'value' => $folder_name];

        $storage = $this->rc->get_storage();
        $delim   = $storage->get_hierarchy_delimiter();
        $form   = [];

        if (strlen($folder_name)) {
            $options = $storage->folder_info($folder_name);

            $path_imap = explode($delim, $folder_name);
            array_pop($path_imap);  // pop off name part
            $path_imap = implode($delim, $path_imap);
        } else {
            $path_imap = '';
            $options   = [];
        }

        // General tab
        $form['properties'] = [
            'name'   => $this->rc->gettext('properties'),
            'fields' => [],
        ];

        // folder name (default field)
        $input_name = new html_inputfield(['name' => 'name', 'id' => 'noteslist-name', 'size' => 20]);
        $form['properties']['fields']['name'] = [
            'label' => $this->plugin->gettext('listname'),
            'value' => $input_name->show($this->list['editname'], ['disabled' => ($options['norename'] || $options['protected'])]),
            'id'    => 'noteslist-name',
        ];

        // prevent user from moving folder
        if (!empty($options) && ($options['norename'] || $options['protected'])) {
            $hidden_fields[] = ['name' => 'parent', 'value' => $path_imap];
        } else {
            $select = kolab_storage::folder_selector('note', ['name' => 'parent', 'id' => 'parent-folder'], $folder_name);
            $form['properties']['fields']['path'] = [
                'label' => $this->plugin->gettext('parentfolder'),
                'value' => $select->show(strlen($folder_name) ? $path_imap : ''),
                'id'    => 'parent-folder',
            ];
        }

        $form_html = kolab_utils::folder_form($form, $folder_name, 'kolab_notes', $hidden_fields);

        return html::tag('form', $attrib + ['action' => '#', 'method' => 'post', 'id' => 'noteslistpropform'], $form_html);
    }
}
