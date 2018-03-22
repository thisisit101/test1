/**
 * Client UI Javascript for the Calendar plugin
 *
 * @author Lazlo Westerhof <hello@lazlo.me>
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * @licstart  The following is the entire license notice for the
 * JavaScript code in this file.
 *
 * Copyright (C) 2010, Lazlo Westerhof <hello@lazlo.me>
 * Copyright (C) 2014-2015, Kolab Systems AG <contact@kolabsys.com>
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

// Roundcube calendar UI client class
function rcube_calendar_ui(settings)
{
    // extend base class
    rcube_calendar.call(this, settings);
    
    /***  member vars  ***/
    this.is_loading = false;
    this.selected_event = null;
    this.selected_calendar = null;
    this.search_request = null;
    this.saving_lock = null;
    this.calendars = {};
    this.quickview_sources = [];


    /***  private vars  ***/
    var DAY_MS = 86400000;
    var HOUR_MS = 3600000;
    var me = this;
    var day_clicked = day_clicked_ts = 0;
    var ignore_click = false;
    var event_defaults = { free_busy:'busy', alarms:'' };
    var event_attendees = [];
    var calendars_list;
    var calenders_search_list;
    var calenders_search_container;
    var search_calendars = {};
    var attendees_list;
    var resources_list;
    var resources_treelist;
    var resources_data = {};
    var resources_index = [];
    var resource_owners = {};
    var resources_events_source = { url:null, editable:false };
    var freebusy_ui = { workinhoursonly:false, needsupdate:false };
    var freebusy_data = {};
    var current_view = null;
    var count_sources = [];
    var exec_deferred = 1;
    var sensitivitylabels = { 'public':rcmail.gettext('public','calendar'), 'private':rcmail.gettext('private','calendar'), 'confidential':rcmail.gettext('confidential','calendar') };
    var ui_loading = rcmail.set_busy(true, 'loading');

    // general datepicker settings
    var datepicker_settings = {
      // translate from fullcalendar format to datepicker format
      dateFormat: settings['date_format'].replace(/M/g, 'm').replace(/mmmmm/, 'MM').replace(/mmm/, 'M').replace(/dddd/, 'DD').replace(/ddd/, 'D').replace(/yy/g, 'y'),
      firstDay : settings['first_day'],
      dayNamesMin: settings['days_short'],
      monthNames: settings['months'],
      monthNamesShort: settings['months'],
      changeMonth: false,
      showOtherMonths: true,
      selectOtherMonths: true
    };

    // global fullcalendar settings
    var fullcalendar_defaults = {
      aspectRatio: 1,
      ignoreTimezone: true,  // will treat the given date strings as in local (browser's) timezone
      monthNames : settings.months,
      monthNamesShort : settings.months_short,
      dayNames : settings.days,
      dayNamesShort : settings.days_short,
      firstDay : settings.first_day,
      firstHour : settings.first_hour,
      slotMinutes : 60/settings.timeslots,
      timeFormat: {
        '': settings.time_format,
        agenda: settings.time_format + '{ - ' + settings.time_format + '}',
        list: settings.time_format + '{ - ' + settings.time_format + '}',
        table: settings.time_format + '{ - ' + settings.time_format + '}'
      },
      axisFormat : settings.time_format,
      columnFormat: {
        month: 'ddd', // Mon
        week: 'ddd ' + settings.date_short, // Mon 9/7
        day: 'dddd ' + settings.date_short,  // Monday 9/7
        table: settings.date_agenda
      },
      titleFormat: {
        month: 'MMMM yyyy',
        week: settings.dates_long,
        day: 'dddd ' + settings['date_long'],
        table: settings.dates_long
      },
      listPage: 7,  // advance one week in agenda view
      listRange: settings.agenda_range,
      listSections: settings.agenda_sections,
      tableCols: ['handle', 'date', 'time', 'title', 'location'],
      defaultView: rcmail.env.view || settings.default_view,
      allDayText: rcmail.gettext('all-day', 'calendar'),
      buttonText: {
        prev: '&nbsp;&#9668;&nbsp;',
        next: '&nbsp;&#9658;&nbsp;',
        today: settings['today'],
        day: rcmail.gettext('day', 'calendar'),
        week: rcmail.gettext('week', 'calendar'),
        month: rcmail.gettext('month', 'calendar'),
        table: rcmail.gettext('agenda', 'calendar')
      },
      listTexts: {
        until: rcmail.gettext('until', 'calendar'),
        past: rcmail.gettext('pastevents', 'calendar'),
        today: rcmail.gettext('today', 'calendar'),
        tomorrow: rcmail.gettext('tomorrow', 'calendar'),
        thisWeek: rcmail.gettext('thisweek', 'calendar'),
        nextWeek: rcmail.gettext('nextweek', 'calendar'),
        thisMonth: rcmail.gettext('thismonth', 'calendar'),
        nextMonth: rcmail.gettext('nextmonth', 'calendar'),
        future: rcmail.gettext('futureevents', 'calendar'),
        week: rcmail.gettext('weekofyear', 'calendar')
      },
      currentTimeIndicator: settings.time_indicator,
      // event rendering
      eventRender: function(event, element, view) {
        if (view.name != 'list' && view.name != 'table') {
          var prefix = event.sensitivity && event.sensitivity != 'public' ? String(sensitivitylabels[event.sensitivity]).toUpperCase()+': ' : '';
          element.attr('title', prefix + event.title);
        }
        if (view.name != 'month') {
          if (event.location) {
            element.find('div.fc-event-title').after('<div class="fc-event-location">@&nbsp;' + Q(event.location) + '</div>');
          }
          if (event.sensitivity && event.sensitivity != 'public')
            element.find('div.fc-event-time').append('<i class="fc-icon-sensitive"></i>');
          if (event.recurrence)
            element.find('div.fc-event-time').append('<i class="fc-icon-recurring"></i>');
          if (event.alarms || (event.valarms && event.valarms.length))
            element.find('div.fc-event-time').append('<i class="fc-icon-alarms"></i>');
        }
        if (event.status) {
          element.addClass('cal-event-status-' + String(event.status).toLowerCase());
        }

        element.attr('aria-label', event.title + ', ' + me.event_date_text(event, true));
      },
      // render element indicating more (invisible) events
      overflowRender: function(data, element) {
        element.html(rcmail.gettext('andnmore', 'calendar').replace('$nr', data.count))
          .click(function(e){ me.fisheye_view(data.date); });
      },
      // callback when a specific event is clicked
      eventClick: function(event, ev, view) {
        if (!event.temp && String(event.className).indexOf('fc-type-freebusy') < 0)
          event_show_dialog(event, ev);
      }
    };

    /***  imports  ***/
    var Q = this.quote_html;
    var text2html = this.text2html;
    var event_date_text = this.event_date_text;
    var parse_datetime = this.parse_datetime;
    var date2unixtime = this.date2unixtime;
    var fromunixtime = this.fromunixtime;
    var parseISO8601 = this.parseISO8601;
    var date2servertime = this.date2ISO8601;
    var render_message_links = this.render_message_links;


    /***  private methods  ***/

    // same as str.split(delimiter) but it ignores delimiters within quoted strings
    var explode_quoted_string = function(str, delimiter)
    {
      var result = [],
        strlen = str.length,
        q, p, i, chr, last;

      for (q = p = i = 0; i < strlen; i++) {
        chr = str.charAt(i);
        if (chr == '"' && last != '\\') {
          q = !q;
        }
        else if (!q && chr == delimiter) {
          result.push(str.substring(p, i));
          p = i + 1;
        }
        last = chr;
      }

      result.push(str.substr(p));
      return result;
    };

    // Change the first charcter to uppercase
    var ucfirst = function(str)
    {
        return str.charAt(0).toUpperCase() + str.substr(1);
    };

    // clone the given date object and optionally adjust time
    var clone_date = function(date, adjust)
    {
      var d = new Date(date.getTime());
      
      // set time to 00:00
      if (adjust == 1) {
        d.setHours(0);
        d.setMinutes(0);
      }
      // set time to 23:59
      else if (adjust == 2) {
        d.setHours(23);
        d.setMinutes(59);
      }
      
      return d;
    };

    // fix date if jumped over a DST change
    var fix_date = function(date)
    {
      if (date.getHours() == 23)
        date.setTime(date.getTime() + HOUR_MS);
      else if (date.getHours() > 0)
        date.setHours(0);
    };

    var date2timestring = function(date, dateonly)
    {
      return date2servertime(date).replace(/[^0-9]/g, '').substr(0, (dateonly ? 8 : 14));
    }

    var format_datetime = function(date, mode, voice)
    {
      return me.format_datetime(date, mode, voice);
    }

    var render_link = function(url)
    {
      var islink = false, href = url;
      if (url.match(/^[fhtpsmailo]+?:\/\//i)) {
        islink = true;
      }
      else if (url.match(/^[a-z0-9.-:]+(\/|$)/i)) {
        islink = true;
        href = 'http://' + url;
      }
      return islink ? '<a href="' + Q(href) + '" target="_blank">' + Q(url) + '</a>' : Q(url);
    }

    // determine whether the given date is on a weekend
    var is_weekend = function(date)
    {
      return date.getDay() == 0 || date.getDay() == 6;
    };

    var is_workinghour = function(date)
    {
      if (settings['work_start'] > settings['work_end'])
        return date.getHours() >= settings['work_start'] || date.getHours() < settings['work_end'];
      else
        return date.getHours() >= settings['work_start'] && date.getHours() < settings['work_end'];
    };

    var load_attachment = function(data)
    {
      var event = data.record,
        query = {_id: data.attachment.id, _event: event.recurrence_id || event.id, _cal: event.calendar};

      if (event.rev)
        query._rev = event.rev;

      if (event.calendar == "--invitation--itip")
        $.extend(query, {_uid: event._uid, _part: event._part, _mbox: event._mbox});

      libkolab.load_attachment(query, data.attachment);
    };

    // build event attachments list
    var event_show_attachments = function(list, container, event, edit)
    {
      libkolab.list_attachments(list, container, edit, event,
        function(id) { remove_attachment(id); },
        function(data) { load_attachment(data); }
      );
    };

    var remove_attachment = function(id)
    {
      rcmail.env.deleted_attachments.push(id);
    };

    // event details dialog (show only)
    var event_show_dialog = function(event, ev, temp)
    {
      var $dialog = $("#eventshow");
      var calendar = event.calendar && me.calendars[event.calendar] ? me.calendars[event.calendar] : { editable:false, rights:'lrs' };

      if (!temp)
        me.selected_event = event;

      if ($dialog.is(':ui-dialog'))
        $dialog.dialog('close');

      // remove status-* and sensitivity-* classes
      $dialog.removeClass(function(i, oldclass) {
          var oldies = String(oldclass).split(' ');
          return $.grep(oldies, function(cls) { return cls.indexOf('status-') === 0 || cls.indexOf('sensitivity-') === 0 }).join(' ');
      });

      // convert start/end dates if not done yet by fullcalendar
      if (typeof event.start == 'string')
        event.start = parseISO8601(event.start);
      if (typeof event.end == 'string')
        event.end = parseISO8601(event.end);

      // allow other plugins to do actions when event form is opened
      rcmail.triggerEvent('calendar-event-init', {o: event});

      $dialog.find('div.event-section, div.event-line, .form-group').hide();
      $('#event-title').html(Q(event.title)).show();
      
      if (event.location)
        $('#event-location').html('@ ' + text2html(event.location)).show();
      if (event.description)
        $('#event-description').show().find('.event-text').html(text2html(event.description, 300, 6));
      if (event.vurl)
        $('#event-url').show().find('.event-text').html(render_link(event.vurl));
      
      // render from-to in a nice human-readable way
      // -> now shown in dialog title
      // $('#event-date').html(Q(me.event_date_text(event))).show();
      
      if (event.recurrence && event.recurrence_text)
        $('#event-repeat').show().find('.event-text').html(Q(event.recurrence_text));
      
      if (event.valarms && event.alarms_text)
        $('#event-alarm').show().find('.event-text').html(Q(event.alarms_text).replace(',', ',<br>'));
      
      if (calendar.name)
        $('#event-calendar').show().find('.event-text').html(Q(calendar.name)).addClass('cal-'+calendar.id).css('color', calendar.textColor || calendar.color || '');
      if (event.categories)
        $('#event-category').show().find('.event-text').html(Q(event.categories)).addClass('cat-'+String(event.categories).toLowerCase().replace(rcmail.identifier_expr, ''));
      if (event.free_busy)
        $('#event-free-busy').show().find('.event-text').text(rcmail.gettext(event.free_busy, 'calendar'));
      if (event.priority > 0) {
        var priolabels = [ '', rcmail.gettext('highest'), rcmail.gettext('high'), '', '', rcmail.gettext('normal'), '', '', rcmail.gettext('low'), rcmail.gettext('lowest') ];
        $('#event-priority').show().find('.event-text').html(Q(event.priority+' '+priolabels[event.priority]));
      }

      if (event.status) {
        var status_lc = String(event.status).toLowerCase();
        $('#event-status').show().find('.event-text').text(rcmail.gettext('status-'+status_lc,'calendar'));
        $('#event-status-badge > span').text(rcmail.gettext('status-'+status_lc,'calendar'));
        $dialog.addClass('status-'+status_lc);
      }
      if (event.sensitivity && event.sensitivity != 'public') {
        $('#event-sensitivity').show().find('.event-text').text(sensitivitylabels[event.sensitivity]);
        $('#event-status-badge > span').text(sensitivitylabels[event.sensitivity]);
        $dialog.addClass('sensitivity-'+event.sensitivity);
      }
      if (event.created || event.changed) {
        var created = parseISO8601(event.created),
          changed = parseISO8601(event.changed);
        $('.event-created', $dialog).text(created ? format_datetime(created) : rcmail.gettext('unknown','calendar'));
        $('.event-changed', $dialog).text(changed ? format_datetime(changed) : rcmail.gettext('unknown','calendar'));
        $('#event-created-changed').show()
      }

      // create attachments list
      if ($.isArray(event.attachments)) {
        event_show_attachments(event.attachments, $('#event-attachments').find('.event-text'), event);
        if (event.attachments.length > 0) {
          $('#event-attachments').show();
        }
      }
      else if (calendar.attachments) {
        // fetch attachments, some drivers doesn't set 'attachments' prop of the event?
      }

      // build attachments list
      $('#event-links').hide();
      if ($.isArray(event.links) && event.links.length) {
          render_message_links(event.links || [], $('#event-links').find('.event-text'), false, 'calendar');
          $('#event-links').show();
      }

      // list event attendees
      if (calendar.attendees && event.attendees) {
        // sort resources to the end
        event.attendees.sort(function(a,b) {
          var j = a.cutype == 'RESOURCE' ? 1 : 0,
              k = b.cutype == 'RESOURCE' ? 1 : 0;
          return (j - k);
        });

        var data, mystatus = null, rsvp, line, morelink, html = '', overflow = '',
          organizer = me.is_organizer(event);

        for (var j=0; j < event.attendees.length; j++) {
          data = event.attendees[j];
          if (data.email) {
            if (data.role != 'ORGANIZER' && settings.identity.emails.indexOf(';'+data.email) >= 0) {
              mystatus = (data.status || 'UNKNOWN').toLowerCase();
              if (data.status == 'NEEDS-ACTION' || data.status == 'TENTATIVE' || data.rsvp)
                rsvp = mystatus;
            }
          }

          line = rcube_libcalendaring.attendee_html(data);

          if (morelink)
            overflow += ' ' + line;
          else
            html += ' ' + line;

          // stop listing attendees
          if (j == 7 && event.attendees.length >= 7) {
            morelink = $('<a href="#more" class="morelink"></a>').html(rcmail.gettext('andnmore', 'calendar').replace('$nr', event.attendees.length - j - 1));
          }
        }

        if (html && (event.attendees.length > 1 || !organizer)) {
          $('#event-attendees').show()
            .find('.event-text')
            .html(html)
            .find('a.mailtolink').click(event_attendee_click);

          // display all attendees in a popup when clicking the "more" link
          if (morelink) {
            $('#event-attendees .event-text').append(morelink);
            morelink.click(function(e){
              rcmail.show_popup_dialog(
                '<div id="all-event-attendees" class="event-attendees">' + html + overflow + '</div>',
                rcmail.gettext('tabattendees','calendar'),
                null,
                { width:450, modal:false });
              $('#all-event-attendees a.mailtolink').click(event_attendee_click);
              return false;
            })
          }
        }

        if (mystatus && !rsvp) {
          $('#event-partstat').show().find('.changersvp')
            .removeClass('accepted tentative declined delegated needs-action unknown')
            .addClass(mystatus)
            .find('.event-text')
            .text(rcmail.gettext('status' + mystatus, 'libcalendaring'));
        }

        var show_rsvp = rsvp && !organizer && event.status != 'CANCELLED' && me.has_permission(calendar, 'v');
        $('#event-rsvp')[(show_rsvp ? 'show' : 'hide')]();
        $('#event-rsvp .rsvp-buttons input').prop('disabled', false).filter('input[rel="'+(mystatus || '')+'"]').prop('disabled', true);

        if (show_rsvp && event.comment)
          $('#event-rsvp-comment').show().find('.event-text').html(Q(event.comment));

        $('#event-rsvp a.reply-comment-toggle').show();
        $('#event-rsvp .itip-reply-comment textarea').hide().val('');

        if (event.recurrence && event.id) {
          var sel = event._savemode || (event.thisandfuture ? 'future' : (event.isexception ? 'current' : 'all'));
          $('#event-rsvp .rsvp-buttons').addClass('recurring');
        }
        else {
          $('#event-rsvp .rsvp-buttons').removeClass('recurring');
        }
      }

      var buttons = [];
      if (!temp && calendar.editable && event.editable !== false) {
        buttons.push({
          text: rcmail.gettext('edit', 'calendar'),
          'class': 'edit mainaction',
          click: function() {
            event_edit_dialog('edit', event);
          }
        });
      }
      if (!temp && me.has_permission(calendar, 'td') && event.editable !== false) {
        buttons.push({
          text: rcmail.gettext('delete', 'calendar'),
          'class': 'delete',
          click: function() {
            me.delete_event(event);
            $dialog.dialog('close');
          }
        });
      }

      if (!buttons.length) {
        buttons.push({
          text: rcmail.gettext('close', 'calendar'),
          'class': 'cancel',
          click: function() {
            $dialog.dialog('close');
          }
        });
      }

      // open jquery UI dialog
      $dialog.dialog({
        modal: true,
        resizable: true,
        closeOnEscape: true,
        title: me.event_date_text(event),
        open: function() {
          $dialog.attr('aria-hidden', 'false');
          setTimeout(function(){
            $dialog.parent().find('.ui-button:not(.ui-dialog-titlebar-close)').first().focus();
          }, 5);
        },
        beforeClose: function(e) {
          rcmail.command('menu-close', 'eventoptionsmenu', null, e);
        },
        close: function(e) {
          $dialog.dialog('destroy').attr('aria-hidden', 'true').hide();
          $('.libcal-rsvp-replymode').hide();
        },
        dragStart: function(e) {
          rcmail.command('menu-close', 'eventoptionsmenu', null, e);
          $('.libcal-rsvp-replymode').hide();
        },
        resizeStart: function(e) {
          rcmail.command('menu-close', 'eventoptionsmenu', null, e);
          $('.libcal-rsvp-replymode').hide();
        },
        buttons: buttons,
        minWidth: 320,
        width: 420
      }).show();

      // remember opener element (to be focused on close)
      $dialog.data('opener', ev && rcube_event.is_keyboard(ev) ? ev.target : null);

      // set voice title on dialog widget
      $dialog.dialog('widget').removeAttr('aria-labelledby')
        .attr('aria-label', me.event_date_text(event, true) + ', ', event.title);

      // set dialog size according to content
      me.dialog_resize($dialog.get(0), $dialog.height(), 420);

      // add link for "more options" drop-down
      if (!temp && !event.temporary && event.calendar != '_resource') {
        $('<a>')
          .attr({href: '#', 'class': 'dropdown-link btn btn-link options', 'data-popup-pos': 'top'})
          .text(rcmail.gettext('eventoptions','calendar'))
          .click(function(e) {
            return rcmail.command('menu-open','eventoptionsmenu', this, e)
          })
          .appendTo($dialog.parent().find('.ui-dialog-buttonset'));
      }

      rcmail.enable_command('event-history', calendar.history)

      rcmail.triggerEvent('calendar-event-dialog', {dialog: $dialog});
    };

    // event handler for clicks on an attendee link
    var event_attendee_click = function(e)
    {
      var cutype = $(this).attr('data-cutype'),
        mailto = this.href.substr(7);
      if (rcmail.env.calendar_resources && cutype == 'RESOURCE') {
        event_resources_dialog(mailto);
      }
      else {
        rcmail.command('compose', mailto, e ? e.target : null, e);
      }
      return false;
    };

    // bring up the event dialog (jquery-ui popup)
    var event_edit_dialog = function(action, event)
    {
      // copy opener element from show dialog
      var op_elem = $("#eventshow:ui-dialog").data('opener');

      // close show dialog first
      $("#eventshow:ui-dialog").data('opener', null).dialog('close');

      var $dialog = $('<div>');
      var calendar = event.calendar && me.calendars[event.calendar] ? me.calendars[event.calendar] : { editable:true, rights: action=='new' ? 'lrwitd' : 'lrs' };
      me.selected_event = $.extend($.extend({}, event_defaults), event);  // clone event object (with defaults)
      event = me.selected_event; // change reference to clone
      freebusy_ui.needsupdate = false;

      // reset dialog first
      $('#eventedit form').trigger('reset');
      $('#event-panel-recurrence input, #event-panel-recurrence select, #event-panel-attachments input').prop('disabled', false);
      $('#event-panel-recurrence, #event-panel-attachments').removeClass('disabled');

      // allow other plugins to do actions when event form is opened
      rcmail.triggerEvent('calendar-event-init', {o: event});

      // event details
      var title = $('#edit-title').val(event.title || '');
      var location = $('#edit-location').val(event.location || '');
      var description = $('#edit-description').text(event.description || '');
      var vurl = $('#edit-url').val(event.vurl || '');
      var categories = $('#edit-categories').val(event.categories);
      var calendars = $('#edit-calendar').val(event.calendar);
      var eventstatus = $('#edit-event-status').val(event.status);
      var freebusy = $('#edit-free-busy').val(event.free_busy);
      var priority = $('#edit-priority').val(event.priority);
      var sensitivity = $('#edit-sensitivity').val(event.sensitivity);
      var syncstart = $('#edit-recurrence-syncstart input');
      var duration = Math.round((event.end.getTime() - event.start.getTime()) / 1000);
      var startdate = $('#edit-startdate').val($.fullCalendar.formatDate(event.start, settings['date_format'])).data('duration', duration);
      var starttime = $('#edit-starttime').val($.fullCalendar.formatDate(event.start, settings['time_format'])).show();
      var enddate = $('#edit-enddate').val($.fullCalendar.formatDate(event.end, settings['date_format']));
      var endtime = $('#edit-endtime').val($.fullCalendar.formatDate(event.end, settings['time_format'])).show();
      var allday = $('#edit-allday').get(0);
      var notify = $('#edit-attendees-donotify').get(0);
      var invite = $('#edit-attendees-invite').get(0);
      var comment = $('#edit-attendees-comment');

      // make sure any calendar is selected
      if (!calendars.val())
        calendars.val($('option:first', calendars).attr('value'));

      invite.checked = settings.itip_notify & 1 > 0;
      notify.checked = me.has_attendees(event) && invite.checked;

      if (event.allDay) {
        starttime.val("12:00").hide();
        endtime.val("13:00").hide();
        allday.checked = true;
      }
      else {
        allday.checked = false;
      }

      // set calendar selection according to permissions
      calendars.find('option').each(function(i, opt) {
        var cal = me.calendars[opt.value] || {};
        $(opt).prop('disabled', !(cal.editable || (action == 'new' && me.has_permission(cal, 'i'))))
      });

      // set alarm(s)
      me.set_alarms_edit('#edit-alarms', action != 'new' && event.valarms && calendar.alarms ? event.valarms : []);

      // enable/disable alarm property according to backend support
      $('#edit-alarms')[(calendar.alarms ? 'show' : 'hide')]();

      // check categories drop-down: add value if not exists
      if (event.categories && !categories.find("option[value='"+event.categories+"']").length) {
        $('<option>').attr('value', event.categories).text(event.categories).appendTo(categories).prop('selected', true);
      }

      if ($.isArray(event.links) && event.links.length) {
          render_message_links(event.links, $('#edit-event-links .event-text'), true, 'calendar');
          $('#edit-event-links').show();
      }
      else {
          $('#edit-event-links').hide();
      }

      // show warning if editing a recurring event
      if (event.id && event.recurrence) {
        var sel = event._savemode || (event.thisandfuture ? 'future' : (event.isexception ? 'current' : 'all'));
        $('#edit-recurring-warning').show();
        $('input.edit-recurring-savemode[value="'+sel+'"]').prop('checked', true).change();
      }
      else
        $('#edit-recurring-warning').hide();

      // init attendees tab
      var organizer = !event.attendees || me.is_organizer(event),
        allow_invitations = organizer || (calendar.owner && calendar.owner == 'anonymous') || settings.invite_shared;
      event_attendees = [];
      attendees_list = $('#edit-attendees-table > tbody').html('');
      resources_list = $('#edit-resources-table > tbody').html('');
      $('#edit-attendees-notify')[(action != 'new' && allow_invitations && me.has_attendees(event) && (settings.itip_notify & 2) ? 'show' : 'hide')]();
      $('#edit-localchanges-warning')[(action != 'new' && me.has_attendees(event) && !(allow_invitations || (calendar.owner && me.is_organizer(event, calendar.owner))) ? 'show' : 'hide')]();

      var load_attendees_tab = function()
      {
        var j, data, organizer_attendee, reply_selected = 0;
        if (event.attendees) {
          for (j=0; j < event.attendees.length; j++) {
            data = event.attendees[j];
            // reset attendee status
            if (event._savemode == 'new' && data.role != 'ORGANIZER') {
              data.status = 'NEEDS-ACTION';
              delete data.noreply;
            }
            add_attendee(data, !allow_invitations);
            if (allow_invitations && data.role != 'ORGANIZER' && !data.noreply)
              reply_selected++;

            if (data.role == 'ORGANIZER')
              organizer_attendee = data;
          }
        }

        // make sure comment box is visible if at least one attendee has reply enabled
        // or global "send invitations" checkbox is checked
        $('#eventedit .attendees-commentbox')[(reply_selected || invite.checked ? 'show' : 'hide')]();

        // select the correct organizer identity
        var identity_id = 0;
        $.each(settings.identities, function(i,v){
          if (organizer && typeof organizer == 'object' && v == organizer.email) {
            identity_id = i;
            return false;
          }
        });

        // In case the user is not the (shared) event organizer we'll add the organizer to the selection list
        if (!identity_id && !organizer && organizer_attendee) {
          var organizer_name = organizer_attendee.email;
          if (organizer_attendee.name)
            organizer_name = '"' + organizer_attendee.name + '" <' + organizer_name + '>';
          $('#edit-identities-list').append($('<option value="0">').text(organizer_name));
        }

        $('#edit-identities-list').val(identity_id);
        $('#edit-attendees-form')[(allow_invitations?'show':'hide')]();
        $('#edit-attendee-schedule')[(calendar.freebusy?'show':'hide')]();
      };

      // attachments
      var load_attachments_tab = function()
      {
        rcmail.enable_command('remove-attachment', 'upload-file', calendar.editable && !event.recurrence_id);
        rcmail.env.deleted_attachments = [];
        // we're sharing some code for uploads handling with app.js
        rcmail.env.attachments = [];
        rcmail.env.compose_id = event.id; // for rcmail.async_upload_form()

        if ($.isArray(event.attachments)) {
          event_show_attachments(event.attachments, $('#edit-attachments'), event, true);
        }
        else {
          $('#edit-attachments > ul').empty();
          // fetch attachments, some drivers doesn't set 'attachments' array for event?
        }
      };
      
      // init dialog buttons
      var buttons = [];
      
      // save action
      buttons.push({
        text: rcmail.gettext('save', 'calendar'),
        'class': 'save mainaction',
        click: function() {
        var start = parse_datetime(allday.checked ? '12:00' : starttime.val(), startdate.val());
        var end   = parse_datetime(allday.checked ? '13:00' : endtime.val(), enddate.val());
        
        // basic input validatetion
        if (start.getTime() > end.getTime()) {
          alert(rcmail.gettext('invalideventdates', 'calendar'));
          return false;
        }
        
        // post data to server
        var data = {
          calendar: event.calendar,
          start: date2servertime(start),
          end: date2servertime(end),
          allday: allday.checked?1:0,
          title: title.val(),
          description: description.val(),
          location: location.val(),
          categories: categories.val(),
          vurl: vurl.val(),
          free_busy: freebusy.val(),
          priority: priority.val(),
          sensitivity: sensitivity.val(),
          status: eventstatus.val(),
          recurrence: me.serialize_recurrence(endtime.val()),
          valarms: me.serialize_alarms('#edit-alarms'),
          attendees: event_attendees,
          links: me.selected_event.links,
          deleted_attachments: rcmail.env.deleted_attachments,
          attachments: []
        };

        // uploaded attachments list
        for (var i in rcmail.env.attachments)
          if (i.match(/^rcmfile(.+)/))
            data.attachments.push(RegExp.$1);

        // read attendee roles
        $('select.edit-attendee-role').each(function(i, elem){
          if (data.attendees[i])
            data.attendees[i].role = $(elem).val();
        });

        if (organizer)
          data._identity = $('#edit-identities-list option:selected').val();

        // per-attendee notification suppression
        var need_invitation = false;
        if (allow_invitations) {
          $.each(data.attendees, function (i, v) {
            if (v.role != 'ORGANIZER') {
              if ($('input.edit-attendee-reply[value="' + v.email + '"]').prop('checked') || v.cutype == 'RESOURCE') {
                need_invitation = true;
                delete data.attendees[i]['noreply'];
              }
              else if (settings.itip_notify > 0) {
                data.attendees[i].noreply = 1;
              }
            }
          });
        }

        // tell server to send notifications
        if ((data.attendees.length || (event.id && event.attendees.length)) && allow_invitations && (notify.checked || invite.checked || need_invitation)) {
          data._notify = settings.itip_notify;
          data._comment = comment.val();
        }

        data.calendar = calendars.val();

        if (event.id) {
          data.id = event.id;
          if (event.recurrence)
            data._savemode = $('input.edit-recurring-savemode:checked').val();
          if (data.calendar && data.calendar != event.calendar)
            data._fromcalendar = event.calendar;
        }

        if (data.recurrence && syncstart.is(':checked'))
          data.syncstart = 1;

        update_event(action, data);
        $dialog.dialog("close");
      }  // end click:
      });

      if (event.id) {
        buttons.push({
          text: rcmail.gettext('delete', 'calendar'),
          'class': 'delete',
          click: function() {
            me.delete_event(event);
            $dialog.dialog('close');
          }
        });
      }

      buttons.push({
        text: rcmail.gettext('cancel', 'calendar'),
        'class': 'cancel',
        click: function() {
          $dialog.dialog("close");
        }
      });

      // show/hide tabs according to calendar's feature support and activate first tab (Larry)
      $('#edit-tab-attendees')[(calendar.attendees?'show':'hide')]();
      $('#edit-tab-resources')[(rcmail.env.calendar_resources?'show':'hide')]();
      $('#edit-tab-attachments')[(calendar.attachments?'show':'hide')]();
      $('#eventedit:not([data-notabs]) > form').tabs('option', 'active', 0); // Larry

      // show/hide tabs according to calendar's feature support and activate first tab (Ellastic)
      $('li > a[href="#event-panel-attendees"]').parent()[(calendar.attendees?'show':'hide')]();
      $('li > a[href="#event-panel-resources"]').parent()[(rcmail.env.calendar_resources?'show':'hide')]();
      $('li > a[href="#event-panel-attachments"]').parent()[(calendar.attachments?'show':'hide')]();
      if ($('#eventedit').data('notabs'))
        $('#eventedit li.nav-item:first-child a').tab('show');

      // hack: set task to 'calendar' to make all dialog actions work correctly
      var comm_path_before = rcmail.env.comm_path;
      rcmail.env.comm_path = comm_path_before.replace(/_task=[a-z]+/, '_task=calendar');

      var editform = $("#eventedit");

      // open jquery UI dialog
      $dialog.dialog({
        modal: true,
        resizable: true,
        closeOnEscape: false,
        title: rcmail.gettext((action == 'edit' ? 'edit_event' : 'new_event'), 'calendar'),
        open: function() {
          editform.attr('aria-hidden', 'false');
        },
        close: function() {
          editform.hide().attr('aria-hidden', 'true').appendTo(document.body);
          $dialog.dialog("destroy").remove();
          rcmail.ksearch_blur();
          freebusy_data = {};
          rcmail.env.comm_path = comm_path_before;  // restore comm_path
          if (op_elem)
            $(op_elem).focus();
        },
        buttons: buttons,
        minWidth: 500,
        width: 600
      }).append(editform.show());  // adding form content AFTERWARDS massively speeds up opening on IE6

      // set dialog size according to form content
      me.dialog_resize($dialog.get(0), editform.height() + (bw.ie ? 20 : 0), 550);

      title.select();

      // init other tabs asynchronously
      window.setTimeout(function(){ me.set_recurrence_edit(event); }, exec_deferred);
      if (calendar.attendees)
        window.setTimeout(load_attendees_tab, exec_deferred);
      if (calendar.attachments)
        window.setTimeout(load_attachments_tab, exec_deferred);

      rcmail.triggerEvent('calendar-event-dialog', {dialog: $dialog});
    };

    // show event changelog in a dialog
    var event_history_dialog = function(event)
    {
      if (!event.id || !window.libkolab_audittrail)
        return false

      // render dialog
      var $dialog = libkolab_audittrail.object_history_dialog({
        module: 'calendar',
        container: '#eventhistory',
        title: rcmail.gettext('objectchangelog','calendar') + ' - ' + event.title + ', ' + me.event_date_text(event),

        // callback function for list actions
        listfunc: function(action, rev) {
          me.loading_lock = rcmail.set_busy(true, 'loading', me.loading_lock);
          rcmail.http_post('event', { action:action, e:{ id:event.id, calendar:event.calendar, rev: rev } }, me.loading_lock);
        },

        // callback function for comparing two object revisions
        comparefunc: function(rev1, rev2) {
          me.loading_lock = rcmail.set_busy(true, 'loading', me.loading_lock);
          rcmail.http_post('event', { action:'diff', e:{ id:event.id, calendar:event.calendar, rev1: rev1, rev2: rev2 } }, me.loading_lock);
        }
      });

      $dialog.data('event', event);

      // fetch changelog data
      me.loading_lock = rcmail.set_busy(true, 'loading', me.loading_lock);
      rcmail.http_post('event', { action:'changelog', e:{ id:event.id, calendar:event.calendar } }, me.loading_lock);
    };

    // callback from server with changelog data
    var render_event_changelog = function(data)
    {
      var $dialog = $('#eventhistory'),
        event = $dialog.data('event');

      if (data === false || !data.length || !event) {
        // display 'unavailable' message
        $('<div class="notfound-message event-dialog-message warning">' + rcmail.gettext('objectchangelognotavailable','calendar') + '</div>')
          .insertBefore($dialog.find('.changelog-table').hide());
        return;
      }

      data.module = 'calendar';
      libkolab_audittrail.render_changelog(data, event, me.calendars[event.calendar]);

      // set dialog size according to content
      me.dialog_resize($dialog.get(0), $dialog.height(), 600);
    };

    // callback from server with event diff data
    var event_show_diff = function(data)
    {
      var event = me.selected_event,
        $dialog = $("#eventdiff");

      $dialog.find('div.event-section, div.event-line, h1.event-title-new').hide().data('set', false).find('.index').html('');
      $dialog.find('div.event-section.clone, div.event-line.clone').remove();

      // always show event title and date
      $('.event-title', $dialog).text(event.title).removeClass('event-text-old').show();
      $('.event-date', $dialog).text(me.event_date_text(event)).show();

      // show each property change
      $.each(data.changes, function(i,change) {
        var prop = change.property, r2, html = false,
          row = $('div.event-' + prop, $dialog).first();

          // special case: title
          if (prop == 'title') {
            $('.event-title', $dialog).addClass('event-text-old').text(change['old'] || '--');
            $('.event-title-new', $dialog).text(change['new'] || '--').show();
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

          // format dates
          if (['start','end','changed'].indexOf(prop) >= 0) {
            if (change['old']) change.old_ = me.format_datetime(parseISO8601(change['old']));
            if (change['new']) change.new_ = me.format_datetime(parseISO8601(change['new']));
          }
          // render description text
          else if (prop == 'description') {
            // TODO: show real text diff
            if (!change.diff_ && change['old']) change.old_ = text2html(change['old']);
            if (!change.diff_ && change['new']) change.new_ = text2html(change['new']);
            html = true;
          }
          // format attendees struct
          else if (prop == 'attendees') {
            if (change['old']) change.old_ = rcube_libcalendaring.attendee_html(change['old']);
            if (change['new']) change.new_ = rcube_libcalendaring.attendee_html($.extend({}, change['old'] || {}, change['new']));
            html = true;
          }
          // localize priority values
          else if (prop == 'priority') {
            var priolabels = [ '', rcmail.gettext('highest'), rcmail.gettext('high'), '', '', rcmail.gettext('normal'), '', '', rcmail.gettext('low'), rcmail.gettext('lowest') ];
            if (change['old']) change.old_ = change['old'] + ' ' + (priolabels[change['old']] || '');
            if (change['new']) change.new_ = change['new'] + ' ' + (priolabels[change['new']] || '');
          }
          // localize status
          else if (prop == 'status') {
            var status_lc = String(event.status).toLowerCase();
            if (change['old']) change.old_ = rcmail.gettext(String(change['old']).toLowerCase(), 'calendar');
            if (change['new']) change.new_ = rcmail.gettext(String(change['new']).toLowerCase(), 'calendar');
          }

          // format attachments struct
          if (prop == 'attachments') {
            if (change['old']) event_show_attachments([change['old']], row.children('.event-text-old'), event, false);
            else            row.children('.event-text-old').text('--');
            if (change['new']) event_show_attachments([$.extend({}, change['old'] || {}, change['new'])], row.children('.event-text-new'), event, false);
            else            row.children('.event-text-new').text('--');
            // remove click handler as we're currentyl not able to display the according attachment contents
            $('.attachmentslist li a', row).unbind('click').removeAttr('href');
          }
          else if (change.diff_) {
            row.children('.event-text-diff').html(change.diff_);
            row.children('.event-text-old, .event-text-new').hide();
          }
          else {
            if (!html) {
              // escape HTML characters
              change.old_ = Q(change.old_ || change['old'] || '--')
              change.new_ = Q(change.new_ || change['new'] || '--')
            }
            row.children('.event-text-old').html(change.old_ || change['old'] || '--');
            row.children('.event-text-new').html(change.new_ || change['new'] || '--');
          }

          // display index number
          if (typeof change.index != 'undefined') {
            row.find('.index').html('(' + change.index + ')');
          }

          row.show().data('set', true);

          // hide event-date line
          if (prop == 'start' || prop == 'end')
            $('.event-date', $dialog).hide();
      });

      var buttons = [{
        text: rcmail.gettext('close', 'calendar'),
        'class': 'cancel',
        click: function() { $dialog.dialog('close'); }
      }];

      // open jquery UI dialog
      $dialog.dialog({
        modal: false,
        resizable: true,
        closeOnEscape: true,
        title: rcmail.gettext('objectdiff','calendar').replace('$rev1', data.rev1).replace('$rev2', data.rev2) + ' - ' + event.title,
        open: function() {
          $dialog.attr('aria-hidden', 'false');
          setTimeout(function(){
            $dialog.parent().find('.ui-button:not(.ui-dialog-titlebar-close)').first().focus();
          }, 5);
        },
        close: function() {
          $dialog.dialog('destroy').attr('aria-hidden', 'true').hide();
        },
        buttons: buttons,
        minWidth: 320,
        width: 450
      }).show();

      // set dialog size according to content
      me.dialog_resize($dialog.get(0), $dialog.height(), 400);
    };

    // close the event history dialog
    var close_history_dialog = function()
    {
      $('#eventhistory, #eventdiff').each(function(i, elem) {
        var $dialog = $(elem);
        if ($dialog.is(':ui-dialog'))
          $dialog.dialog('close');
      });
    }

    // exports
    this.event_show_diff = event_show_diff;
    this.event_show_dialog = event_show_dialog;
    this.event_history_dialog = event_history_dialog;
    this.render_event_changelog = render_event_changelog;
    this.close_history_dialog = close_history_dialog;

    // open a dialog to display detailed free-busy information and to find free slots
    var event_freebusy_dialog = function()
    {
      var $dialog = $('#eventfreebusy'),
        event = me.selected_event;
      
      if ($dialog.is(':ui-dialog'))
        $dialog.dialog('close');
      
      if (!event_attendees.length)
        return false;
      
      // set form elements
      var allday = $('#edit-allday').get(0);
      var duration = Math.round((event.end.getTime() - event.start.getTime()) / 1000);
      freebusy_ui.startdate = $('#schedule-startdate').val($.fullCalendar.formatDate(event.start, settings['date_format'])).data('duration', duration);
      freebusy_ui.starttime = $('#schedule-starttime').val($.fullCalendar.formatDate(event.start, settings['time_format'])).show();
      freebusy_ui.enddate = $('#schedule-enddate').val($.fullCalendar.formatDate(event.end, settings['date_format']));
      freebusy_ui.endtime = $('#schedule-endtime').val($.fullCalendar.formatDate(event.end, settings['time_format'])).show();
      
      if (allday.checked) {
        freebusy_ui.starttime.val("12:00").hide();
        freebusy_ui.endtime.val("13:00").hide();
        event.allDay = true;
      }
      
      // read attendee roles from drop-downs
      $('select.edit-attendee-role').each(function(i, elem){
        if (event_attendees[i])
          event_attendees[i].role = $(elem).val();
      });
      
      // render time slots
      var now = new Date(), fb_start = new Date(), fb_end = new Date();
      fb_start.setTime(event.start);
      fb_start.setHours(0); fb_start.setMinutes(0); fb_start.setSeconds(0); fb_start.setMilliseconds(0);
      fb_end.setTime(fb_start.getTime() + DAY_MS);
      
      freebusy_data = { required:{}, all:{} };
      freebusy_ui.loading = 1;  // prevent render_freebusy_grid() to load data yet
      freebusy_ui.numdays = Math.max(allday.checked ? 14 : 1, Math.ceil(duration * 2 / 86400));
      freebusy_ui.interval = allday.checked ? 1440 : (60 / (settings.timeslots || 1));
      freebusy_ui.start = fb_start;
      freebusy_ui.end = new Date(freebusy_ui.start.getTime() + DAY_MS * freebusy_ui.numdays);
      render_freebusy_grid(0);
      
      // render list of attendees
      freebusy_ui.attendees = {};
      var domid, dispname, data, role_html, list_html = '';
      for (var i=0; i < event_attendees.length; i++) {
        data = event_attendees[i];
        dispname = Q(data.name || data.email);
        domid = String(data.email).replace(rcmail.identifier_expr, '');
        role_html = '<a class="attendee-role-toggle" id="rcmlia' + domid + '" title="' + Q(rcmail.gettext('togglerole', 'calendar')) + '">&nbsp;</a>';
        list_html += '<div class="attendee ' + String(data.role).toLowerCase() + '" id="rcmli' + domid + '">' + role_html + dispname + '</div>';
        
        // clone attendees data for local modifications
        freebusy_ui.attendees[i] = freebusy_ui.attendees[domid] = $.extend({}, data);
      }
      
      // add total row
      list_html += '<div class="attendee spacer">&nbsp;</div>';
      list_html += '<div class="attendee total">' + rcmail.gettext('reqallattendees','calendar') + '</div>';
      
      $('#schedule-attendees-list').html(list_html)
        .unbind('click.roleicons')
        .bind('click.roleicons', function(e){
          // toggle attendee status upon click on icon
          if (e.target.id && e.target.id.match(/rcmlia(.+)/)) {
            var attendee, domid = RegExp.$1,
                roles = [ 'REQ-PARTICIPANT', 'OPT-PARTICIPANT', 'NON-PARTICIPANT', 'CHAIR' ];
            if ((attendee = freebusy_ui.attendees[domid]) && attendee.role != 'ORGANIZER') {
              var req = attendee.role != 'OPT-PARTICIPANT' && attendee.role != 'NON-PARTICIPANT';
              var j = $.inArray(attendee.role, roles);
              j = (j+1) % roles.length;
              attendee.role = roles[j];
              $(e.target).parent().attr('class', 'attendee '+String(attendee.role).toLowerCase());
              
              // update total display if required-status changed
              if (req != (roles[j] != 'OPT-PARTICIPANT' && roles[j] != 'NON-PARTICIPANT')) {
                compute_freebusy_totals();
                update_freebusy_display(attendee.email);
              }
            }
          }
          
          return false;
        });
      
      // enable/disable buttons
      $('#schedule-find-prev').button('option', 'disabled', (fb_start.getTime() < now.getTime()));
      
      // dialog buttons
      var buttons = [
        {
          text: rcmail.gettext('select', 'calendar'),
          'class': 'save mainaction',
          click: function() {
            $('#edit-startdate').val(freebusy_ui.startdate.val());
            $('#edit-starttime').val(freebusy_ui.starttime.val());
            $('#edit-enddate').val(freebusy_ui.enddate.val());
            $('#edit-endtime').val(freebusy_ui.endtime.val());

            // write role changes back to main dialog
            $('select.edit-attendee-role').each(function(i, elem){
              if (event_attendees[i] && freebusy_ui.attendees[i]) {
                event_attendees[i].role = freebusy_ui.attendees[i].role;
                $(elem).val(event_attendees[i].role);
              }
            });

            if (freebusy_ui.needsupdate)
              update_freebusy_status(me.selected_event);
            freebusy_ui.needsupdate = false;
            $dialog.dialog("close");
          }
        },
        {
          text: rcmail.gettext('cancel', 'calendar'),
          'class': 'cancel',
          click: function() { $dialog.dialog("close"); }
        }
      ];

      $dialog.dialog({
        modal: true,
        resizable: true,
        closeOnEscape: true,
        title: rcmail.gettext('scheduletime', 'calendar'),
        open: function() {
          rcmail.ksearch_blur();
          $dialog.attr('aria-hidden', 'false').find('#schedule-find-next, #schedule-find-prev').not(':disabled').first().focus();
        },
        close: function() {
          $dialog.dialog("destroy").attr('aria-hidden', 'true').hide();
          // TODO: focus opener button
        },
        resizeStop: function() {
          render_freebusy_overlay();
        },
        buttons: buttons,
        minWidth: 640,
        width: 850
      }).show();
      
      // adjust dialog size to fit grid without scrolling
      var gridw = $('#schedule-freebusy-times').width();
      var overflow = gridw - $('#attendees-freebusy-table td.times').width();
      me.dialog_resize($dialog.get(0), $dialog.height() + (bw.ie ? 20 : 0), 800 + Math.max(0, overflow));
      
      // fetch data from server
      freebusy_ui.loading = 0;
      load_freebusy_data(freebusy_ui.start, freebusy_ui.interval);
    };

    // render an HTML table showing free-busy status for all the event attendees
    var render_freebusy_grid = function(delta)
    {
      if (delta) {
        freebusy_ui.start.setTime(freebusy_ui.start.getTime() + DAY_MS * delta);
        fix_date(freebusy_ui.start);
        
        // skip weekends if in workinhoursonly-mode
        if (Math.abs(delta) == 1 && freebusy_ui.workinhoursonly) {
          while (is_weekend(freebusy_ui.start))
            freebusy_ui.start.setTime(freebusy_ui.start.getTime() + DAY_MS * delta);
          fix_date(freebusy_ui.start);
        }
        
        freebusy_ui.end = new Date(freebusy_ui.start.getTime() + DAY_MS * freebusy_ui.numdays);
      }
      
      var dayslots = Math.floor(1440 / freebusy_ui.interval);
      var date_format = 'ddd '+ (dayslots <= 2 ? settings.date_short : settings.date_format);
      var lastdate, datestr, css,
        curdate = new Date(),
        allday = (freebusy_ui.interval == 1440),
        interval = allday ? 1440 : (freebusy_ui.interval * (settings.timeslots || 1));
        times_css = (allday ? 'allday ' : ''),
        dates_row = '<tr class="dates">',
        times_row = '<tr class="times">',
        slots_row = '';

      for (var s = 0, t = freebusy_ui.start.getTime(); t < freebusy_ui.end.getTime(); s++) {
        curdate.setTime(t);
        datestr = fc.fullCalendar('formatDate', curdate, date_format);
        if (datestr != lastdate) {
          if (lastdate && !allday) break;
          dates_row += '<th colspan="' + dayslots + '" class="boxtitle date' + $.fullCalendar.formatDate(curdate, 'ddMMyyyy') + '">' + Q(datestr) + '</th>';
          lastdate = datestr;
        }
        
        // set css class according to working hours
        css = is_weekend(curdate) || (freebusy_ui.interval <= 60 && !is_workinghour(curdate)) ? 'offhours' : 'workinghours';
        times_row += '<td class="' + times_css + css + '" id="t-' + Math.floor(t/1000) + '">' + Q(allday ? rcmail.gettext('all-day','calendar') : $.fullCalendar.formatDate(curdate, settings['time_format'])) + '</td>';
        slots_row += '<td class="' + css + '">&nbsp;</td>';
        
        t += interval * 60000;
      }
      dates_row += '</tr>';
      times_row += '</tr>';
      
      // render list of attendees
      var domid, data, list_html = '', times_html = '';
      for (var i=0; i < event_attendees.length; i++) {
        data = event_attendees[i];
        domid = String(data.email).replace(rcmail.identifier_expr, '');
        times_html += '<tr id="fbrow' + domid + '">' + slots_row + '</tr>';
      }
      
      // add line for all/required attendees
      times_html += '<tr class="spacer"><td colspan="' + (dayslots * freebusy_ui.numdays) + '"></td>';
      times_html += '<tr id="fbrowall">' + slots_row + '</tr>';
      
      var table = $('#schedule-freebusy-times');
      table.children('thead').html(dates_row + times_row);
      table.children('tbody').html(times_html);
      
      // initialize event handlers on grid
      if (!freebusy_ui.grid_events) {
        freebusy_ui.grid_events = true;
        table.children('thead').click(function(e){
          // move event to the clicked date/time
          if (e.target.id && e.target.id.match(/t-(\d+)/)) {
            var newstart = new Date(RegExp.$1 * 1000);
            // set time to 00:00
            if (me.selected_event.allDay) {
              newstart.setMinutes(0);
              newstart.setHours(0);
            }
            update_freebusy_dates(newstart, new Date(newstart.getTime() + freebusy_ui.startdate.data('duration') * 1000));
            render_freebusy_overlay();
          }
        });
      }
      
      // if we have loaded free-busy data, show it
      if (!freebusy_ui.loading) {
        if (freebusy_ui.start < freebusy_data.start || freebusy_ui.end > freebusy_data.end || freebusy_ui.interval != freebusy_data.interval) {
          load_freebusy_data(freebusy_ui.start, freebusy_ui.interval);
        }
        else {
          for (var email, i=0; i < event_attendees.length; i++) {
            if ((email = event_attendees[i].email))
              update_freebusy_display(email);
          }
        }
      }
      
      // render current event date/time selection over grid table
      // use timeout to let the dom attributes (width/height/offset) be set first
      window.setTimeout(function(){ render_freebusy_overlay(); }, 10);
    };
    
    // render overlay element over the grid to visiualize the current event date/time
    var render_freebusy_overlay = function()
    {
      var overlay = $('#schedule-event-time');
      if (me.selected_event.end.getTime() <= freebusy_ui.start.getTime() || me.selected_event.start.getTime() >= freebusy_ui.end.getTime()) {
        overlay.hide();
        if (overlay.data('isdraggable'))
          overlay.draggable('disable');
      }
      else {
        var i, n, table = $('#schedule-freebusy-times'),
          width = 0,
          pos = { top:table.children('thead').height(), left:0 },
          eventstart = date2unixtime(clone_date(me.selected_event.start, me.selected_event.allDay?1:0)),
          eventend = date2unixtime(clone_date(me.selected_event.end, me.selected_event.allDay?2:0)) - 60,
          slotstart = date2unixtime(freebusy_ui.start),
          slotsize = freebusy_ui.interval * 60,
          slotnum = freebusy_ui.interval > 60 ? 1 : (60 / freebusy_ui.interval),
          cells = table.children('thead').find('td'),
          cell_width = cells.first().get(0).offsetWidth,
          slotend;

        // iterate through slots to determine position and size of the overlay
        for (i=0; i < cells.length; i++) {
          for (n=0; n < slotnum; n++) {
            slotend = slotstart + slotsize - 1;
            // event starts in this slot: compute left
            if (eventstart >= slotstart && eventstart <= slotend) {
              pos.left = Math.round(i * cell_width + (cell_width / slotnum) * n);
            }
            // event ends in this slot: compute width
            if (eventend >= slotstart && eventend <= slotend) {
              width = Math.round(i * cell_width + (cell_width / slotnum) * (n + 1)) - pos.left;
            }
            slotstart += slotsize;
          }
        }

        if (!width)
          width = table.width() - pos.left;

        // overlay is visible
        if (width > 0) {
          overlay.css({ width: (width-4)+'px', height:(table.children('tbody').height() - 4)+'px', left:pos.left+'px', top:pos.top+'px' }).show();
          
          // configure draggable
          if (!overlay.data('isdraggable')) {
            overlay.draggable({
              axis: 'x',
              scroll: true,
              stop: function(e, ui){
                // convert pixels to time
                var px = ui.position.left;
                var range_p = $('#schedule-freebusy-times').width();
                var range_t = freebusy_ui.end.getTime() - freebusy_ui.start.getTime();
                var newstart = new Date(freebusy_ui.start.getTime() + px * (range_t / range_p));
                newstart.setSeconds(0); newstart.setMilliseconds(0);
                // snap to day boundaries
                if (me.selected_event.allDay) {
                  if (newstart.getHours() >= 12)  // snap to next day
                    newstart.setTime(newstart.getTime() + DAY_MS);
                  newstart.setMinutes(0);
                  newstart.setHours(0);
                }
                else {
                  // round to 5 minutes
                  // @TODO: round to timeslots?
                  var round = newstart.getMinutes() % 5;
                  if (round > 2.5) newstart.setTime(newstart.getTime() + (5 - round) * 60000);
                  else if (round > 0) newstart.setTime(newstart.getTime() - round * 60000);
                }
                // update event times and display
                update_freebusy_dates(newstart, new Date(newstart.getTime() + freebusy_ui.startdate.data('duration') * 1000));
                if (me.selected_event.allDay)
                  render_freebusy_overlay();
              }
            }).data('isdraggable', true);
          }
          else
            overlay.draggable('enable');
        }
        else
          overlay.draggable('disable').hide();
      }
    };

    // fetch free-busy information for each attendee from server
    var load_freebusy_data = function(from, interval)
    {
      var start = new Date(from.getTime() - DAY_MS * 2);  // start 2 days before event
      fix_date(start);
      var end = new Date(start.getTime() + DAY_MS * Math.max(14, freebusy_ui.numdays + 7));   // load min. 14 days
      freebusy_ui.numrequired = 0;
      freebusy_data.all = [];
      freebusy_data.required = [];

      // load free-busy information for every attendee
      var domid, email;
      for (var i=0; i < event_attendees.length; i++) {
        if ((email = event_attendees[i].email)) {
          domid = String(email).replace(rcmail.identifier_expr, '');
          $('#rcmli' + domid).addClass('loading');
          freebusy_ui.loading++;
          
          $.ajax({
            type: 'GET',
            dataType: 'json',
            url: rcmail.url('freebusy-times'),
            data: { email:email, start:date2servertime(clone_date(start, 1)), end:date2servertime(clone_date(end, 2)), interval:interval, _remote:1 },
            success: function(data) {
              freebusy_ui.loading--;
              
              // find attendee
              var i, attendee = null;
              for (i=0; i < event_attendees.length; i++) {
                if (freebusy_ui.attendees[i].email == data.email) {
                  attendee = freebusy_ui.attendees[i];
                  break;
                }
              }
              
              // copy data to member var
              var ts, status,
                req = attendee.role != 'OPT-PARTICIPANT',
                start = parseISO8601(data.start);

              freebusy_data.start = new Date(start);
              freebusy_data.end = parseISO8601(data.end);
              freebusy_data.interval = data.interval;
              freebusy_data[data.email] = {};

              for (i=0; i < data.slots.length; i++) {
                ts = date2timestring(start, data.interval > 60);
                status = data.slots.charAt(i);
                freebusy_data[data.email][ts] = status
                start = new Date(start.getTime() + data.interval * 60000);
                
                // set totals
                if (!freebusy_data.required[ts])
                  freebusy_data.required[ts] = [0,0,0,0];
                if (req)
                  freebusy_data.required[ts][status]++;
                
                if (!freebusy_data.all[ts])
                  freebusy_data.all[ts] = [0,0,0,0];
                freebusy_data.all[ts][status]++;
              }

              // hide loading indicator
              var domid = String(data.email).replace(rcmail.identifier_expr, '');
              $('#rcmli' + domid).removeClass('loading');
              
              // update display
              update_freebusy_display(data.email);
            }
          });
          
          // count required attendees
          if (freebusy_ui.attendees[i].role != 'OPT-PARTICIPANT')
            freebusy_ui.numrequired++;
        }
      }
    };
    
    // re-calculate total status after role change
    var compute_freebusy_totals = function()
    {
      freebusy_ui.numrequired = 0;
      freebusy_data.all = [];
      freebusy_data.required = [];
      
      var email, req, status;
      for (var i=0; i < event_attendees.length; i++) {
        if (!(email = event_attendees[i].email))
          continue;
        
        req = freebusy_ui.attendees[i].role != 'OPT-PARTICIPANT';
        if (req)
          freebusy_ui.numrequired++;
        
        for (var ts in freebusy_data[email]) {
          if (!freebusy_data.required[ts])
            freebusy_data.required[ts] = [0,0,0,0];
          if (!freebusy_data.all[ts])
            freebusy_data.all[ts] = [0,0,0,0];
          
          status = freebusy_data[email][ts];
          freebusy_data.all[ts][status]++;
          
          if (req)
            freebusy_data.required[ts][status]++;
        }
      }
    };

    // update free-busy grid with status loaded from server
    var update_freebusy_display = function(email)
    {
      var status_classes = ['unknown','free','busy','tentative','out-of-office'];
      var domid = String(email).replace(rcmail.identifier_expr, '');
      var row = $('#fbrow' + domid);
      var rowall = $('#fbrowall').children();
      var dateonly = freebusy_ui.interval > 60,
        t, ts = date2timestring(freebusy_ui.start, dateonly),
        curdate = new Date(),
        fbdata = freebusy_data[email];

      if (fbdata && fbdata[ts] !== undefined && row.length) {
        t = freebusy_ui.start.getTime();
        row.children().each(function(i, cell) {
          var j, n, attr, last, all_slots = [], slots = [],
            all_cell = rowall.get(i),
            cnt = dateonly ? 1 : (60 / freebusy_ui.interval),
            percent = (100 / cnt);

          for (n=0; n < cnt; n++) {
            curdate.setTime(t);
            ts = date2timestring(curdate, dateonly);
            attr = {
              'style': 'float:left; width:' + percent.toFixed(2) + '%',
              'class': fbdata[ts] ? status_classes[fbdata[ts]] : 'unknown'
            };

            slots.push($('<div>').attr(attr));

            // also update total row if all data was loaded
            if (!freebusy_ui.loading && freebusy_data.all[ts] && all_cell) {
              var all_status = freebusy_data.all[ts][2] ? 'busy' : 'unknown',
                req_status = freebusy_data.required[ts][2] ? 'busy' : 'free';

              for (j=1; j < status_classes.length; j++) {
                if (freebusy_ui.numrequired && freebusy_data.required[ts][j] >= freebusy_ui.numrequired)
                  req_status = status_classes[j];
                if (freebusy_data.all[ts][j] == event_attendees.length)
                  all_status = status_classes[j];
              }

              attr['class'] = req_status + ' all-' + all_status;

              // these elements use some specific styling, so we want to minimize their number
              if (last && last.attr('class') == attr['class'])
                last.css('width', (percent + parseFloat(last.css('width').replace('%', ''))).toFixed(2) + '%');
              else {
                last = $('<div>').attr(attr);
                all_slots.push(last);
              }
            }

            t += freebusy_ui.interval * 60000;
          }

          $(cell).html('').append(slots);
          if (all_slots.length)
            $(all_cell).html('').append(all_slots);
        });
      }
    };
    
    // write changed event date/times back to form fields
    var update_freebusy_dates = function(start, end)
    {
      // fix all-day evebt times
      if (me.selected_event.allDay) {
        var numdays = Math.floor((me.selected_event.end.getTime() - me.selected_event.start.getTime()) / DAY_MS);
        start.setHours(12);
        start.setMinutes(0);
        end.setTime(start.getTime() + numdays * DAY_MS);
        end.setHours(13);
        end.setMinutes(0);
      }
      me.selected_event.start = start;
      me.selected_event.end = end;
      freebusy_ui.startdate.val($.fullCalendar.formatDate(start, settings['date_format']));
      freebusy_ui.starttime.val($.fullCalendar.formatDate(start, settings['time_format']));
      freebusy_ui.enddate.val($.fullCalendar.formatDate(end, settings['date_format']));
      freebusy_ui.endtime.val($.fullCalendar.formatDate(end, settings['time_format']));
      freebusy_ui.needsupdate = true;
    };

    // attempt to find a time slot where all attemdees are available
    var freebusy_find_slot = function(dir)
    {
      // exit if free-busy data isn't available yet
      if (!freebusy_data || !freebusy_data.start)
        return false;

      var event = me.selected_event,
        eventstart = clone_date(event.start, event.allDay ? 1 : 0).getTime(),  // calculate with integers
        eventend = clone_date(event.end, event.allDay ? 2 : 0).getTime(),
        duration = eventend - eventstart - (event.allDay ? HOUR_MS : 0),  /* make sure we don't cross day borders on DST change */
        sinterval = freebusy_data.interval * 60000,
        intvlslots = 1,
        numslots = Math.ceil(duration / sinterval),
        fb_start = freebusy_data.start.getTime(),
        fb_end = freebusy_data.end.getTime(),
        checkdate, slotend, email, ts, slot, slotdate = new Date(),
        candidatecount = 0, candidatestart = false, success = false;

      // shift event times to next possible slot
      eventstart += sinterval * intvlslots * dir;
      eventend += sinterval * intvlslots * dir;

      // iterate through free-busy slots and find candidates
      for (slot = dir > 0 ? fb_start : fb_end - sinterval;
            (dir > 0 && slot < fb_end) || (dir < 0 && slot >= fb_start);
            slot += sinterval * dir
      ) {
        slotdate.setTime(slot);
        // fix slot if just crossed a DST change
        if (event.allDay) {
          fix_date(slotdate);
          slot = slotdate.getTime();
        }
        slotend = slot + sinterval;

        if ((dir > 0 && slotend <= eventstart) || (dir < 0 && slot >= eventend))  // skip
          continue;

        // respect workinghours setting
        if (freebusy_ui.workinhoursonly) {
          if (is_weekend(slotdate) || (freebusy_data.interval <= 60 && !is_workinghour(slotdate))) {  // skip off-hours
            candidatestart = false;
            candidatecount = 0;
            continue;
          }
        }

        if (!candidatestart)
          candidatestart = slot;

        ts = date2timestring(slotdate, freebusy_data.interval > 60);

        // check freebusy data for all attendees
        for (var i=0; i < event_attendees.length; i++) {
          if (freebusy_ui.attendees[i].role != 'OPT-PARTICIPANT' && (email = freebusy_ui.attendees[i].email) && freebusy_data[email] && freebusy_data[email][ts] > 1) {
            candidatestart = false;
            break;
          }
        }
        
        // occupied slot
        if (!candidatestart) {
          slot += Math.max(0, intvlslots - candidatecount - 1) * sinterval * dir;
          candidatecount = 0;
          continue;
        }
        else if (dir < 0)
          candidatestart = slot;
        
        candidatecount++;
        
        // if candidate is big enough, this is it!
        if (candidatecount == numslots) {
          event.start.setTime(candidatestart);
          event.end.setTime(candidatestart + duration);
          success = true;
          break;
        }
      }
      
      // update event date/time display
      if (success) {
        update_freebusy_dates(event.start, event.end);
        
        // move freebusy grid if necessary
        var offset = Math.ceil((event.start.getTime() - freebusy_ui.end.getTime()) / DAY_MS);
        if (event.start.getTime() >= freebusy_ui.end.getTime())
          render_freebusy_grid(Math.max(1, offset));
        else if (event.end.getTime() <= freebusy_ui.start.getTime())
          render_freebusy_grid(Math.min(-1, offset));
        else
          render_freebusy_overlay();
        
        var now = new Date();
        $('#schedule-find-prev').button('option', 'disabled', (event.start.getTime() < now.getTime()));
        
        // speak new selection
        rcmail.display_message(rcmail.gettext('suggestedslot', 'calendar') + ': ' + me.event_date_text(event, true), 'voice');
      }
      else {
        alert(rcmail.gettext('noslotfound','calendar'));
      }
    };

    // update event properties and attendees availability if event times have changed
    var event_times_changed = function()
    {
      if (me.selected_event) {
        var allday = $('#edit-allday').get(0);
        me.selected_event.allDay = allday.checked;
        me.selected_event.start = parse_datetime(allday.checked ? '12:00' : $('#edit-starttime').val(), $('#edit-startdate').val());
        me.selected_event.end   = parse_datetime(allday.checked ? '13:00' : $('#edit-endtime').val(), $('#edit-enddate').val());
        if (event_attendees)
          freebusy_ui.needsupdate = true;
        $('#edit-startdate').data('duration', Math.round((me.selected_event.end.getTime() - me.selected_event.start.getTime()) / 1000));
      }
    };

    // add the given list of participants
    var add_attendees = function(names, params)
    {
      names = explode_quoted_string(names.replace(/,\s*$/, ''), ',');

      // parse name/email pairs
      var item, email, name, success = false;
      for (var i=0; i < names.length; i++) {
        email = name = '';
        item = $.trim(names[i]);
        
        if (!item.length) {
          continue;
        } // address in brackets without name (do nothing)
        else if (item.match(/^<[^@]+@[^>]+>$/)) {
          email = item.replace(/[<>]/g, '');
        } // address without brackets and without name (add brackets)
        else if (rcube_check_email(item)) {
          email = item;
        } // address with name
        else if (item.match(/([^\s<@]+@[^>]+)>*$/)) {
          email = RegExp.$1;
          name = item.replace(email, '').replace(/^["\s<>]+/, '').replace(/["\s<>]+$/, '');
        }
        if (email) {
          add_attendee($.extend({ email:email, name:name }, params));
          success = true;
        }
        else {
          alert(rcmail.gettext('noemailwarning'));
        }
      }
      
      return success;
    };

    // add the given attendee to the list
    var add_attendee = function(data, readonly, before)
    {
      if (!me.selected_event)
        return false;

      // check for dupes...
      var exists = false;
      $.each(event_attendees, function(i, v){ exists |= (v.email == data.email); });
      if (exists)
        return false;
      
      var calendar = me.selected_event && me.calendars[me.selected_event.calendar] ? me.calendars[me.selected_event.calendar] : me.calendars[me.selected_calendar];

      var dispname = Q(data.name || data.email);
      if (data.email)
        dispname = '<a href="mailto:' + data.email + '" title="' + Q(data.email) + '" class="mailtolink" data-cutype="' + data.cutype + '">' + dispname + '</a>';
      
      // role selection
      var organizer = data.role == 'ORGANIZER';
      var opts = {};
      if (organizer)
        opts.ORGANIZER = rcmail.gettext('calendar.roleorganizer');
      opts['REQ-PARTICIPANT'] = rcmail.gettext('calendar.rolerequired');
      opts['OPT-PARTICIPANT'] = rcmail.gettext('calendar.roleoptional');
      opts['NON-PARTICIPANT'] = rcmail.gettext('calendar.rolenonparticipant');

      if (data.cutype != 'RESOURCE')
        opts['CHAIR'] =  rcmail.gettext('calendar.rolechair');

      if (organizer && !readonly)
        dispname = rcmail.env['identities-selector'];

      var select = '<select class="edit-attendee-role form-control"'
        + (organizer || readonly ? ' disabled="true"' : '')
        + ' aria-label="' + rcmail.gettext('role','calendar') + '">';
      for (var r in opts)
        select += '<option value="'+ r +'" class="' + r.toLowerCase() + '"' + (data.role == r ? ' selected="selected"' : '') +'>' + Q(opts[r]) + '</option>';
      select += '</select>';

      // delete icon
      var icon = rcmail.env.deleteicon ? '<img src="' + rcmail.env.deleteicon + '" alt="" />' : '<span class="inner">' + Q(rcmail.gettext('delete')) + '</span>';
      var dellink = '<a href="#delete" class="iconlink icon button delete deletelink" title="' + Q(rcmail.gettext('delete')) + '">' + icon + '</a>';
      var tooltip = '', status = (data.status || '').toLowerCase(),
        status_label = rcmail.gettext('status' + status, 'libcalendaring');

      // send invitation checkbox
      var invbox = '<input type="checkbox" class="edit-attendee-reply" value="' + Q(data.email) +'" title="' + Q(rcmail.gettext('calendar.sendinvitations')) + '" '
        + (!data.noreply && settings.itip_notify & 1 ? 'checked="checked" ' : '') + '/>';

      if (data['delegated-to'])
        tooltip = rcmail.gettext('libcalendaring.delegatedto') + ' ' + data['delegated-to'];
      else if (data['delegated-from'])
        tooltip = rcmail.gettext('libcalendaring.delegatedfrom') + ' ' + data['delegated-from'];
      else if (!status && organizer)
        tooltip = rcmail.gettext('statusorganizer', 'libcalendaring');
      else if (status)
        tooltip = status_label;

      // add expand button for groups
      if (data.cutype == 'GROUP') {
        dispname += ' <a href="#expand" data-email="' + Q(data.email) + '" class="iconbutton add expandlink" title="' + rcmail.gettext('expandattendeegroup','libcalendaring') + '">' +
          rcmail.gettext('expandattendeegroup','libcalendaring') + '</a>';
      }

      var avail = data.email ? 'loading' : 'unknown';
      var table = rcmail.env.calendar_resources && data.cutype == 'RESOURCE' ? resources_list : attendees_list;
      var img_src = rcmail.assets_path('program/resources/blank.gif');
      var elastic = $(table).parents('.no-img').length > 0;
      var avail_tag = elastic ? ('<span class="' + avail + '"') : ('<img alt="" src="' + img_src + '" class="availabilityicon '  + avail + '"');
      var html = '<td class="role">' + select + '</td>' +
        '<td class="name"><span class="attendee-name">' + dispname + '</span></td>' +
        '<td class="availability">' + avail_tag + ' data-email="' + data.email + '" /></td>' +
        '<td class="confirmstate"><span class="attendee ' + (status || 'organizer') + '" title="' + Q(tooltip) + '">' + Q(status && !elastic ? status_label : '') + '</span></td>' +
        (data.cutype != 'RESOURCE' ? '<td class="invite">' + (organizer || readonly || !invbox ? '' : invbox) + '</td>' : '') +
        '<td class="options">' + (organizer || readonly ? '' : dellink) + '</td>';

      var tr = $('<tr>')
        .addClass(String(data.role).toLowerCase())
        .html(html);

      if (before)
        tr.insertBefore(before)
      else
        tr.appendTo(table);

      tr.find('a.deletelink').click({ id:(data.email || data.name) }, function(e) { remove_attendee(this, e.data.id); return false; });
      tr.find('a.mailtolink').click(event_attendee_click);
      tr.find('a.expandlink').click(data, function(e) { me.expand_attendee_group(e, add_attendee, remove_attendee); return false; });
      tr.find('input.edit-attendee-reply').click(function() {
        var enabled = $('#edit-attendees-invite:checked').length || $('input.edit-attendee-reply:checked').length;
        $('#eventedit .attendees-commentbox')[enabled ? 'show' : 'hide']();
      });

      // select organizer identity
      if (data.identity_id)
        $('#edit-identities-list').val(data.identity_id);
      
      // check free-busy status
      if (avail == 'loading') {
        check_freebusy_status(tr.find('.availability > *:first'), data.email, me.selected_event);
      }
      
      event_attendees.push(data);
      return true;
    };
    
    // iterate over all attendees and update their free-busy status display
    var update_freebusy_status = function(event)
    {
      attendees_list.find('.availability > *').each(function(i,v) {
        var email, icon = $(this);
        if (email = icon.attr('data-email'))
          check_freebusy_status(icon, email, event);
      });
      
      freebusy_ui.needsupdate = false;
    };
    
    // load free-busy status from server and update icon accordingly
    var check_freebusy_status = function(icon, email, event)
    {
      var calendar = event.calendar && me.calendars[event.calendar] ? me.calendars[event.calendar] : { freebusy:false };
      if (!calendar.freebusy) {
        $(icon).attr('class', 'availabilityicon unknown');
        return;
      }
      
      icon = $(icon).attr('class', 'availabilityicon loading');
      
      $.ajax({
        type: 'GET',
        dataType: 'html',
        url: rcmail.url('freebusy-status'),
        data: { email:email, start:date2servertime(clone_date(event.start, event.allDay?1:0)), end:date2servertime(clone_date(event.end, event.allDay?2:0)), _remote: 1 },
        success: function(status){
          var avail = String(status).toLowerCase();
          icon.removeClass('loading').addClass(avail).attr('title', rcmail.gettext('avail' + avail, 'calendar'));
        },
        error: function(){
          icon.removeClass('loading').addClass('unknown').attr('title', rcmail.gettext('availunknown', 'calendar'));
        }
      });
    };
    
    // remove an attendee from the list
    var remove_attendee = function(elem, id)
    {
      $(elem).closest('tr').remove();
      event_attendees = $.grep(event_attendees, function(data){ return (data.name != id && data.email != id) });
    };

    // open a dialog to display detailed free-busy information and to find free slots
    var event_resources_dialog = function(search)
    {
      var $dialog = $('#eventresourcesdialog');

      if ($dialog.is(':ui-dialog'))
        $dialog.dialog('close');

      // dialog buttons
      var buttons = [
        {
          text: rcmail.gettext('addresource', 'calendar'),
          'class': 'mainaction create',
          click: function() { rcmail.command('add-resource'); }
        },
        {
          text: rcmail.gettext('close'),
          'class': 'cancel',
          click: function() { $dialog.dialog("close"); }
        }
      ];

      // open jquery UI dialog
      $dialog.dialog({
        modal: true,
        resizable: true,
        closeOnEscape: true,
        title: rcmail.gettext('findresources', 'calendar'),
        open: function() {
          rcmail.ksearch_blur();
          $dialog.attr('aria-hidden', 'false');
        },
        close: function() {
          $dialog.dialog('destroy').attr('aria-hidden', 'true').hide();
        },
        resize: function(e) {
          var container = $(rcmail.gui_objects.resourceinfocalendar);
          container.fullCalendar('option', 'height', container.height() + 4);
        },
        buttons: buttons,
        width: 900,
        height: 500
      }).show();

      // define add-button as main action
      $('.ui-dialog-buttonset .ui-button', $dialog.parent()).first().addClass('mainaction').attr('id', 'rcmbtncalresadd');

      me.dialog_resize($dialog.get(0), 540, Math.min(1000, $(window).width() - 50));

      // set search query
      $('#resourcesearchbox').val(search || '');

      // initialize the treelist widget
      if (!resources_treelist) {
        resources_treelist = new rcube_treelist_widget(rcmail.gui_objects.resourceslist, {
          id_prefix: 'rcres',
          id_encode: rcmail.html_identifier_encode,
          id_decode: rcmail.html_identifier_decode,
          selectable: true,
          save_state: true
        });
        resources_treelist.addEventListener('select', function(node) {
          if (resources_data[node.id]) {
            resource_showinfo(resources_data[node.id]);
            rcmail.enable_command('add-resource', me.selected_event && $("#eventedit").is(':visible') ? true : false);
          }
          else {
            rcmail.enable_command('add-resource', false);
            $(rcmail.gui_objects.resourceinfo).hide();
            $(rcmail.gui_objects.resourceownerinfo).hide();
            $(rcmail.gui_objects.resourceinfocalendar).fullCalendar('removeEventSource', resources_events_source);
          }
        });

        // fetch (all) resource data from server
        me.loading_lock = rcmail.set_busy(true, 'loading', me.loading_lock);
        rcmail.http_request('resources-list', {}, me.loading_lock);

        // register button
        rcmail.register_button('add-resource', 'rcmbtncalresadd', 'uibutton');

        // initialize resource calendar display
        var resource_cal = $(rcmail.gui_objects.resourceinfocalendar);
        resource_cal.fullCalendar($.extend({}, fullcalendar_defaults, {
          header: { left: '', center: '', right: '' },
          height: resource_cal.height() + 4,
          defaultView: 'agendaWeek',
          eventSources: [],
          slotMinutes: 60,
          allDaySlot: false,
          eventRender: function(event, element, view) {
            var title = rcmail.get_label(event.status, 'calendar');
            element.addClass('status-' + event.status);
            element.find('.fc-event-head').hide();
            element.find('.fc-event-title').text(title);
            element.attr('aria-label', me.event_date_text(event, true) + ': ' + title);
          }
        }));

        $('#resource-calendar-prev').click(function(){
          resource_cal.fullCalendar('prev');
          return false;
        });
        $('#resource-calendar-next').click(function(){
          resource_cal.fullCalendar('next');
          return false;
        });
      }
      else if (search) {
        resource_search();
      }
      else {
        resource_render_list(resources_index);
      }

      if (me.selected_event && me.selected_event.start) {
        $(rcmail.gui_objects.resourceinfocalendar).fullCalendar('gotoDate', me.selected_event.start);
      }
    };

    // render the resource details UI box
    var resource_showinfo = function(resource)
    {
      // inline function to render a resource attribute
      function render_attrib(value) {
        if (typeof value == 'boolean') {
          return value ? rcmail.get_label('yes') : rcmail.get_label('no');
        }

        return value;
      }

      if (rcmail.gui_objects.resourceinfo) {
        var tr, table = $(rcmail.gui_objects.resourceinfo).show().find('tbody').html(''),
          attribs = $.extend({ name:resource.name }, resource.attributes||{})
          attribs.description = resource.description;

        for (var k in attribs) {
          if (typeof attribs[k] == 'undefined')
            continue;
          table.append($('<tr>').addClass(k)
            .append('<td class="title">' + Q(ucfirst(rcmail.get_label(k, 'calendar'))) + '</td>')
            .append('<td class="value">' + text2html(render_attrib(attribs[k])) + '</td>')
          );
        }

        $(rcmail.gui_objects.resourceownerinfo).hide();
        $(rcmail.gui_objects.resourceinfocalendar).fullCalendar('removeEventSource', resources_events_source);

        if (resource.owner) {
          // display cached data
          if (resource_owners[resource.owner]) {
            resource_owner_load(resource_owners[resource.owner]);
          }
          else {
            // fetch owner data from server
            me.loading_lock = rcmail.set_busy(true, 'loading', me.loading_lock);
            rcmail.http_request('resources-owner', { _id: resource.owner }, me.loading_lock);
          }
        }

        // load resource calendar
        resources_events_source.url = "./?_task=calendar&_action=resources-calendar&_id="+urlencode(resource.ID);
        $(rcmail.gui_objects.resourceinfocalendar).fullCalendar('addEventSource', resources_events_source);
      }
    };

    // callback from server for resource listing
    var resource_data_load = function(data)
    {
      var resources_tree = {};

      // store data by ID
      $.each(data, function(i, rec) {
        resources_data[rec.ID] = rec;

        // assign parent-relations
        if (rec.members) {
          $.each(rec.members, function(j, m){
            resources_tree[m] = rec.ID;
          });
        }
      });

      // walk the parent-child tree to determine the depth of each node
      $.each(data, function(i, rec) {
        rec._depth = 0;
        if (resources_tree[rec.ID])
          rec.parent_id = resources_tree[rec.ID];

        var parent_id = resources_tree[rec.ID];
        while (parent_id) {
          rec._depth++;
          parent_id = resources_tree[parent_id];
        }
      });

      // sort by depth, collection and name
      data.sort(function(a,b) {
        var j = a._type == 'collection' ? 1 : 0,
            k = b._type == 'collection' ? 1 : 0,
            d = a._depth - b._depth;
        if (!d) d = (k - j);
        if (!d) d = b.name < a.name ? 1 : -1;
        return d;
      });

      $.each(data, function(i, rec) {
        resources_index.push(rec.ID);
      });

      // apply search filter...
      if ($('#resourcesearchbox').val() != '')
        resource_search();
      else  // ...or render full list
        resource_render_list(resources_index);

      rcmail.set_busy(false, null, me.loading_lock);
    };

    // renders the given list of resource records into the treelist
    var resource_render_list = function(index) {
      var rec, link;

      resources_treelist.reset();

      $.each(index, function(i, dn) {
        if (rec = resources_data[dn]) {
          link = $('<a>').attr('href', '#')
            .attr('rel', rec.ID)
            .html(Q(rec.name));

          resources_treelist.insert({ id:rec.ID, html:link, classes:[rec._type], collapsed:true }, rec.parent_id, false);
        }
      });
    };

    // callback from server for owner information display
    var resource_owner_load = function(data)
    {
      if (data) {
        // cache this!
        resource_owners[data.ID] = data;

        var table = $(rcmail.gui_objects.resourceownerinfo).find('tbody').html('');

        for (var k in data) {
          if (k == 'event' || k == 'ID')
            continue;

          table.append($('<tr>').addClass(k)
            .append('<td class="title">' + Q(ucfirst(rcmail.get_label(k, 'calendar'))) + '</td>')
            .append('<td class="value">' + text2html(data[k]) + '</td>')
          );
        }

        table.parent().show();
      }
    }

    // quick-filter the loaded resource data
    var resource_search = function()
    {
      var dn, rec, dataset = [],
        q = $('#resourcesearchbox').val().toLowerCase();

      if (q.length && resources_data) {
        // search by iterating over all resource records
        for (dn in resources_data) {
          rec = resources_data[dn];
          if ((rec.name && String(rec.name).toLowerCase().indexOf(q) >= 0)
            || (rec.email && String(rec.email).toLowerCase().indexOf(q) >= 0)
            || (rec.description && String(rec.description).toLowerCase().indexOf(q) >= 0)
          ) {
            dataset.push(rec.ID);
          }
        }

        resource_render_list(dataset);

        // select single match
        if (dataset.length == 1) {
          resources_treelist.select(dataset[0]);
        }
      }
      else {
        $('#resourcesearchbox').val('');
      }
    };

    // 
    var reset_resource_search = function()
    {
      $('#resourcesearchbox').val('').focus();
      resource_render_list(resources_index);
    };

    // 
    var add_resource2event = function()
    {
      var resource = resources_data[resources_treelist.get_selection()];
      if (resource) {
        if (add_attendee($.extend({ role:'REQ-PARTICIPANT', status:'NEEDS-ACTION', cutype:'RESOURCE' }, resource)))
          rcmail.display_message(rcmail.get_label('resourceadded', 'calendar'), 'confirmation');
      }
    }

    // when the user accepts or declines an event invitation
    var event_rsvp = function(response, delegate, replymode)
    {
      var btn;
      if (typeof response == 'object') {
        btn = $(response);
        response = btn.attr('rel')
      }
      else {
        btn = $('#event-rsvp input.button[rel='+response+']');
      }

      // show menu to select rsvp reply mode (current or all)
      if (me.selected_event && me.selected_event.recurrence && !replymode) {
        rcube_libcalendaring.itip_rsvp_recurring(btn, function(resp, mode) {
          event_rsvp(resp, null, mode);
        });
        return;
      }

      if (me.selected_event && me.selected_event.attendees && response) {
        // bring up delegation dialog
        if (response == 'delegated' && !delegate) {
          rcube_libcalendaring.itip_delegate_dialog(function(data) {
            data.rsvp = data.rsvp ? 1 : '';
            event_rsvp('delegated', data, replymode);
          });
          return;
        }

        // update attendee status
        attendees = [];
        for (var data, i=0; i < me.selected_event.attendees.length; i++) {
          data = me.selected_event.attendees[i];
          if (settings.identity.emails.indexOf(';'+String(data.email).toLowerCase()) >= 0) {
            data.status = response.toUpperCase();
            data.rsvp = 0;  // unset RSVP flag

            if (data.status == 'DELEGATED') {
              data['delegated-to'] = delegate.to;
              data.rsvp = delegate.rsvp
            }
            else {
              if (data['delegated-to']) {
                delete data['delegated-to'];
                if (data.role == 'NON-PARTICIPANT' && data.status != 'DECLINED')
                  data.role = 'REQ-PARTICIPANT';
              }
            }

            attendees.push(i)
          }
          else if (response != 'DELEGATED' && data['delegated-from'] &&
              settings.identity.emails.indexOf(';'+String(data['delegated-from']).toLowerCase()) >= 0) {
            delete data['delegated-from'];
          }

          // set free_busy status to transparent if declined (#4425)
          if (data.status == 'DECLINED' || data.role == 'NON-PARTICIPANT') {
            me.selected_event.free_busy = 'free';
          }
          else {
            me.selected_event.free_busy = 'busy';
          }
        }

        // submit status change to server
        var submit_data = $.extend({}, me.selected_event, { source:null, comment:$('#reply-comment-event-rsvp').val(), _savemode: replymode || 'all' }, (delegate || {})),
          noreply = $('#noreply-event-rsvp:checked').length ? 1 : 0;

        // import event from mail (temporary iTip event)
        if (submit_data._mbox && submit_data._uid) {
          me.saving_lock = rcmail.set_busy(true, 'calendar.savingdata');
          rcmail.http_post('mailimportitip', {
            _mbox: submit_data._mbox,
            _uid:  submit_data._uid,
            _part: submit_data._part,
            _status:  response,
            _to: (delegate ? delegate.to : null),
            _rsvp: (delegate && delegate.rsvp) ? 1 : 0,
            _noreply: noreply,
            _comment: submit_data.comment,
            _instance: submit_data._instance,
            _savemode: submit_data._savemode
          });
        }
        else if (settings.invitation_calendars) {
          update_event('rsvp', submit_data, { status:response, noreply:noreply, attendees:attendees });
        }
        else {
          me.saving_lock = rcmail.set_busy(true, 'calendar.savingdata');
          rcmail.http_post('event', { action:'rsvp', e:submit_data, status:response, attendees:attendees, noreply:noreply });
        }

        event_show_dialog(me.selected_event);
      }
    };
    
    // add the given date to the RDATE list
    var add_rdate = function(date)
    {
      var li = $('<li>')
        .attr('data-value', date2servertime(date))
        .html('<span>' + Q($.fullCalendar.formatDate(date, settings['date_format'])) + '</span>')
        .appendTo('#edit-recurrence-rdates');

      $('<a>').attr('href', '#del')
        .addClass('iconbutton delete')
        .html(rcmail.get_label('delete', 'calendar'))
        .attr('title', rcmail.get_label('delete', 'calendar'))
        .appendTo(li);
    };

    // re-sort the list items by their 'data-value' attribute
    var sort_rdates = function()
    {
      var mylist = $('#edit-recurrence-rdates'),
        listitems = mylist.children('li').get();
      listitems.sort(function(a, b) {
         var compA = $(a).attr('data-value');
         var compB = $(b).attr('data-value');
         return (compA < compB) ? -1 : (compA > compB) ? 1 : 0;
      })
      $.each(listitems, function(idx, item) { mylist.append(item); });
    }

    // remove the link reference matching the given uri
    function remove_link(elem)
    {
      var $elem = $(elem), uri = $elem.attr('data-uri');

      me.selected_event.links = $.grep(me.selected_event.links, function(link) { return link.uri != uri; });

      // remove UI list item
      $elem.hide().closest('li').addClass('deleted');
    }

    // post the given event data to server
    var update_event = function(action, data, add)
    {
      me.saving_lock = rcmail.set_busy(true, 'calendar.savingdata');
      rcmail.http_post('calendar/event', $.extend({ action:action, e:data }, (add || {})));
      
      // render event temporarily into the calendar
      if ((data.start && data.end) || data.id) {
        var event = data.id ? $.extend(fc.fullCalendar('clientEvents', function(e){ return e.id == data.id; })[0], data) : data;
        if (data.start)
          event.start = data.start;
        if (data.end)
          event.end = data.end;
        if (data.allday !== undefined)
          event.allDay = data.allday;
        event.editable = false;
        event.temp = true;
        event.className = 'fc-event-cal-'+data.calendar+' fc-event-temp';
        fc.fullCalendar(data.id ? 'updateEvent' : 'renderEvent', event);

        // mark all recurring instances as temp
        if (event.recurrence || event.recurrence_id) {
          var base_id = event.recurrence_id ? event.recurrence_id : String(event.id).replace(/-\d+(T\d{6})?$/, '');
          $.each(fc.fullCalendar('clientEvents', function(e){ return e.id == base_id || e.recurrence_id == base_id; }), function(i,ev) {
            ev.temp = true;
            ev.editable = false;
            event.className += ' fc-event-temp';
            fc.fullCalendar('updateEvent', ev);
          });
        }
      }
    };

    // mouse-click handler to check if the show dialog is still open and prevent default action
    var dialog_check = function(e)
    {
      var showd = $("#eventshow");
      if (showd.is(':visible') && !$(e.target).closest('.ui-dialog').length && !$(e.target).closest('.popupmenu').length) {
        showd.dialog('close');
        e.stopImmediatePropagation();
        ignore_click = true;
        return false;
      }
      else if (ignore_click) {
        window.setTimeout(function(){ ignore_click = false; }, 20);
        return false;
      }
      return true;
    };

    // display confirm dialog when modifying/deleting an event
    var update_event_confirm = function(action, event, data)
    {
      // Allow other plugins to do actions here
      // E.g. when you move/resize the event init wasn't called
      // but we need it as some plugins may modify user identities
      // we depend on here (kolab_delegation)
      rcmail.triggerEvent('calendar-event-init', {o: event});

      if (!data) data = event;
      var decline = false, notify = false, html = '', cal = me.calendars[event.calendar],
        _has_attendees = me.has_attendees(event),
        _is_attendee = _has_attendees && me.is_attendee(event),
        _is_organizer = me.is_organizer(event);

      // event has attendees, ask whether to notify them
      if (_has_attendees) {
        var checked = (settings.itip_notify & 1 ? ' checked="checked"' : '');

        if (action == 'remove' && cal.group != 'shared' && !_is_organizer && _is_attendee) {
          decline = true;
          checked = event.status != 'CANCELLED' ? checked : '';
          html += '<div class="message">' +
            '<label><input class="confirm-attendees-decline" type="checkbox"' + checked + ' value="1" name="decline" />&nbsp;' +
            rcmail.gettext('itipdeclineevent', 'calendar') + 
            '</label></div>';
        }
        else if (_is_organizer) {
          notify = true;
          if (settings.itip_notify & 2) {
            html += '<div class="message">' +
              '<label><input class="confirm-attendees-donotify" type="checkbox"' + checked + ' value="1" name="notify" />&nbsp;' +
                rcmail.gettext((action == 'remove' ? 'sendcancellation' : 'sendnotifications'), 'calendar') +
              '</label></div>';
          }
          else {
            data._notify = settings.itip_notify;
          }
        }
      }

      // recurring event: user needs to select the savemode
      if (event.recurrence) {
        var future_disabled = '', message_label = (action == 'remove' ? 'removerecurringeventwarning' : 'changerecurringeventwarning');

        // disable the 'future' savemode if I'm an attendee
        // reason: no calendaring system supports the thisandfuture range parameter in iTip REPLY
        if (action == 'remove' && !_is_organizer && _is_attendee) {
          future_disabled = ' disabled';
        }

        html += '<div class="message"><span class="ui-icon ui-icon-alert"></span>' +
          rcmail.gettext(message_label, 'calendar') + '</div>' +
          '<div class="savemode">' +
            '<a href="#current" class="button">' + rcmail.gettext('currentevent', 'calendar') + '</a>' +
            '<a href="#future" class="button' + future_disabled + '">' + rcmail.gettext('futurevents', 'calendar') + '</a>' +
            '<a href="#all" class="button">' + rcmail.gettext('allevents', 'calendar') + '</a>' +
            (action != 'remove' ? '<a href="#new" class="button">' + rcmail.gettext('saveasnew', 'calendar') + '</a>' : '') +
          '</div>';
      }
      
      // show dialog
      if (html) {
        var $dialog = $('<div>').html(html);
      
        $dialog.find('a.button').button().filter(':not(.disabled)').click(function(e) {
          data._savemode = String(this.href).replace(/.+#/, '');
          data._notify = settings.itip_notify;

          // open event edit dialog when saving as new
          if (data._savemode == 'new') {
            event._savemode = 'new';
            event_edit_dialog('edit', event);
            fc.fullCalendar('refetchEvents');
          }
          else {
            if ($dialog.find('input.confirm-attendees-donotify').length)
              data._notify = $dialog.find('input.confirm-attendees-donotify').get(0).checked ? 1 : 0;
            if (decline) {
              data._decline = $dialog.find('input.confirm-attendees-decline:checked').length;
              data._notify = 0;
            }
            update_event(action, data);
          }

          $dialog.dialog("close");
          return false;
        });
        
        var buttons = [];

        if (!event.recurrence) {
          buttons.push({
            text: rcmail.gettext((action == 'remove' ? 'delete' : 'save'), 'calendar'),
            'class': action == 'remove' ? 'delete mainaction' : 'save mainaction',
            click: function() {
              data._notify = notify && $dialog.find('input.confirm-attendees-donotify:checked').length ? 1 : 0;
              data._decline = decline && $dialog.find('input.confirm-attendees-decline:checked').length ? 1 : 0;
              update_event(action, data);
              $(this).dialog("close");
            }
          });
        }

        buttons.push({
          text: rcmail.gettext('cancel', 'calendar'),
          'class': 'cancel',
          click: function() {
            $(this).dialog("close");
          }
        });

        $dialog.dialog({
          modal: true,
          width: 460,
          dialogClass: 'warning',
          title: rcmail.gettext((action == 'remove' ? 'removeeventconfirm' : 'changeeventconfirm'), 'calendar'),
          buttons: buttons,
          open: function() {
            setTimeout(function(){
              $dialog.parent().find('.ui-button:not(.ui-dialog-titlebar-close)').first().focus();
            }, 5);
          },
          close: function(){
            $dialog.dialog("destroy").remove();
            if (!rcmail.busy)
              fc.fullCalendar('refetchEvents');
          }
        }).addClass('event-update-confirm').show();
        
        return false;
      }
      // show regular confirm box when deleting
      else if (action == 'remove' && !cal.undelete) {
        if (!confirm(rcmail.gettext('deleteventconfirm', 'calendar')))
          return false;
      }

      // do update
      update_event(action, data);
      
      return true;
    };

    var update_agenda_toolbar = function()
    {
      $('#agenda-listrange').val(fc.fullCalendar('option', 'listRange'));
      $('#agenda-listsections').val(fc.fullCalendar('option', 'listSections'));
    }


    /*** public methods ***/

    /**
     * Remove saving lock and free the UI for new input
     */
    this.unlock_saving = function()
    {
        if (me.saving_lock)
            rcmail.set_busy(false, null, me.saving_lock);
    };

    // opens calendar day-view in a popup
    this.fisheye_view = function(date)
    {
      $('#fish-eye-view:ui-dialog').dialog('close');
      
      // create list of active event sources
      var src, cals = {}, sources = [];
      for (var id in this.calendars) {
        src = $.extend({}, this.calendars[id]);
        src.editable = false;
        src.url = null;
        src.events = [];

        if (src.active) {
          cals[id] = src;
          sources.push(src);
        }
      }
      
      // copy events already loaded
      var events = fc.fullCalendar('clientEvents');
      for (var event, i=0; i< events.length; i++) {
        event = events[i];
        if (event.source && (src = cals[event.source.id])) {
          src.events.push(event);
        }
      }
      
      var h = $(window).height() - 50;
      var dialog = $('<div>')
        .attr('id', 'fish-eye-view')
        .dialog({
          modal: true,
          width: 680,
          height: h,
          title: $.fullCalendar.formatDate(date, 'dddd ' + settings['date_long']),
          buttons: [{
            text: rcmail.gettext('cancel', 'calendar'),
            'class': 'cancel',
            click: function() { $(this).dialog("close"); }
          }],
          close: function(){
            dialog.dialog("destroy");
            me.fisheye_date = null;
          }
        })
        .fullCalendar($.extend({}, fullcalendar_defaults, {
          defaultView: 'agendaDay',
          header: { left: '', center: '', right: '' },
          height: h - 50,
          date: date.getDate(),
          month: date.getMonth(),
          year: date.getFullYear(),
          eventSources: sources
        }));
        
        this.fisheye_date = date;
    };

    // opens the given calendar in a popup dialog
    this.quickview = function(id, shift)
    {
      var src, in_quickview = false;
      $.each(this.quickview_sources, function(i,cal) {
        if (cal.id == id) {
          in_quickview = true;
          src = cal;
        }
      });

      // remove source from quickview
      if (in_quickview && shift) {
        this.quickview_sources = $.grep(this.quickview_sources, function(src) { return src.id != id; });
      }
      else {
        if (!shift) {
          // remove all current quickview event sources
          if (this.quickview_active) {
            fc.fullCalendar('removeEventSources');
          }

          this.quickview_sources = [];

          // uncheck all active quickview icons
          calendars_list.container.find('div.focusview')
            .add('#calendars .searchresults div.focusview')
            .removeClass('focusview')
              .find('a.quickview').attr('aria-checked', 'false');
        }

        if (!in_quickview) {
          // clone and modify calendar properties
          src = $.extend({}, this.calendars[id]);
          src.url += '&_quickview=1';
          this.quickview_sources.push(src);
        }
      }

      // disable quickview
      if (this.quickview_active && !this.quickview_sources.length) {
        // register regular calendar event sources
        $.each(this.calendars, function(k, cal) {
          if (cal.active)
            fc.fullCalendar('addEventSource', cal);
        });

        this.quickview_active = false;
        $('body').removeClass('quickview-active');

        // uncheck all active quickview icons
        calendars_list.container.find('div.focusview')
          .add('#calendars .searchresults div.focusview')
          .removeClass('focusview')
            .find('a.quickview').attr('aria-checked', 'false');
      }
      // activate quickview
      else if (!this.quickview_active) {
        // remove regular calendar event sources
        fc.fullCalendar('removeEventSources');

        // register quickview event sources
        $.each(this.quickview_sources, function(i, src) {
          fc.fullCalendar('addEventSource', src);
        });

        this.quickview_active = true;
        $('body').addClass('quickview-active');
      }
      // update quickview sources
      else if (in_quickview) {
        fc.fullCalendar('removeEventSource', src);
      }
      else if (src) {
        fc.fullCalendar('addEventSource', src);
      }

      // activate quickview icon
      if (this.quickview_active) {
        $(calendars_list.get_item(id)).find('.calendar').first()
          .add('#calendars .searchresults .cal-' + id)
          [in_quickview ? 'removeClass' : 'addClass']('focusview')
            .find('a.quickview').attr('aria-checked', in_quickview ? 'false' : 'true');
      }
    };

    // disable quickview mode
    function reset_quickview()
    {
      // remove all current quickview event sources
      if (me.quickview_active) {
        fc.fullCalendar('removeEventSources');
        me.quickview_sources = [];
      }

      // register regular calendar event sources
      $.each(me.calendars, function(k, cal) {
        if (cal.active)
          fc.fullCalendar('addEventSource', cal);
      });

      // uncheck all active quickview icons
      calendars_list.container.find('div.focusview')
        .add('#calendars .searchresults div.focusview')
        .removeClass('focusview')
          .find('a.quickview').attr('aria-checked', 'false');

      me.quickview_active = false;
      $('body').removeClass('quickview-active');
    };

    //public method to show the print dialog.
    this.print_calendars = function(view)
    {
      if (!view) view = fc.fullCalendar('getView').name;
      var date = fc.fullCalendar('getDate') || new Date();
      var range = fc.fullCalendar('option', 'listRange');
      var sections = fc.fullCalendar('option', 'listSections');
      rcmail.open_window(rcmail.url('print', { view: view, date: date2unixtime(date), range: range, sections: sections, search: this.search_query }), true, true);
    };

    // public method to bring up the new event dialog
    this.add_event = function(templ) {
      if (this.selected_calendar) {
        var now = new Date();
        var date = fc.fullCalendar('getDate');
        if (typeof date != 'Date')
          date = now;
        date.setHours(now.getHours()+1);
        date.setMinutes(0);
        var end = new Date(date.getTime());
        end.setHours(date.getHours()+1);
        event_edit_dialog('new', $.extend({ start:date, end:end, allDay:false, calendar:this.selected_calendar }, templ || {}));
      }
    };

    // delete the given event after showing a confirmation dialog
    this.delete_event = function(event) {
      // show confirm dialog for recurring events, use jquery UI dialog
      return update_event_confirm('remove', event, { id:event.id, calendar:event.calendar, attendees:event.attendees });
    };

    // opens a jquery UI dialog with event properties (or empty for creating a new calendar)
    this.calendar_edit_dialog = function(calendar)
    {
      if (!calendar)
        calendar = { name:'', color:'cc0000', editable:true, showalarms:true };

      var title = rcmail.gettext((calendar.id ? 'editcalendar' : 'createcalendar'), 'calendar'),
        params = {action: calendar.id ? 'form-edit' : 'form-new', c: {id: calendar.id}, _framed: 1},
        $dialog = $('<iframe>').attr('src', rcmail.url('calendar', params)).on('load', function() {
          var contents = $(this).contents();
          contents.find('#calendar-name')
            .prop('disabled', !calendar.editable)
            .val(calendar.editname || calendar.name)
            .select();
          contents.find('#calendar-color')
            .val(calendar.color);
          contents.find('#calendar-showalarms')
            .prop('checked', calendar.showalarms);
        }),
        save_func = function() {
          var data,
            form = $dialog.contents().find('#calendarpropform'),
            name = form.find('#calendar-name');

          // form is not loaded
          if (!form || !form.length)
            return false;

          // do some input validation
          if (!name.val() || name.val().length < 2) {
            rcmail.alert_dialog(rcmail.gettext('invalidcalendarproperties', 'calendar'), function() {
              name.select();
            });

            return false;
          }

          // post data to server
          data = form.serializeJSON();
          if (data.color)
            data.color = data.color.replace(/^#/, '');
          if (calendar.id)
            data.id = calendar.id;

          me.saving_lock = rcmail.set_busy(true, 'calendar.savingdata');
          rcmail.http_post('calendar', { action:(calendar.id ? 'edit' : 'new'), c:data });
          $dialog.dialog("close");
        };

      rcmail.simple_dialog($dialog, title, save_func, {
        width: 600,
        height: 400
      });
    };

    this.calendar_remove = function(calendar)
    {
      this.calendar_destroy_source(calendar.id);
      rcmail.http_post('calendar', { action:'subscribe', c:{ id:calendar.id, active:0, permanent:0, recursive:1 } });
      return true;
    };

    this.calendar_delete = function(calendar)
    {
      var label = calendar.children ? 'deletecalendarconfirmrecursive' : 'deletecalendarconfirm';
      rcmail.confirm_dialog(rcmail.gettext(label, 'calendar'), 'delete', function() {
        rcmail.http_post('calendar', { action:'delete', c:{ id:calendar.id } });
        return true;
      });

      return false;
    };

    this.calendar_refresh_source = function(id)
    {
      // got race-conditions fc.currentFetchID when using refetchEvents,
      // so we remove and add the source instead
      // fc.fullCalendar('refetchEvents', me.calendars[id]);
      fc.fullCalendar('removeEventSource', me.calendars[id]);
      fc.fullCalendar('addEventSource', me.calendars[id]);
    };

    this.calendar_destroy_source = function(id)
    {
      var delete_ids = [];

      if (this.calendars[id]) {
        // find sub-calendars
        if (this.calendars[id].children) {
          for (var child_id in this.calendars) {
            if (String(child_id).indexOf(id) == 0)
              delete_ids.push(child_id);
          }
        }
        else {
          delete_ids.push(id);
        }
      }

      // delete all calendars in the list
      for (var i=0; i < delete_ids.length; i++) {
        id = delete_ids[i];
        calendars_list.remove(id);
        fc.fullCalendar('removeEventSource', this.calendars[id]);
        $('#edit-calendar option[value="'+id+'"]').remove();
        delete this.calendars[id];
      }
    };

    // open a dialog to upload an .ics file with events to be imported
    this.import_events = function(calendar)
    {
      // close show dialog first
      var $dialog = $("#eventsimport"),
        form = rcmail.gui_objects.importform;

      if ($dialog.is(':ui-dialog'))
        $dialog.dialog('close');

      if (calendar)
        $('#event-import-calendar').val(calendar.id);

      var buttons = [
        {
          text: rcmail.gettext('import', 'calendar'),
          'class' : 'mainaction import',
          click: function() {
            if (form && form.elements._data.value) {
              rcmail.async_upload_form(form, 'import_events', function(e) {
                rcmail.set_busy(false, null, me.saving_lock);
                $('.ui-dialog-buttonpane button', $dialog.parent()).button('enable');

                // display error message if no sophisticated response from server arrived (e.g. iframe load error)
                if (me.import_succeeded === null)
                  rcmail.display_message(rcmail.get_label('importerror', 'calendar'), 'error');
              });

              // display upload indicator (with extended timeout)
              var timeout = rcmail.env.request_timeout;
              rcmail.env.request_timeout = 600;
              me.import_succeeded = null;
              me.saving_lock = rcmail.set_busy(true, 'uploading');
              $('.ui-dialog-buttonpane button', $dialog.parent()).button('disable');

              // restore settings
              rcmail.env.request_timeout = timeout;
            }
          }
        },
        {
          text: rcmail.gettext('cancel', 'calendar'),
          'class': 'cancel',
          click: function() { $dialog.dialog("close"); }
        }
      ];

      // open jquery UI dialog
      $dialog.dialog({
        modal: true,
        resizable: false,
        closeOnEscape: false,
        title: rcmail.gettext('importevents', 'calendar'),
        close: function() {
          $('.ui-dialog-buttonpane button', $dialog.parent()).button('enable');
          $dialog.dialog("destroy").hide();
        },
        buttons: buttons,
        width: 520
      }).show();
    };

    // callback from server if import succeeded
    this.import_success = function(p)
    {
      this.import_succeeded = true;
      $("#eventsimport:ui-dialog").dialog('close');
      rcmail.set_busy(false, null, me.saving_lock);
      rcmail.gui_objects.importform.reset();

      if (p.refetch)
        this.refresh(p);
    };

    // callback from server to report errors on import
    this.import_error = function(p)
    {
      this.import_succeeded = false;
      rcmail.set_busy(false, null, me.saving_lock);
      rcmail.display_message(p.message || rcmail.get_label('importerror', 'calendar'), 'error');
    }

    // open a dialog to select calendars for export
    this.export_events = function(calendar)
    {
      // close show dialog first
      var $dialog = $("#eventsexport"),
        form = rcmail.gui_objects.exportform;

      if ($dialog.is(':ui-dialog'))
        $dialog.dialog('close');

      if (calendar)
        $('#event-export-calendar').val(calendar.id);

      $('#event-export-range').change(function(e){
        var custom = $('option:selected', this).val() == 'custom',
          input = $('#event-export-startdate')
        input.parent()[(custom?'show':'hide')]();
        if (custom)
          input.select();
      })

      var buttons = [
        {
          text: rcmail.gettext('export', 'calendar'),
          'class': 'mainaction export',
          click: function() {
            if (form) {
              var start = 0, range = $('#event-export-range option:selected', this).val(),
                source = $('#event-export-calendar option:selected').val(),
                attachmt = $('#event-export-attachments').get(0).checked;

              if (range == 'custom')
                start = date2unixtime(parse_datetime('00:00', $('#event-export-startdate').val()));
              else if (range > 0)
                start = 'today -' + range + ' months';

              rcmail.goto_url('export_events', { source:source, start:start, attachments:attachmt?1:0 }, false);
            }
            $dialog.dialog("close");
          }
        },
        {
          text: rcmail.gettext('cancel', 'calendar'),
          'class': 'cancel',
          click: function() { $dialog.dialog("close"); }
        }
      ];

      // open jquery UI dialog
      $dialog.dialog({
        modal: true,
        resizable: false,
        closeOnEscape: false,
        title: rcmail.gettext('exporttitle', 'calendar'),
        close: function() {
          $('.ui-dialog-buttonpane button', $dialog.parent()).button('enable');
          $dialog.dialog("destroy").hide();
        },
        buttons: buttons,
        width: 520
      }).show();
    };

    // download the selected event as iCal
    this.event_download = function(event)
    {
      if (event && event.id) {
        rcmail.goto_url('export_events', { source:event.calendar, id:event.id, attachments:1 }, false);
      }
    };

    // open the message compose step with a calendar_event parameter referencing the selected event.
    // the server-side plugin hook will pick that up and attach the event to the message.
    this.event_sendbymail = function(event, e)
    {
      if (event && event.id) {
        rcmail.command('compose', { _calendar_event:event._id }, e ? e.target : null, e);
      }
    };

    // display the edit dialog, request 'new' action and pass the selected event
    this.event_copy = function(event) {
      if (event && event.id) {
        var copy = $.extend(true, {}, event);

        delete copy.id;
        delete copy._id;
        delete copy.created;
        delete copy.changed;
        delete copy.recurrence_id;
        delete copy.attachments; // @TODO

        $.each(copy.attendees, function (k, v) {
          if (v.role != 'ORGANIZER') {
            v.status = 'NEEDS-ACTION';
          }
        })

        event_edit_dialog('new', copy);
      }
    };

    // show URL of the given calendar in a dialog box
    this.showurl = function(calendar)
    {
      if (calendar.feedurl) {
        var dialog = $('#calendarurlbox').clone(true);

        if (calendar.caldavurl) {
          $('#caldavurl', dialog).val(calendar.caldavurl);
          $('#calendarcaldavurl', dialog).show();
        }
        else {
          $('#calendarcaldavurl', dialog).hide();
        }

        rcmail.simple_dialog(dialog, rcmail.gettext('showurl', 'calendar'), null, {
          open: function() { $('#calfeedurl', dialog).val(calendar.feedurl).select(); },
          cancel_button: 'close'
        });
      }
    };

    // show free-busy URL in a dialog box
    this.showfburl = function()
    {
      var dialog = $('#fburlbox').clone(true);

      rcmail.simple_dialog(dialog, rcmail.gettext('showfburl', 'calendar'), null, {
        open: function() { $('#fburl', dialog).val(settings.freebusy_url).select(); },
        cancel_button: 'close'
      });
    };

    // refresh the calendar view after saving event data
    this.refresh = function(p)
    {
      var source = me.calendars[p.source];

      // helper function to update the given fullcalendar view
      function update_view(view, event, source) {
        var existing = view.fullCalendar('clientEvents', event._id);
        if (existing.length) {
          $.extend(existing[0], event);
          view.fullCalendar('updateEvent', existing[0]);
          // remove old recurrence instances
          if (event.recurrence && !event.recurrence_id)
            view.fullCalendar('removeEvents', function(e){ return e._id.indexOf(event._id+'-') == 0; });
        }
        else {
          event.source = source;  // link with source
          view.fullCalendar('renderEvent', event);
        }
      }

      // remove temp events
      fc.fullCalendar('removeEvents', function(e){ return e.temp; });

      if (source && (p.refetch || (p.update && !source.active))) {
        // activate event source if new event was added to an invisible calendar
        if (this.quickview_active) {
          // map source to the quickview_sources equivalent
          $.each(this.quickview_sources, function(src) {
            if (src.id == source.id) {
              source = src;
              return false;
            }
          });
          fc.fullCalendar('refetchEvents', source, true);
        }
        else if (!source.active) {
          source.active = true;
          fc.fullCalendar('addEventSource', source);
          $('#rcmlical' + source.id + ' input').prop('checked', true);
        }
        else
          fc.fullCalendar('refetchEvents', source, true);

        fetch_counts();
      }
      // add/update single event object
      else if (source && p.update) {
        var event = p.update;
        event.temp = false;
        event.editable = 0;

          // update fish-eye view
        if (this.fisheye_date)
          update_view($('#fish-eye-view'), event, source);

        // update main view
        event.editable = source.editable;
        update_view(fc, event, source);

        // update the currently displayed event dialog
        if ($('#eventshow').is(':visible') && me.selected_event && me.selected_event.id == event.id)
          event_show_dialog(event)
      }
      // refetch all calendars
      else if (p.refetch) {
        fc.fullCalendar('refetchEvents', undefined, true);
        fetch_counts();
      }
    };

    // modify query parameters for refresh requests
    this.before_refresh = function(query)
    {
      var view = fc.fullCalendar('getView');

      query.start = date2unixtime(view.visStart);
      query.end = date2unixtime(view.visEnd);

      if (this.search_query)
        query.q = this.search_query;

      return query;
    };

    // callback from server providing event counts
    this.update_counts = function(p)
    {
      $.each(p.counts, function(cal, count) {
        var li = calendars_list.get_item(cal),
          bubble = $(li).children('.calendar').find('span.count');

        if (!bubble.length && count > 0) {
          bubble = $('<span>')
            .addClass('count')
            .appendTo($(li).children('.calendar').first())
        }

        if (count > 0) {
          bubble.text(count).show();
        }
        else {
          bubble.text('').hide();
        }
      });
    };

    // callback after an iTip message event was imported
    this.itip_message_processed = function(data)
    {
      // remove temporary iTip source
      fc.fullCalendar('removeEventSource', this.calendars['--invitation--itip']);

      $('#eventshow:ui-dialog').dialog('close');
      this.selected_event = null;

      // refresh destination calendar source
      this.refresh({ source:data.calendar, refetch:true });

      this.unlock_saving();

      // process 'after_action' in mail task
      if (window.opener && window.opener.rcube_libcalendaring)
        window.opener.rcube_libcalendaring.itip_message_processed(data);
    };

    // reload the calendar view by keeping the current date/view selection
    this.reload_view = function()
    {
      var query = { view: fc.fullCalendar('getView').name },
        date = fc.fullCalendar('getDate');
      if (date)
        query.date = date2unixtime(date);
      rcmail.redirect(rcmail.url('', query));
    }

    // update browser location to remember current view
    this.update_state = function()
    {
      var query = { view: current_view },
        date = fc.fullCalendar('getDate');
      if (date)
        query.date = date2unixtime(date);

      if (window.history.replaceState)
        window.history.replaceState({}, document.title, rcmail.url('', query).replace('&_action=', ''));
    };

    this.resource_search = resource_search;
    this.reset_resource_search = reset_resource_search;
    this.add_resource2event = add_resource2event;
    this.resource_data_load = resource_data_load;
    this.resource_owner_load = resource_owner_load;


    /***  event searching  ***/

    // execute search
    this.quicksearch = function()
    {
      if (rcmail.gui_objects.qsearchbox) {
        var q = rcmail.gui_objects.qsearchbox.value;
        if (q != '') {
          var id = 'search-'+q;
          var sources = [];
          
          if (me.quickview_active)
            reset_quickview();
          
          if (this._search_message)
            rcmail.hide_message(this._search_message);
          
          for (var sid in this.calendars) {
            if (this.calendars[sid]) {
              this.calendars[sid].url = this.calendars[sid].url.replace(/&q=.+/, '') + '&q=' + urlencode(q);
              sources.push(sid);
            }
          }
          id += '@'+sources.join(',');
          
          // ignore if query didn't change
          if (this.search_request == id) {
            return;
          }
          // remember current view
          else if (!this.search_request) {
            this.default_view = fc.fullCalendar('getView').name;
          }
          
          this.search_request = id;
          this.search_query = q;
          
          // change to list view
          fc.fullCalendar('option', 'listSections', 'month')
            .fullCalendar('option', 'listRange', Math.max(60, settings['agenda_range']))
            .fullCalendar('changeView', 'table');
          
          update_agenda_toolbar();
          
          // refetch events with new url (if not already triggered by changeView)
          if (!this.is_loading)
            fc.fullCalendar('refetchEvents');
        }
        else  // empty search input equals reset
          this.reset_quicksearch();
      }
    };

    // reset search and get back to normal event listing
    this.reset_quicksearch = function()
    {
      $(rcmail.gui_objects.qsearchbox).val('');
      
      if (this._search_message)
        rcmail.hide_message(this._search_message);
      
      if (this.search_request) {
        // hide bottom links of agenda view
        fc.find('.fc-list-content > .fc-listappend').hide();
        
        // restore original event sources and view mode from fullcalendar
        fc.fullCalendar('option', 'listSections', settings['agenda_sections'])
          .fullCalendar('option', 'listRange', settings['agenda_range']);
        
        update_agenda_toolbar();
        
        for (var sid in this.calendars) {
          if (this.calendars[sid])
            this.calendars[sid].url = this.calendars[sid].url.replace(/&q=.+/, '');
        }
        if (this.default_view)
          fc.fullCalendar('changeView', this.default_view);
        
        if (!this.is_loading)
          fc.fullCalendar('refetchEvents');
        
        this.search_request = this.search_query = null;
      }
    };

    // callback if all sources have been fetched from server
    this.events_loaded = function(count)
    {
      var addlinks, append = '';
      
      // enhance list view when searching
      if (this.search_request) {
        if (!count) {
          this._search_message = rcmail.display_message(rcmail.gettext('searchnoresults', 'calendar'), 'notice');
          append = '<div class="message">' + rcmail.gettext('searchnoresults', 'calendar') + '</div>';
        }
        append += '<div class="fc-bottomlinks formlinks"></div>';
        addlinks = true;
      }
      
      if (fc.fullCalendar('getView').name == 'table') {
        var container = fc.find('.fc-list-content > .fc-listappend');
        if (append) {
          if (!container.length)
            container = $('<div class="fc-listappend"></div>').appendTo(fc.find('.fc-list-content'));
          container.html(append).show();
        }
        else if (container.length)
          container.hide();
        
        // add links to adjust search date range
        if (addlinks) {
          var lc = container.find('.fc-bottomlinks');
          $('<a>').attr('href', '#').html(rcmail.gettext('searchearlierdates', 'calendar')).appendTo(lc).click(function(){
            fc.fullCalendar('incrementDate', 0, -1, 0);
          });
          lc.append(" ");
          $('<a>').attr('href', '#').html(rcmail.gettext('searchlaterdates', 'calendar')).appendTo(lc).click(function(){
            var range = fc.fullCalendar('option', 'listRange');
            if (range < 90) {
              fc.fullCalendar('option', 'listRange', fc.fullCalendar('option', 'listRange') + 30).fullCalendar('render');
              update_agenda_toolbar();
            }
            else
              fc.fullCalendar('incrementDate', 0, 1, 0);
          });
        }
      }
      
      if (this.fisheye_date)
        this.fisheye_view(this.fisheye_date);
    };

    // adjust calendar view size
    this.view_resize = function()
    {
      var footer = fc.fullCalendar('getView').name == 'table' ? $('#agendaoptions').outerHeight() : 0;
      fc.fullCalendar('option', 'height', $('#calendar').height() - footer);
    };

    // mark the given calendar folder as selected
    this.select_calendar = function(id, nolistupdate)
    {
      if (!nolistupdate)
        calendars_list.select(id);

      // trigger event hook
      rcmail.triggerEvent('selectfolder', { folder:id, prefix:'rcmlical' });

      this.selected_calendar = id;

      rcmail.update_state({source: id});
    };

    // register the given calendar to the current view
    var add_calendar_source = function(cal)
    {
      var color, brightness, select, id = cal.id;

      me.calendars[id] = $.extend({
        url: rcmail.url('calendar/load_events', { source: id }),
        className: 'fc-event-cal-'+id,
        id: id
      }, cal);

      // choose black text color when background is bright, white otherwise
      if (color = settings.event_coloring % 2  ? '' : '#' + cal.color) {
        if (/^#([a-f0-9]{2})([a-f0-9]{2})([a-f0-9]{2})$/i.test(color)) {
          // use information about brightness calculation found at
          // http://javascriptrules.com/2009/08/05/css-color-brightness-contrast-using-javascript/
          brightness = (parseInt(RegExp.$1, 16) * 299 + parseInt(RegExp.$2, 16) * 587 + parseInt(RegExp.$3, 16) * 114) / 1000;
          if (brightness > 125)
            me.calendars[id].textColor = 'black';
        }

        me.calendars[id].color = color;
      }

      if (fc && (cal.active || cal.subscribed)) {
        if (cal.active)
          fc.fullCalendar('addEventSource', me.calendars[id]);

        var submit = { id: id, active: cal.active ? 1 : 0 };
        if (cal.subscribed !== undefined)
            submit.permanent = cal.subscribed ? 1 : 0;
        rcmail.http_post('calendar', { action:'subscribe', c:submit });
      }

      // insert to #calendar-select options if writeable
      select = $('#edit-calendar');
      if (fc && me.has_permission(cal, 'i') && select.length && !select.find('option[value="'+id+'"]').length) {
        $('<option>').attr('value', id).html(cal.name).appendTo(select);
      }
    }

    // fetch counts for some calendars from the server
    var fetch_counts = function()
    {
      if (count_sources.length) {
        setTimeout(function() {
          rcmail.http_request('calendar/count', { source:count_sources });
        }, 500);
      }
    };


    /***  startup code  ***/

    // create list of event sources AKA calendars
    var id, cal, active, event_sources = [];
    for (id in rcmail.env.calendars) {
      cal = rcmail.env.calendars[id];
      active = cal.active || false;
      add_calendar_source(cal);

      // check active calendars
      $('#rcmlical'+id+' > .calendar input').prop('checked', active);

      if (active) {
        event_sources.push(this.calendars[id]);
      }
      if (cal.counts) {
        count_sources.push(id);
      }

      if (cal.editable && !this.selected_calendar) {
        this.selected_calendar = id;
        rcmail.enable_command('addevent', true);
      }
    }

    // initialize treelist widget that controls the calendars list
    var widget_class = window.kolab_folderlist || rcube_treelist_widget;
    calendars_list = new widget_class(rcmail.gui_objects.calendarslist, {
      id_prefix: 'rcmlical',
      selectable: true,
      save_state: true,
      keyboard: false,
      searchbox: '#calendarlistsearch',
      search_action: 'calendar/calendar',
      search_sources: [ 'folders', 'users' ],
      search_title: rcmail.gettext('calsearchresults','calendar')
    });
    calendars_list.addEventListener('select', function(node) {
      if (node && node.id && me.calendars[node.id]) {
        me.select_calendar(node.id, true);
        rcmail.enable_command('calendar-edit', 'calendar-showurl', 'calendar-showfburl', true);
        rcmail.enable_command('calendar-delete', me.calendars[node.id].editable);
        rcmail.enable_command('calendar-remove', me.calendars[node.id] && me.calendars[node.id].removable);
      }
    });
    calendars_list.addEventListener('insert-item', function(p) {
      var cal = p.data;
      if (cal && cal.id) {
        add_calendar_source(cal);

        // add css classes related to this calendar to document
        if (cal.css) {
          $('<style type="text/css"></style>')
            .html(cal.css)
            .appendTo('head');
        }
      }
    });
    calendars_list.addEventListener('subscribe', function(p) {
      var cal;
      if ((cal = me.calendars[p.id])) {
        cal.subscribed = p.subscribed || false;
        rcmail.http_post('calendar', { action:'subscribe', c:{ id:p.id, active:cal.active?1:0, permanent:cal.subscribed?1:0 } });
      }
    });
    calendars_list.addEventListener('remove', function(p) {
      if (me.calendars[p.id] && me.calendars[p.id].removable) {
        me.calendar_remove(me.calendars[p.id]);
      }
    });
    calendars_list.addEventListener('search-complete', function(data) {
      if (data.length)
        rcmail.display_message(rcmail.gettext('nrcalendarsfound','calendar').replace('$nr', data.length), 'voice');
      else
        rcmail.display_message(rcmail.gettext('nocalendarsfound','calendar'), 'info');
    });
    calendars_list.addEventListener('click-item', function(event) {
      // handle clicks on quickview icon: temprarily add this source and open in quickview
      if ($(event.target).hasClass('quickview') && event.data) {
        if (!me.calendars[event.data.id]) {
          event.data.readonly = true;
          event.data.active = false;
          event.data.subscribed = false;
          add_calendar_source(event.data);
        }
        me.quickview(event.data.id, event.shiftKey || event.metaKey || event.ctrlKey);
        return false;
      }
    });

    // init (delegate) event handler on calendar list checkboxes
    $(rcmail.gui_objects.calendarslist).on('click', 'input[type=checkbox]', function(e) {
      e.stopPropagation();

      if (me.quickview_active) {
        this.checked = !this.checked;
        return false;
      }

      var id = this.value;
      if (me.calendars[id]) {  // add or remove event source on click
        var action;
        if (this.checked) {
          action = 'addEventSource';
          me.calendars[id].active = true;
        }
        else {
          action = 'removeEventSource';
          me.calendars[id].active = false;
        }

        // adjust checked state of original list item
        if (calendars_list.is_search()) {
          calendars_list.container.find('input[value="'+id+'"]').prop('checked', this.checked);
        }

        // add/remove event source
        fc.fullCalendar(action, me.calendars[id]);
        rcmail.http_post('calendar', { action:'subscribe', c:{ id:id, active:me.calendars[id].active?1:0 } });
      }
    })
    .on('keypress', 'input[type=checkbox]', function(e) {
        // select calendar on <Enter>
        if (e.keyCode == 13) {
            calendars_list.select(this.value);
            return rcube_event.cancel(e);
        }
    })
    // init (delegate) event handler on quickview links
    .on('click', 'a.quickview', function(e) {
      var id = $(this).closest('li').attr('id').replace(/^rcmlical/, '');

      if (calendars_list.is_search())
        id = id.replace(/--xsR$/, '');

      if (me.calendars[id])
        me.quickview(id, e.shiftKey || e.metaKey || e.ctrlKey);

      if (!rcube_event.is_keyboard(e) && this.blur)
        this.blur();

      e.stopPropagation();
      return false;
    });

    // register dbl-click handler to open calendar edit dialog
    $(rcmail.gui_objects.calendarslist).on('dblclick', ':not(.virtual) > .calname', function(e){
      var id = $(this).closest('li').attr('id').replace(/^rcmlical/, '');
      me.calendar_edit_dialog(me.calendars[id]);
    });

    // Make Elastic checkboxes pretty
    if (window.UI && UI.pretty_checkbox) {
      $(rcmail.gui_objects.calendarslist).find('input[type=checkbox]').each(function() {
        UI.pretty_checkbox($(this).addClass('flex-checkbox'));
       });
       calendars_list.addEventListener('add-item', function(prop) {
         UI.pretty_checkbox($(prop.li).find('input').addClass('flex-checkbox'));
       });
    }

    // select default calendar
    if (rcmail.env.source && this.calendars[rcmail.env.source])
      this.selected_calendar = rcmail.env.source;
    else if (settings.default_calendar && this.calendars[settings.default_calendar] && this.calendars[settings.default_calendar].editable)
      this.selected_calendar = settings.default_calendar;
    
    if (this.selected_calendar)
      this.select_calendar(this.selected_calendar);
    
    var viewdate = new Date();
    if (rcmail.env.date)
      viewdate.setTime(fromunixtime(rcmail.env.date));

    // add source with iTip event data for rendering
    if (rcmail.env.itip_events && rcmail.env.itip_events.length) {
      me.calendars['--invitation--itip'] = {
        events: rcmail.env.itip_events,
        className: 'fc-event-cal---invitation--itip',
        color: '#fff',
        textColor: '#333',
        editable: false,
        rights: 'lrs',
        attendees: true
      };
      event_sources.push(me.calendars['--invitation--itip']);
    }

    // initalize the fullCalendar plugin
    var fc = $('#calendar').fullCalendar($.extend({}, fullcalendar_defaults, {
      header: {
        right: 'prev,next today',
        center: 'title',
        left: 'agendaDay,agendaWeek,month,table'
      },
      date: viewdate.getDate(),
      month: viewdate.getMonth(),
      year: viewdate.getFullYear(),
      height: $('#calendar').height(),
      eventSources: event_sources,
      selectable: true,
      selectHelper: false,
      loading: function(isLoading) {
        me.is_loading = isLoading;
        this._rc_loading = rcmail.set_busy(isLoading, 'loading', this._rc_loading);
        // trigger callback
        if (!isLoading)
          me.events_loaded($(this).fullCalendar('clientEvents').length);
      },
      // callback for date range selection
      select: function(start, end, allDay, e, view) {
        var range_select = (!allDay || start.getDate() != end.getDate())
        if (dialog_check(e) && range_select)
          event_edit_dialog('new', { start:start, end:end, allDay:allDay, calendar:me.selected_calendar });
        if (range_select || ignore_click)
          view.calendar.unselect();
      },
      // callback for clicks in all-day box
      dayClick: function(date, allDay, e, view) {
        var now = new Date().getTime();
        if (now - day_clicked_ts < 400 && day_clicked == date.getTime()) {  // emulate double-click on day
          var enddate = new Date(); enddate.setTime(date.getTime() + DAY_MS - 60000);
          return event_edit_dialog('new', { start:date, end:enddate, allDay:allDay, calendar:me.selected_calendar });
        }
        
        if (!ignore_click) {
          view.calendar.gotoDate(date);
          if (day_clicked && new Date(day_clicked).getMonth() != date.getMonth())
            view.calendar.select(date, date, allDay);
        }
        day_clicked = date.getTime();
        day_clicked_ts = now;
      },
      // callback when an event was dragged and finally dropped
      eventDrop: function(event, dayDelta, minuteDelta, allDay, revertFunc) {
        if (event.end == null || event.end.getTime() < event.start.getTime()) {
          event.end = new Date(event.start.getTime() + (allDay ? DAY_MS : HOUR_MS));
        }
        // moved to all-day section: set times to 12:00 - 13:00
        if (allDay && !event.allDay) {
          event.start.setHours(12);
          event.start.setMinutes(0);
          event.start.setSeconds(0);
          event.end.setHours(13);
          event.end.setMinutes(0);
          event.end.setSeconds(0);
        }
        // moved from all-day section: set times to working hours
        else if (event.allDay && !allDay) {
          var newstart = event.start.getTime();
          revertFunc();  // revert to get original duration
          var numdays = Math.max(1, Math.round((event.end.getTime() - event.start.getTime()) / DAY_MS)) - 1;
          event.start = new Date(newstart);
          event.end = new Date(newstart + numdays * DAY_MS);
          event.end.setHours(settings['work_end'] || 18);
          event.end.setMinutes(0);
          
          if (event.end.getTime() < event.start.getTime())
            event.end = new Date(newstart + HOUR_MS);
        }
        
        // send move request to server
        var data = {
          id: event.id,
          calendar: event.calendar,
          start: date2servertime(event.start),
          end: date2servertime(event.end),
          allday: allDay?1:0
        };
        update_event_confirm('move', event, data);
      },
      // callback for event resizing
      eventResize: function(event, delta) {
        // sanitize event dates
        if (event.allDay)
          event.start.setHours(12);
        if (!event.end || event.end.getTime() < event.start.getTime())
          event.end = new Date(event.start.getTime() + HOUR_MS);

        // send resize request to server
        var data = {
          id: event.id,
          calendar: event.calendar,
          start: date2servertime(event.start),
          end: date2servertime(event.end)
        };
        update_event_confirm('resize', event, data);
      },
      viewDisplay: function(view) {
        $('#agendaoptions')[view.name == 'table' ? 'show' : 'hide']();
        if (minical) {
          window.setTimeout(function(){ minical.datepicker('setDate', fc.fullCalendar('getDate')); }, exec_deferred);
          if (view.name != current_view)
            me.view_resize();
          current_view = view.name;
          me.update_state();
        }
      },
      viewRender: function(view) {
        if (fc && view.name == 'month')
          fc.fullCalendar('option', 'maxHeight', Math.floor((view.element.parent().height()-18) / 6) - 35);
      }
    }));

    // if start date is changed, shift end date according to initial duration
    var shift_enddate = function(dateText) {
      var newstart = parse_datetime('0', dateText);
      var newend = new Date(newstart.getTime() + $('#edit-startdate').data('duration') * 1000);
      $('#edit-enddate').val($.fullCalendar.formatDate(newend, me.settings['date_format']));
      event_times_changed();
    };

    // Set as calculateWeek to determine the week of the year based on the ISO 8601 definition.
    // Uses the default $.datepicker.iso8601Week() function but takes firstDay setting into account.
    // This is a temporary fix until http://bugs.jqueryui.com/ticket/8420 is resolved.
    var iso8601Week = datepicker_settings.calculateWeek = function(date) {
      var mondayOffset = Math.abs(1 - datepicker_settings.firstDay);
      return $.datepicker.iso8601Week(new Date(date.getTime() + mondayOffset * 86400000));
    };

    var minical;
    var init_calendar_ui = function()
    {
      // initialize small calendar widget using jQuery UI datepicker
      minical = $('#datepicker').datepicker($.extend(datepicker_settings, {
        inline: true,
        showWeek: true,
        changeMonth: true,
        changeYear: true,
        onSelect: function(dateText, inst) {
          ignore_click = true;
          var d = minical.datepicker('getDate'); //parse_datetime('0:0', dateText);
          fc.fullCalendar('gotoDate', d).fullCalendar('select', d, d, true);
        },
        onChangeMonthYear: function(year, month, inst) {
          minical.data('year', year).data('month', month);
        },
        beforeShowDay: function(date) {
          var view = fc.fullCalendar('getView');
          var active = view.visStart && date.getTime() >= view.visStart.getTime() && date.getTime() < view.visEnd.getTime();
          return [ true, (active ? 'ui-datepicker-activerange ui-datepicker-active-' + view.name : ''), ''];
        }
      })) // set event handler for clicks on calendar week cell of the datepicker widget
        .on('click', 'td.ui-datepicker-week-col', function(e) {
          var cell = $(e.target);
          if (e.target.tagName == 'TD') {
            var base_date = minical.datepicker('getDate');
            if (minical.data('month'))
              base_date.setMonth(minical.data('month')-1);
            if (minical.data('year'))
              base_date.setYear(minical.data('year'));
            base_date.setHours(12);
            base_date.setDate(base_date.getDate() - ((base_date.getDay() + 6) % 7) + datepicker_settings.firstDay);
            var base_kw = iso8601Week(base_date),
              target_kw = parseInt(cell.html()),
              wdiff = target_kw - base_kw;
            if (wdiff > 10)  // year jump
              base_date.setYear(base_date.getFullYear() - 1);
            else if (wdiff < -10)
              base_date.setYear(base_date.getFullYear() + 1);
            // select monday of the chosen calendar week
            var day_off = base_date.getDay() - datepicker_settings.firstDay,
              date = new Date(base_date.getTime() - day_off * DAY_MS + wdiff * 7 * DAY_MS);
            fc.fullCalendar('gotoDate', date).fullCalendar('setDate', date).fullCalendar('changeView', 'agendaWeek');
            minical.datepicker('setDate', date);
          }
        });

      minical.find('.ui-datepicker-inline').attr('aria-labelledby', 'aria-label-minical');

      if (rcmail.env.date) {
        var viewdate = new Date();
        viewdate.setTime(fromunixtime(rcmail.env.date));
        minical.datepicker('setDate', viewdate);
      }

      // init event dialog
      var tab_change = function(event, ui) {
          // newPanel.selector for jQuery-UI 1.10, newPanel.attr('id') for jQuery-UI 1.12, href for Bootstrap tabs
          var tab = (ui ? String(ui.newPanel.selector || ui.newPanel.attr('id')) : $(event.target).attr('href'))
            .replace(/^#?event-panel-/, '').replace(/s$/, '');
          var has_real_attendee = function(attendees) {
              for (var i=0; i < (attendees ? attendees.length : 0); i++) {
                if (attendees[i].cutype != 'RESOURCE')
                  return true;
              }
            };

          if (tab == 'attendee' || tab == 'resource') {
            if (!rcube_event.is_keyboard(event))
              $('#edit-'+tab+'-name').select();
            // update free-busy status if needed
            if (freebusy_ui.needsupdate && me.selected_event)
              update_freebusy_status(me.selected_event);
            // add current user as organizer if non added yet
            if (tab == 'attendee' && !has_real_attendee(event_attendees)) {
              add_attendee($.extend({ role:'ORGANIZER' }, settings.identity));
              $('#edit-attendees-form .attendees-invitebox').show();
            }
          }

          // reset autocompletion on tab change (#3389)
          rcmail.ksearch_blur();

          // display recurrence warning in recurrence tab only
          if (tab == 'recurrence')
            $('#edit-recurrence-frequency').change();
          else
            $('#edit-recurrence-syncstart').hide();
      };

      $('#eventtabs').tabs({activate: tab_change});             // Larry
      $('#eventedit a.nav-link').on('show.bs.tab', tab_change); // Elastic

      $('#edit-enddate').datepicker(datepicker_settings);
      $('#edit-startdate').datepicker(datepicker_settings).datepicker('option', 'onSelect', shift_enddate).change(function(){ shift_enddate(this.value); });
      $('#edit-enddate').datepicker('option', 'onSelect', event_times_changed).change(event_times_changed);
      $('#edit-allday').click(function(){ $('#edit-starttime, #edit-endtime')[(this.checked?'hide':'show')](); event_times_changed(); });

      // configure drop-down menu on time input fields based on jquery UI autocomplete
      $('#edit-starttime, #edit-endtime').each(function() {
        me.init_time_autocomplete(this, {
          container: '#eventedit',
          change: event_times_changed
        });
      });

      // adjust end time when changing start
      $('#edit-starttime').change(function(e) {
        var dstart = $('#edit-startdate'),
          newstart = parse_datetime(this.value, dstart.val()),
          newend = new Date(newstart.getTime() + dstart.data('duration') * 1000);
        $('#edit-endtime').val($.fullCalendar.formatDate(newend, me.settings['time_format']));
        $('#edit-enddate').val($.fullCalendar.formatDate(newend, me.settings['date_format']));
        event_times_changed();
      });

      // register events on alarms and recurrence fields
      me.init_alarms_edit('#edit-alarms');
      me.init_recurrence_edit('#eventedit');

      // reload free-busy status when changing the organizer identity
      $('#eventedit').on('change', '#edit-identities-list', function(e) {
        var email = settings.identities[$(this).val()],
          icon = $(this).closest('tr').find('.availability > *');

        if (email && icon.length) {
          icon.attr('data-email', email);
          check_freebusy_status(icon, email, me.selected_event);
        }
      });

      $('#event-export-startdate').datepicker(datepicker_settings);

      // init attendees autocompletion
      var ac_props;
      // parallel autocompletion
      if (rcmail.env.autocomplete_threads > 0) {
        ac_props = {
          threads: rcmail.env.autocomplete_threads,
          sources: rcmail.env.autocomplete_sources
        };
      }
      rcmail.init_address_input_events($('#edit-attendee-name'), ac_props);
      rcmail.addEventListener('autocomplete_insert', function(e) {
        var success = false;
        if (e.field.name == 'participant') {
          success = add_attendees(e.insert, { role:'REQ-PARTICIPANT', status:'NEEDS-ACTION', cutype:(e.data && e.data.type == 'group' ? 'GROUP' : 'INDIVIDUAL') });
        }
        else if (e.field.name == 'resource' && e.data && e.data.email) {
          success = add_attendee($.extend(e.data, { role:'REQ-PARTICIPANT', status:'NEEDS-ACTION', cutype:'RESOURCE' }));
        }
        if (e.field && success) {
          e.field.value = '';
        }
      });

      $('#edit-attendee-add').click(function(){
        var input = $('#edit-attendee-name');
        rcmail.ksearch_blur();
        if (add_attendees(input.val(), { role:'REQ-PARTICIPANT', status:'NEEDS-ACTION', cutype:'INDIVIDUAL' })) {
          input.val('');
        }
      });

      rcmail.init_address_input_events($('#edit-resource-name'), { action:'calendar/resources-autocomplete' });

      $('#edit-resource-add').click(function(){
        var input = $('#edit-resource-name');
        rcmail.ksearch_blur();
        if (add_attendees(input.val(), { role:'REQ-PARTICIPANT', status:'NEEDS-ACTION', cutype:'RESOURCE' })) {
          input.val('');
        }
      });
      
      $('#edit-resource-find').click(function(){
        event_resources_dialog();
        return false;
      });

      // handle change of "send invitations" checkbox
      $('#edit-attendees-invite').change(function() {
        $('#edit-attendees-donotify,input.edit-attendee-reply').prop('checked', this.checked);
        // hide/show comment field
        $('#eventedit .attendees-commentbox')[this.checked ? 'show' : 'hide']();
      });

      // delegate change event to "send invitations" checkbox
      $('#edit-attendees-donotify').change(function() {
        $('#edit-attendees-invite').click();
        return false;
      });

      $('#edit-attendee-schedule').click(function(){
        event_freebusy_dialog();
      });

      $('#schedule-freebusy-prev').html('&#9668;').button().click(function(){ render_freebusy_grid(-1); });
      $('#schedule-freebusy-next').html('&#9658;').button().click(function(){ render_freebusy_grid(1); }).parent();//FIXME .buttonset();

      $('#schedule-find-prev').button().click(function(){ freebusy_find_slot(-1); });
      $('#schedule-find-next').button().click(function(){ freebusy_find_slot(1); });

      $('#schedule-freebusy-workinghours').click(function(){
        freebusy_ui.workinhoursonly = this.checked;
        $('#workinghourscss').remove();
        if (this.checked)
          $('<style type="text/css" id="workinghourscss"> td.offhours { opacity:0.3; filter:alpha(opacity=30) } </style>').appendTo('head');
      });

      $('#event-rsvp input.button').click(function(e) {
        event_rsvp(this)
      });

      $('#eventedit input.edit-recurring-savemode').change(function(e) {
        var sel = $('input.edit-recurring-savemode:checked').val(),
          disabled = sel == 'current' || sel == 'future';
        $('#event-panel-recurrence input, #event-panel-recurrence select, #event-panel-attachments input').prop('disabled', disabled);
        $('#event-panel-recurrence, #event-panel-attachments')[(disabled?'addClass':'removeClass')]('disabled');
      })

      $('#eventshow .changersvp').click(function(e) {
        var d = $('#eventshow'),
          h = -$(this).closest('.event-line').toggle().height();
        $('#event-rsvp').slideDown(300, function() {
          h += $(this).height();
          me.dialog_resize(d.get(0), d.height() + h, d.outerWidth() - 50);
        });
        return false;
      })

      // register click handler for message links
      $('#edit-event-links, #event-links').on('click', 'li a.messagelink', function(e) {
        rcmail.open_window(this.href);
        if (!rcube_event.is_keyboard(e) && this.blur)
          this.blur();
        return false;
      });

      // register click handler for message delete buttons
      $('#edit-event-links').on('click', 'li a.delete', function(e) {
          remove_link(e.target);
          return false;
      });

      $('#agenda-listrange').change(function(e){
        settings['agenda_range'] = parseInt($(this).val());
        fc.fullCalendar('option', 'listRange', settings['agenda_range']).fullCalendar('render');
        // TODO: save new settings in prefs
      }).val(settings['agenda_range']);

      $('#agenda-listsections').change(function(e){
        settings['agenda_sections'] = $(this).val();
        fc.fullCalendar('option', 'listSections', settings['agenda_sections']).fullCalendar('render');
        // TODO: save new settings in prefs
      }).val(fc.fullCalendar('option', 'listSections'));

      // hide event dialog when clicking somewhere into document
      $(document).bind('mousedown', dialog_check);

      rcmail.set_busy(false, 'loading', ui_loading);
    }

    // initialize more UI elements (deferred)
    window.setTimeout(init_calendar_ui, exec_deferred);

    // fetch counts for some calendars
    fetch_counts();

    // add proprietary css styles if not IE
    if (!bw.ie)
      $('div.fc-content').addClass('rcube-fc-content');
} // end rcube_calendar class


/* calendar plugin initialization */
window.rcmail && rcmail.addEventListener('init', function(evt) {
  // configure toolbar buttons
  rcmail.register_command('addevent', function(){ cal.add_event(); }, true);
  rcmail.register_command('print', function(){ cal.print_calendars(); }, true);

  // configure list operations
  rcmail.register_command('calendar-create', function(){ cal.calendar_edit_dialog(null); }, true);
  rcmail.register_command('calendar-edit', function(){ cal.calendar_edit_dialog(cal.calendars[cal.selected_calendar]); }, false);
  rcmail.register_command('calendar-remove', function(){ cal.calendar_remove(cal.calendars[cal.selected_calendar]); }, false);
  rcmail.register_command('calendar-delete', function(){ cal.calendar_delete(cal.calendars[cal.selected_calendar]); }, false);
  rcmail.register_command('events-import', function(){ cal.import_events(cal.calendars[cal.selected_calendar]); }, true);
  rcmail.register_command('calendar-showurl', function(){ cal.showurl(cal.calendars[cal.selected_calendar]); }, false);
  rcmail.register_command('calendar-showfburl', function(){ cal.showfburl(); }, false);
  rcmail.register_command('event-download', function(){ cal.event_download(cal.selected_event); }, true);
  rcmail.register_command('event-sendbymail', function(p, obj, e){ cal.event_sendbymail(cal.selected_event, e); }, true);
  rcmail.register_command('event-copy', function(){ cal.event_copy(cal.selected_event); }, true);
  rcmail.register_command('event-history', function(p, obj, e){ cal.event_history_dialog(cal.selected_event); }, false);

  // search and export events
  rcmail.register_command('export', function(){ cal.export_events(cal.calendars[cal.selected_calendar]); }, true);
  rcmail.register_command('search', function(){ cal.quicksearch(); }, true);
  rcmail.register_command('reset-search', function(){ cal.reset_quicksearch(); }, true);

  // resource invitation dialog
  rcmail.register_command('search-resource', function(){ cal.resource_search(); }, true);
  rcmail.register_command('reset-resource-search', function(){ cal.reset_resource_search(); }, true);
  rcmail.register_command('add-resource', function(){ cal.add_resource2event(); }, false);

  // register callback commands
  rcmail.addEventListener('plugin.refresh_source', function(data) { cal.calendar_refresh_source(data); });
  rcmail.addEventListener('plugin.destroy_source', function(p){ cal.calendar_destroy_source(p.id); });
  rcmail.addEventListener('plugin.unlock_saving', function(p){ cal.unlock_saving(); });
  rcmail.addEventListener('plugin.refresh_calendar', function(p){ cal.refresh(p); });
  rcmail.addEventListener('plugin.import_success', function(p){ cal.import_success(p); });
  rcmail.addEventListener('plugin.import_error', function(p){ cal.import_error(p); });
  rcmail.addEventListener('plugin.update_counts', function(p){ cal.update_counts(p); });
  rcmail.addEventListener('plugin.reload_view', function(p){ cal.reload_view(p); });
  rcmail.addEventListener('plugin.resource_data', function(p){ cal.resource_data_load(p); });
  rcmail.addEventListener('plugin.resource_owner', function(p){ cal.resource_owner_load(p); });
  rcmail.addEventListener('plugin.render_event_changelog', function(data){ cal.render_event_changelog(data); });
  rcmail.addEventListener('plugin.event_show_diff', function(data){ cal.event_show_diff(data); });
  rcmail.addEventListener('plugin.close_history_dialog', function(data){ cal.close_history_dialog(); });
  rcmail.addEventListener('plugin.event_show_revision', function(data){ cal.event_show_dialog(data, null, true); });
  rcmail.addEventListener('plugin.itip_message_processed', function(data){ cal.itip_message_processed(data); });
  rcmail.addEventListener('requestrefresh', function(q){ return cal.before_refresh(q); });

  // let's go
  var cal = new rcube_calendar_ui($.extend(rcmail.env.calendar_settings, rcmail.env.libcal_settings));

  $(window).resize(function(e) {
    // check target due to bugs in jquery
    // http://bugs.jqueryui.com/ticket/7514
    // http://bugs.jquery.com/ticket/9841
    if (e.target == window) {
      cal.view_resize();
    }
  }).resize();

  // show calendars list when ready
  $('#calendars').css('visibility', 'inherit');

  // show toolbar
  $('#toolbar').show();

});
