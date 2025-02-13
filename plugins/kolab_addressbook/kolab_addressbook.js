/**
 * Client script for the Kolab address book plugin
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * @licstart  The following is the entire license notice for the
 * JavaScript code in this file.
 *
 * Copyright (C) 2011-2014, Kolab Systems AG <contact@kolabsys.com>
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
 * @licend  The above is the entire license notice
 * for the JavaScript code in this file.
 */

if (window.rcmail) {
    rcmail.addEventListener('init', function() {
        rcmail.set_book_actions();

        // contextmenu
        kolab_addressbook_contextmenu();

        // append search form for address books
        if (rcmail.gui_objects.folderlist) {
            // remove event handlers set by the regular treelist widget
            rcmail.treelist.container.off('click mousedown focusin focusout');

            // re-initialize folderlist widget
            // copy form app.js with additional parameters
            var widget_class = window.kolab_folderlist || rcube_treelist_widget;
            rcmail.treelist = new widget_class(rcmail.gui_objects.folderlist, {
                selectable: true,
                id_prefix: 'rcmli',
                id_encode: rcmail.html_identifier_encode,
                id_decode: rcmail.html_identifier_decode,
                searchbox: '#addressbooksearch',
                search_action: 'plugin.book-search',
                search_sources: [ 'folders', 'users' ],
                search_title: rcmail.gettext('listsearchresults','kolab_addressbook'),
                check_droptarget: function(node) { return !node.virtual && rcmail.check_droptarget(node.id) }
            });

            rcmail.treelist
                .addEventListener('collapse',  function(node) { rcmail.folder_collapsed(node) })
                .addEventListener('expand',    function(node) { rcmail.folder_collapsed(node) })
                .addEventListener('select',    function(node) { rcmail.triggerEvent('selectfolder', { folder:node.id, prefix:'rcmli' }) })
                .addEventListener('subscribe', function(node) {
                    var source;
                    if ((source = rcmail.env.address_sources[node.id])) {
                        source.subscribed = node.subscribed || false;
                        rcmail.http_post('plugin.book-subscribe', { _source:node.id, _permanent:source.subscribed?1:0 });
                    }
                })
                .addEventListener('remove', function(node) {
                    if (rcmail.env.address_sources[node.id]) {
                        rcmail.book_remove(node.id);
                    }
                })
                .addEventListener('insert-item', function(data) {
                    // register new address source
                    rcmail.env.address_sources[data.id] = rcmail.env.contactfolders[data.id] = data.data;
                    // subscribe folder and load groups to add them to the list
                    if (!data.data.virtual)
                      rcmail.http_post('plugin.book-subscribe', { _source:data.id, _permanent:data.data.subscribed?1:0, _groups:1 });
                })
                .addEventListener('search-complete', function(data) {
                    if (data.length)
                        rcmail.display_message(rcmail.gettext('nraddressbooksfound','kolab_addressbook').replace('$nr', data.length), 'voice');
                    else
                        rcmail.display_message(rcmail.gettext('noaddressbooksfound','kolab_addressbook'), 'notice');
                });
        }

        rcmail.contact_list && rcmail.contact_list.addEventListener('select', function(list) {
            var source, is_writable = true, is_traceable = false;

            // delete/move commands status was set by Roundcube core,
            // however, for Kolab addressbooks we like to check folder ACL
            if (list.selection.length && rcmail.commands['delete']) {
                $.each(rcmail.env.selection_sources, function() {
                    source = rcmail.env.address_sources[this];
                    if (source && source.kolab && source.rights.indexOf('t') < 0) {
                        return is_writable = false;
                    }
                });

                rcmail.enable_command('delete', 'move', is_writable);
            }

            if (list.get_single_selection()) {
                $.each(rcmail.env.selection_sources, function() {
                    source = rcmail.env.address_sources[this];
                    is_traceable = source && !!source.audittrail;
                });
            }

            rcmail.enable_command('contact-history-dialog', is_traceable);
        });
    });

    rcmail.addEventListener('listupdate', function() {
        rcmail.set_book_actions();
    });
}

