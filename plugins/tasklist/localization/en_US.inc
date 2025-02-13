<?php

/**
 * Localizations for the Kolab Tasks plugin
 *
 * Copyright (C) 2014, Kolab Systems AG
 *
 * For translation see https://www.transifex.com/projects/p/kolab/resource/tasklist/
 */

$labels = [];
$labels['navtitle'] = 'Tasks';
$labels['lists'] = 'Tasklists';
$labels['list'] = 'Tasklist';
$labels['tags'] = 'Tags';
$labels['export'] = 'Export';
$labels['exporttitle'] = 'Export to iCalendar';
$labels['exportattachments'] = 'With attachments';
$labels['currentview'] = 'current view';
$labels['tasklistsubscribe'] = 'List permanently';
$labels['listsearchresults'] = 'Available Tasklists';
$labels['findlists'] = 'Find tasklists...';
$labels['findtasks'] = 'Find tasks...';
$labels['searchterms'] = 'Search terms';
$labels['notasklistsfound'] = 'No tasklists found';
$labels['nrtasklistsfound'] = '$nr tasklists found';
$labels['removelist'] = 'Remove from list';
$labels['showcaldavurl'] = 'Show CalDAV URL';
$labels['caldavurldescription'] = 'Copy this address to a <a href="http://en.wikipedia.org/wiki/CalDAV" target="_blank">CalDAV</a> client application (e.g. Evolution or Mozilla Thunderbird) to synchronize this specific tasklist with your computer or mobile device.';

$labels['newtask'] = 'New Task';
$labels['createtask'] = 'Create Task <Enter>';
$labels['createnewtask'] = 'Create new Task (e.g. Saturday, Mow the lawn)';
$labels['createfrommail'] = 'Save as task';
$labels['printtitle'] = 'Print tasks';
$labels['printdescriptions'] = 'Print descriptions';
$labels['mark'] = 'Mark';
$labels['unmark'] = 'Unmark';
$labels['edit'] = 'Edit';
$labels['delete'] = 'Delete';
$labels['title'] = 'Title';
$labels['description'] = 'Description';
$labels['datetime'] = 'Due';
$labels['duetime'] = 'Due time';
$labels['start'] = 'Start';
$labels['starttime'] = 'Start time';
$labels['alarms'] = 'Reminder';
$labels['repeat'] = 'Repeat';
$labels['links'] = 'Reference';
$labels['status'] = 'Status';
$labels['status-needs-action'] = 'Needs action';
$labels['status-in-process'] = 'In process';
$labels['status-completed'] = 'Completed';
$labels['status-cancelled'] = 'Cancelled';
$labels['assignedto'] = 'Assigned to';
$labels['created'] = 'Created';
$labels['changed'] = 'Last Modified';
$labels['taskoptions'] = 'Options';

$labels['all'] = 'All';
$labels['flagged'] = 'Flagged';
$labels['complete'] = 'Complete';
$labels['completeness'] = 'Progress';
$labels['overdue'] = 'Overdue';
$labels['today'] = 'Today';
$labels['tomorrow'] = 'Tomorrow';
$labels['next7days'] = 'Next 7 days';
$labels['later'] = 'Later';
$labels['assigned'] = 'Assigned';
$labels['assignedtitle'] = 'Tasks you assigned to others';
$labels['mytasks'] = 'My tasks';
$labels['mytaskstitle'] = 'Tasks assigned to you';
$labels['nodate'] = 'no date';
$labels['removetag'] = 'Remove';
$labels['removelink'] = 'Remove email reference';
$labels['auto'] = 'Auto';

$labels['taskdetails'] = 'Task details';
$labels['newtask'] = 'New Task';
$labels['edittask'] = 'Edit Task';
$labels['save'] = 'Save';
$labels['cancel'] = 'Cancel';
$labels['saveandnotify'] = 'Save and Notify';
$labels['addsubtask'] = 'Add subtask';
$labels['deletetask'] = 'Delete task';
$labels['deletethisonly'] = 'Delete this task only';
$labels['deletewithchilds'] = 'Delete with all subtasks';
$labels['taskactions'] = 'Task options...';

$labels['tabsummary'] = 'Summary';
$labels['tabrecurrence'] = 'Recurrence';
$labels['tabassignments'] = 'Assignments';
$labels['tabattachments'] = 'Attachments';
$labels['tabsharing'] = 'Sharing';