// (De-)activates address book management commands
rcube_webmail.prototype.set_book_actions = function()
{
    var source = !this.env.group ? this.env.source : null,
        sources = this.env.address_sources || {},
        props = source && sources[source] && sources[source].kolab ? sources[source] : { removable: false, rights: '' },
        can_delete = props.rights.indexOf('x') >= 0 || props.rights.indexOf('a') >= 0;

    this.enable_command('book-create', true);
    this.enable_command('book-edit', can_delete);
    this.enable_command('book-delete', can_delete);
    this.enable_command('book-remove', props.removable);
    this.enable_command('book-showurl', !!props.carddavurl || source == this.env.kolab_addressbook_carddav_ldap);
};

rcube_webmail.prototype.book_create = function()
{
    this.book_dialog('create');
};

rcube_webmail.prototype.book_edit = function()
{
    this.book_dialog('edit');
};

// displays page with book edit/create form
rcube_webmail.prototype.book_dialog = function(action)
{
    var title = rcmail.gettext('kolab_addressbook.book' + action),
        params = {_act: action, _framed: 1, _source: action == 'edit' ? this.env.source : ''},
        dialog = $('<iframe>').attr('src', rcmail.url('plugin.book', params)),
        save_func = function() {
            var data,
                form = dialog.contents().find('form'),
                input = form.find("input[name='_name']");

            // form is not loaded
            if (!form || !form.length)
                return false;

            if (input.length && input.val() == '') {
                rcmail.alert_dialog(rcmail.get_label('kolab_addressbook.nobooknamewarning'), function() {
                    input.focus();
                });

                return;
            }

            // post data to server
            data = form.serializeJSON();

            rcmail.http_post('plugin.book-save', data, rcmail.set_busy(true, 'kolab_addressbook.booksaving'));
            return true;
        };

    rcmail.simple_dialog(dialog, title, save_func, {
        width: 600,
        height: 400
    });
};

rcube_webmail.prototype.book_remove = function(id)
{
    if (!id) id = this.env.source;
    if (id != '' && rcmail.env.address_sources[id]) {
        rcmail.book_delete_done(id, true);
        rcmail.http_post('plugin.book-subscribe', { _source:id, _permanent:0, _recursive:1 });
    }
};

rcube_webmail.prototype.book_delete = function()
{
    if (this.env.source != '') {
        var source = urlencode(this.env.source);
        this.confirm_dialog(this.get_label('kolab_addressbook.bookdeleteconfirm'), 'delete', function() {
            var lock = rcmail.set_busy(true, 'kolab_addressbook.bookdeleting');
            rcmail.http_request('plugin.book', '_act=delete&_source=' + source, lock);
        });
    }
};

rcube_webmail.prototype.book_showurl = function()
{
    var url, source;

    if (this.env.source) {
        if (this.env.source == this.env.kolab_addressbook_carddav_ldap)
            url = this.env.kolab_addressbook_carddav_ldap_url;
        else if (source = this.env.address_sources[this.env.source])
            url = source.carddavurl;
    }

    if (url) {
        var txt = rcmail.gettext('carddavurldescription', 'kolab_addressbook'),
            dialog = $('<div>').addClass('showurldialog').append('<p>' + txt + '</p>'),
            textbox = $('<textarea>').addClass('urlbox').css('width', '100%').attr('rows', 3).appendTo(dialog);

        this.simple_dialog(dialog, rcmail.gettext('bookshowurl', 'kolab_addressbook'), null, {width: 520});

        textbox.val(url).select();
    }
};

// action executed after book delete
rcube_webmail.prototype.book_delete_done = function(id, recur)
{
    var n, groups = this.env.contactgroups,
        sources = this.env.address_sources,
        olddata = sources[id];

    this.treelist.remove(id);

    for (n in groups) {
        if (groups[n].source == id) {
            delete this.env.contactgroups[n];
            delete this.env.contactfolders[n];
        }
    }

    delete this.env.address_sources[id];
    delete this.env.contactfolders[id];

    if (this.env.last_source == id ) {
        this.env.last_source = Object.keys(this.env.address_sources)[0];
        this.env.last_group = '';
    }

    if (recur) {
        return;
    }

    this.enable_command('group-create', 'book-edit', 'book-delete', false);

    // remove subfolders
    olddata.realname += this.env.delimiter;
    for (n in sources) {
        if (sources[n].realname && sources[n].realname.indexOf(olddata.realname) == 0) {
            this.book_delete_done(n, true);
        }
    }
};

// action executed after book create/update
rcube_webmail.prototype.book_update = function(data, old)
{
    var classes = ['addressbook'],
        content = $('<div class="subscribed">').append(
            $('<a>').html(data.listname).attr({
                href: this.url('', {_source: data.id}),
                id: 'kabt:' + data.id,
                rel: data.id,
                onclick: "return rcmail.command('list', '" + data.id + "', this)"
            })
        );

    if (!data.carddav) {
        content.append($('<span>').attr({
            'class': 'subscribed',
            role: 'checkbox',
            'aria-checked': true,
            title: this.gettext('kolab_addressbook.foldersubscribe')
        }));
    }

    // set row attributes
    if (data.readonly)
        classes.push('readonly');
    if (data.group)
        classes.push(data.group);

    // update (remove old row)
    if (old) {
        // is the folder subscribed?
        if (!data.subscribed) {
            content.removeClass('subscribed').find('span').attr('aria-checked', false);
        }

        this.treelist.update(old, {id: data.id, html: content, classes: classes, parent: (old != data.id ? data.parent : null)}, data.group || true);
    }
    else {
        this.treelist.insert({id: data.id, html: content, classes: classes, childlistclass: 'groups'}, data.parent, data.group || true);
    }

    this.env.contactfolders[data.id] = this.env.address_sources[data.id] = data;

    // updated currently selected book
    if (this.env.source != '' && this.env.source == old) {
        this.treelist.select(data.id);
        this.env.source = data.id;
    }

    // update contextmenu
    kolab_addressbook_contextmenu();
};

// open dialog to show the current contact's changelog
rcube_webmail.prototype.contact_history_dialog = function()
{
    var $dialog, rec = { cid: this.get_single_cid(), source: rcmail.env.source },
        source = this.env.address_sources ? this.env.address_sources[rcmail.env.source] || {} : {};

    if (!rec.cid || !window.libkolab_audittrail || !source.audittrail) {
        return false;
    }

    if (this.contact_list && this.contact_list.data[rec.cid]) {
        $.extend(rec, this.contact_list.data[rec.cid]);
    }

    // render dialog
    $dialog = libkolab_audittrail.object_history_dialog({
        module: 'kolab_addressbook',
        container: '#contacthistory',
        title: rcmail.gettext('objectchangelog','kolab_addressbook') + ' - ' + rec.name,

        // callback function for list actions
        listfunc: function(action, rev) {
            var rec = $dialog.data('rec');

            if (action == 'show') {
                // open contact view in a dialog (iframe)
                var dialog, iframe = $('<iframe>')
                    .attr('id', 'contactshowrevframe')
                    .attr('width', '100%')
                    .attr('height', '98%')
                    .attr('frameborder', '0')
                    .attr('src', rcmail.url('show', { _cid: rec.cid, _source: rec.source, _rev: rev, _framed: 1 })),
                    contentframe = $('#' + rcmail.env.contentframe)

                // open jquery UI dialog
                dialog = rcmail.show_popup_dialog(iframe, '', {}, {
                    modal: false,
                    resizable: true,
                    closeOnEscape: true,
                    title: rec.name + ' @ ' + rev,
                    close: function() {
                        dialog.dialog('destroy').attr('aria-hidden', 'true').remove();
                    },
                    minWidth: 400,
                    width: contentframe.width() || 600,
                    height: contentframe.height() || 400
                });
                dialog.css('padding', '0');
            }
            else {
                rcmail.kab_loading_lock = rcmail.set_busy(true, 'loading', rcmail.kab_loading_lock);
                rcmail.http_post('plugin.contact-' + action, { cid: rec.cid, source: rec.source, rev: rev }, rcmail.kab_loading_lock);
            }
        },

        // callback function for comparing two object revisions
        comparefunc: function(rev1, rev2) {
            var rec = $dialog.data('rec');
            rcmail.kab_loading_lock = rcmail.set_busy(true, 'loading', rcmail.kab_loading_lock);
            rcmail.http_post('plugin.contact-diff', { cid: rec.cid, source: rec.source, rev1: rev1, rev2: rev2 }, rcmail.kab_loading_lock);
        }
    });

    $dialog.data('rec', rec);

    // fetch changelog data
    this.kab_loading_lock = rcmail.set_busy(true, 'loading', this.kab_loading_lock);
    this.http_post('plugin.contact-changelog', rec, this.kab_loading_lock);
};