$labels['edlist'] = 'Edit list';
$labels['editlist'] = 'Edit/Share list';
$labels['deletelist'] = 'Delete list';
$labels['createlist'] = 'Add list';
$labels['listactions'] = 'List options...';
$labels['listname'] = 'Name';
$labels['showalarms'] = 'Show reminders';
$labels['import'] = 'Import';
$labels['importtasks'] = 'Import Tasks';
$labels['viewactions'] = 'View actions';
$labels['focusview'] = 'View only this list';
$labels['activate'] = 'Activate';

// date words
$labels['on'] = 'on';
$labels['at'] = 'at';
$labels['this'] = 'this';
$labels['next'] = 'next';
$labels['yes'] = 'yes';

// messages
$labels['savingdata'] = 'Saving data...';
$labels['errorsaving'] = 'Failed to save data.';
$labels['notasksfound'] = 'No tasks found for the given criteria';
$labels['invalidstartduedates'] = 'Start date must not be greater than due date.';
$labels['invalidstartduetimes'] = 'Start and due dates must either both or none specify a time.';
$labels['recurrencerequiresdate'] = 'Recurring tasks require either a start or due date.';
$labels['deletetasktconfirm'] = 'Do you really want to delete this task?';
$labels['deleteparenttasktconfirm'] = 'Do you really want to delete this task and all its subtasks?';
$labels['deletelistconfirm'] = 'Do you really want to delete this list with all its tasks?';
$labels['deletelistconfirmrecursive'] = 'Do you really want to delete this list with all its sub-lists and tasks?';
$labels['aclnorights'] = 'You do not have administrator rights on this task list.';
$labels['changetaskconfirm'] = 'Update task';
$labels['changeconfirmnotifications'] = 'Do you want to notify the attendees about the modification?';
$labels['partstatupdatenotification'] = 'Do you want to notify the organizer about the status change?';

// (hidden) titles and labels for accessibility annotations
$labels['quickaddinput'] = 'New task date and title';
$labels['arialabelquickaddbox'] = 'Quick add new task';
$labels['arialabelsearchform'] = 'Task search form';
$labels['arialabelquicksearchbox'] = 'Task search input';
$labels['arialabellistsearchform'] = 'Tasklists search form';
$labels['arialabeltaskselector'] = 'List mode';
$labels['arialabeltasklisting'] = 'Tasks listing';
$labels['arialabelsortmenu'] = 'Tasks sorting options';
$labels['arialabeltasklistform'] = 'Tasks list properties';
$labels['arialabeltaskform'] = 'Task editing form';

// attendees
$labels['attendee'] = 'Assignee';
$labels['role'] = 'Role';
$labels['availability'] = 'Avail.';
$labels['confirmstate'] = 'Status';
$labels['addattendee'] = 'Add assignee';
$labels['roleorganizer'] = 'Organizer';
$labels['rolerequired'] = 'Required';
$labels['roleoptional'] = 'Optional';
$labels['rolechair'] = 'Chair';
$labels['rolenonparticipant'] = 'Observer';
$labels['sendinvitations'] = 'Send invitations';
$labels['sendnotifications'] = 'Notify assignees about modifications';
$labels['sendcancellation'] = 'Notify assignees about task cancellation';
$labels['invitationsubject'] = 'You\'ve been assigned to "$title"';
$labels['invitationmailbody'] = "*\$title*\n\nDue: \$date\n\nAssignees: \$attendees\n\n\$description\n\nPlease find attached an iCalendar file with all the task details which you can import to your tasks application.";
$labels['itipupdatesubject'] = '"$title" has been updated';
$labels['itipupdatesubjectempty'] = 'A task that concerns you has been updated';
$labels['itipupdatemailbody'] = "*\$title*\n\nDue: \$date\n\nAssignees: \$attendees\n\nPlease find attached an iCalendar file with the updated task details which you can import to your tasks application.";
$labels['itipcancelsubject'] = '"$title" has been cancelled';
$labels['itipcancelmailbody'] = "*\$title*\n\nDue: \$date\n\nAssignees: \$attendees\n\nThe task has been cancelled by \$organizer.\n\nPlease find attached an iCalendar file with the updated task details.";
$labels['saveintasklist'] = 'save in ';

// history dialog
$labels['taskhistory'] = 'History';
$labels['objectchangelog'] = 'Change History';
$labels['objectdiff'] = 'Changes from $rev1 to $rev2';
$labels['objectnotfound'] = 'Failed to load task data';
$labels['objectchangelognotavailable'] = 'Change history is not available for this task';
$labels['objectdiffnotavailable'] = 'No comparison possible for the selected revisions';
$labels['revisionrestoreconfirm'] = 'Do you really want to restore revision $rev of this task? This will replace the current task with the old version.';
$labels['objectrestoresuccess'] = 'Revision $rev successfully restored';
$labels['objectrestoreerror'] = 'Failed to restore the old revision';

// invitation handling (overrides labels from libcalendaring)
$labels['itipobjectnotfound'] = 'The task referred by this message was not found in your tasks list.';

$labels['itipmailbodyaccepted'] = "\$sender has accepted the assignment to the following task:\n\n*\$title*\n\nDue: \$date\n\nAssignees: \$attendees";
$labels['itipmailbodytentative'] = "\$sender has tentatively accepted the assignment to the following task:\n\n*\$title*\n\nDue: \$date\n\nAssignees: \$attendees";
$labels['itipmailbodydeclined'] = "\$sender has declined the assignment to the following task:\n\n*\$title*\n\nDue: \$date\n\nAssignees: \$attendees";
$labels['itipmailbodycancel'] = "\$sender has rejected your assignment to the following task:\n\n*\$title*\n\nDue: \$date";
$labels['itipmailbodyin-process'] = "\$sender has set the status of the following task to in-process:\n\n*\$title*\n\nDue: \$date";
$labels['itipmailbodycompleted'] = "\$sender has completed the following task:\n\n*\$title*\n\nDue: \$date";
$labels['itipmailbodydelegated'] = "\$sender has delegated the following task:\n\n*\$title*\n\nDue: \$date";
$labels['itipmailbodydelegatedto'] = "\$sender has delegated the following task to you:\n\n*\$title*\n\nDue: \$date";

$labels['attendeeaccepted'] = 'Assignee has accepted';
$labels['attendeetentative'] = 'Assignee has tentatively accepted';
$labels['attendeedeclined'] = 'Assignee has declined';
$labels['attendeedelegated'] = 'Assignee has delegated to $delegatedto';
$labels['attendeein-process'] = 'Assignee is in-process';
$labels['attendeecompleted'] = 'Assignee has completed';

$labels['acceptinvitation'] = 'Do you accept this assignment?';
$labels['itipdeclinetask'] = 'Decline your assignment to this task to the organizer';
$labels['declinedeleteconfirm'] = 'Do you also want to delete this declined task from your tasks list?';
$labels['itipcomment'] = 'Invitation/notification comment';
$labels['itipcommenttitle'] = 'This comment will be attached to the invitation/notification message sent to assignees';
$labels['itipsendsuccess'] = 'Notification sent to assignees';
$labels['errornotifying'] = 'Failed to send notifications to task assignees';
$labels['removefromcalendar'] = 'Remove from my tasks';
$labels['delegateinvitation'] = 'Delegate assignment';

$labels['importtext'] = 'You can upload tasks in <a href="https://wikipedia.org/wiki/ICalendar">iCalendar</a> format (.ics).';
$labels['andnmore'] = '$nr more...';
$labels['savetotasklist'] = 'Save to tasks';
$labels['comment'] = 'Comment';
$labels['rsvpcomment'] = 'Comment';
$labels['errorimportingtask'] = 'Failed to import task(s)';
$labels['importwarningexists'] = 'A copy of this task already exists in your tasklist.';
$labels['importsuccess'] = 'Successfully imported $nr tasks';
$labels['importnone'] = 'No tasks found to be imported';
$labels['importerror'] = 'An error occured while importing';
$labels['newerversionexists'] = 'A newer version of this task already exists! Aborted.';
$labels['nowritetasklistfound'] = 'No tasklist found to save the task';
$labels['importedsuccessfully'] = 'The task was successfully added to \'$list\'';
$labels['updatedsuccessfully'] = 'The task was successfully updated in \'$list\'';
$labels['attendeupdateesuccess'] = 'Successfully updated the participant\'s status';
$labels['itipresponseerror'] = 'Failed to send the response to this task assignment';
$labels['itipinvalidrequest'] = 'This invitation is no longer valid';
$labels['sentresponseto'] = 'Successfully sent assignment response to $mailto';
$labels['successremoval'] = 'The task has been deleted successfully.';
$labels['invalidlistproperties'] = 'Invalid list properties! Please set a valid name.';