// callback for displaying a contact's change history
rcube_webmail.prototype.contact_render_changelog = function(data)
{
    var $dialog = $('#contacthistory'),
        rec = $dialog.data('rec');

    if (data === false || !data.length || !rec) {
      // display 'unavailable' message
      $('<div class="notfound-message note-dialog-message warning">' + rcmail.gettext('objectchangelognotavailable','kolab_addressbook') + '</div>')
          .insertBefore($dialog.find('.changelog-table').hide());
      return;
    }

    source = this.env.address_sources[rec.source] || {}
    source.editable = !source.readonly

    data.module = 'kolab_addressbook';
    libkolab_audittrail.render_changelog(data, rec, source);
};

// callback for rendering a diff view of two contact revisions
rcube_webmail.prototype.contact_show_diff = function(data)
{
    var $dialog = $('#contactdiff'),
        rec = {}, namediff = { 'old': '', 'new': '', 'set': false };

    if (this.contact_list && this.contact_list.data[data.cid]) {
        rec = this.contact_list.data[data.cid];
    }

    $dialog.find('div.form-section, h2.contact-names-new').hide().data('set', false);
    $dialog.find('div.form-section.clone').remove();

    var name_props = ['prefix','firstname','middlename','surname','suffix'];

    // Quote HTML entities
    var Q = function(str){
        return String(str).replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };

    // show each property change
    $.each(data.changes, function(i, change) {
        var prop = change.property, r2, html = !!change.ishtml,
            row = $('div.contact-' + prop, $dialog).first();

        // special case: names
        if ($.inArray(prop, name_props) >= 0) {
            namediff['old'] += change['old'] + ' ';
            namediff['new'] += change['new'] + ' ';
            namediff['set'] = true;
            return true;
        }

        // no display container for this property
        if (!row.length) {
            return true;
        }

        // clone row if already exists
        if (row.data('set')) {
            r2 = row.clone().addClass('clone').insertAfter(row);
            row = r2;
        }

        // render photo as image with data: url
        if (prop == 'photo') {
            row.children('.diff-img-old').attr('src', change['old'] ? 'data:' + (change['old'].mimetype || 'image/gif') + ';base64,' + change['old'].base64 : 'data:image/gif;base64,R0lGODlhAQABAPAAAOjq6gAAACH/C1hNUCBEYXRhWE1QAT8AIfkEBQAAAAAsAAAAAAEAAQAAAgJEAQA7');
            row.children('.diff-img-new').attr('src', change['new'] ? 'data:' + (change['new'].mimetype || 'image/gif') + ';base64,' + change['new'].base64 : 'data:image/gif;base64,R0lGODlhAQABAPAAAOjq6gAAACH/C1hNUCBEYXRhWE1QAT8AIfkEBQAAAAAsAAAAAAEAAQAAAgJEAQA7');
        }
        else if (change.diff_) {
            row.children('.diff-text-diff').html(change.diff_);
            row.children('.diff-text-old, .diff-text-new').hide();
        }
        else {
            if (!html) {
                // escape HTML characters
                change.old_ = Q(change.old_ || change['old'] || '--')
                change.new_ = Q(change.new_ || change['new'] || '--')
            }
            row.children('.diff-text-old').html(change.old_ || change['old'] || '--').show();
            row.children('.diff-text-new').html(change.new_ || change['new'] || '--').show();
        }

        // display index number
        if (typeof change.index != 'undefined') {
            row.find('.index').html('(' + change.index + ')');
        }

        row.show().data('set', true);
    });

    // always show name
    if (namediff.set) {
        $('.contact-names', $dialog).html($.trim(namediff['old'] || '--')).addClass('diff-text-old').show();
        $('.contact-names-new', $dialog).html($.trim(namediff['new'] || '--')).show();
    }
    else {
        $('.contact-names', $dialog).text(rec.name).removeClass('diff-text-old').show();
    }

    // open jquery UI dialog
    $dialog.dialog({
        modal: false,
        resizable: true,
        closeOnEscape: true,
        title: rcmail.gettext('objectdiff','kolab_addressbook').replace('$rev1', data.rev1).replace('$rev2', data.rev2),
        open: function() {
            $dialog.attr('aria-hidden', 'false');
        },
        close: function() {
            $dialog.dialog('destroy').attr('aria-hidden', 'true').hide();
        },
        buttons: [
            {
                text: rcmail.gettext('close'),
                click: function() { $dialog.dialog('close'); },
                autofocus: true
            }
        ],
        minWidth: 400,
        width: 480
    }).show();

    // set dialog size according to content frame
    libkolab_audittrail.dialog_resize($dialog.get(0), $dialog.height(), ($('#' + rcmail.env.contentframe).width() || 440) - 40);
};


// close the contact history dialog
rcube_webmail.prototype.close_contact_history_dialog = function(refresh)
{
    $('#contacthistory, #contactdiff').each(function(i, elem) {
        var $dialog = $(elem);
        if ($dialog.is(':ui-dialog'))
            $dialog.dialog('close');
    });

    // reload the contact content frame
    if (refresh && this.get_single_cid() == refresh) {
        this.load_contact(refresh, 'show', true);
    }
};


function kolab_addressbook_contextmenu()
{
    if (!rcmail.env.contextmenu) {
        return;
    }

    if (!rcmail.env.kolab_addressbook_contextmenu) {
        // adjust default addressbook menu actions
        rcmail.addEventListener('contextmenu_init', function(menu) {
            if (menu.menu_name == 'abooklist') {
                menu.addEventListener('activate', function(p) {
                    var source = $(p.source).is('.addressbook') ? rcmail.html_identifier_decode($(p.source).attr('id').replace(/^rcmli/, '')) : null,
                        sources = rcmail.env.address_sources,
                        props = source && sources[source] && sources[source].kolab ?
                            sources[source] : { readonly: true, removable: false, rights: '' };

                    if (p.command == 'book-create') {
                        return true;
                    }

                    if (p.command == 'book-edit') {
                        return props.rights.indexOf('a') >= 0;
                    }

                    if (p.command == 'book-delete') {
                        return props.rights.indexOf('a') >= 0 || props.rights.indexOf('x') >= 0;
                    }

                    if (p.command == 'book-remove') {
                        return props.removable;
                    }

                    if (p.command == 'book-showurl') {
                        return !!(props.carddavurl);
                    }
                });
            }
        });
    }
};
