<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Library function for reminders cron function.
 *
 * @package    local_reminders
 * @author     Isuru Weerarathna <uisurumadushanka89@gmail.com>
 * @copyright  2012 Isuru Madushanka Weerarathna
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/reminders/reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/site_reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/user_reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/course_reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/category_reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/group_reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/due_reminder.class.php');


require_once($CFG->dirroot . '/calendar/lib.php');
require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->libdir . '/accesslib.php');

require_once($CFG->dirroot . '/availability/classes/info_module.php');
require_once($CFG->libdir . '/modinfolib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');

require_once($CFG->dirroot . '/local/reminders/locallib.php');

/**
 * ======== CONSTANTS ==========================================
 */

define('REMINDERS_DAYIN_SECONDS', 24 * 3600);

define('REMINDERS_FIRST_CRON_CYCLE_CUTOFF_DAYS', 1);

define('REMINDERS_7DAYSBEFORE_INSECONDS', 7 * 24 * 3600);
define('REMINDERS_3DAYSBEFORE_INSECONDS', 3 * 24 * 3600);
define('REMINDERS_1DAYBEFORE_INSECONDS', 24 * 3600);

define('REMINDERS_SEND_ALL_EVENTS', 50);
define('REMINDERS_SEND_ONLY_VISIBLE', 51);

define('REMINDERS_ACTIVITY_BOTH', 60);
define('REMINDERS_ACTIVITY_ONLY_OPENINGS', 61);
define('REMINDERS_ACTIVITY_ONLY_CLOSINGS', 62);

define('REMINDERS_SEND_AS_NO_REPLY', 70);
define('REMINDERS_SEND_AS_ADMIN', 71);

define('REMINDERS_CALENDAR_EVENT_ADDED', 'CREATED');
define('REMINDERS_CALENDAR_EVENT_UPDATED', 'UPDATED');
define('REMINDERS_CALENDAR_EVENT_REMOVED', 'REMOVED');

define('REMINDERS_CALL_TYPE_PRE', 'PRE');
define('REMINDERS_CALL_TYPE_OVERDUE', 'OVERDUE');

define('REMINDERS_CLEAN_TABLE', 'local_reminders');

/**
 * ======== FUNCTIONS =========================================
 */

/**
 * Function to be run periodically according to the moodle cron
 * Finds all events due for a reminder and send them out to the users.
 *
 */
function local_reminders_cron_task() {
    global $CFG;

    if (!isset($CFG->local_reminders_enable) || !$CFG->local_reminders_enable) {
        mtrace("   [Local Reminder] This cron cycle will be skipped, because plugin is not enabled!");
        return;
    }

    $currtime = time();
    local_reminders_cron_pre($currtime);

    // Send reminders for overdue activities.
    local_reminders_cron_overdue_activity($currtime);
}

/**
 * Runs and send reminders before an event occurred.
 *
 * @param int $currtime current time with epoch.
 * @return void nothing.
 */
function local_reminders_cron_pre($currtime) {
    global $CFG, $DB, $USER;

    $aheaddaysindex = array(7 => 0, 3 => 1, 1 => 2);
    $eventtypearray = array('site', 'user', 'course', 'due', 'group');

    // Loading roles allowed to receive reminder messages from configuration.
    $tmprolesreminders = get_roles_for_reminders();
    $courseroleids = $tmprolesreminders[0];
    $activityroleids = $tmprolesreminders[1];
    $categoryroleids = $tmprolesreminders[2];

    // We need only last record only, so we limit the returning number of rows at most by one.
    $logrows = $DB->get_records("local_reminders", array(), 'time DESC', '*', 0, 1);

    $timewindowstart = $currtime;
    if (!$logrows) {  // This is the first cron cycle, after plugin is just installed.
        mtrace("   [Local Reminder] This is the first cron cycle");
        $timewindowstart = $timewindowstart - REMINDERS_FIRST_CRON_CYCLE_CUTOFF_DAYS * 24 * 3600;
    } else {
        // Info field includes that starting time of last cron cycle.
        $firstrecord = current($logrows);
        $timewindowstart = $firstrecord->time + 1;
    }

    // End of the time window will be set as current.
    $timewindowend = $currtime;

    // Now lets filter appropiate events to send reminders.
    $secondsaheads = array(REMINDERS_7DAYSBEFORE_INSECONDS,
        REMINDERS_3DAYSBEFORE_INSECONDS,
        REMINDERS_1DAYBEFORE_INSECONDS);

    // Append custom schedule if any of event categories has defined it.
    foreach ($eventtypearray as $etype) {
        $tempconfigstr = 'local_reminders_'.$etype.'custom';
        if (isset($CFG->$tempconfigstr) && !empty($CFG->$tempconfigstr)
            && $CFG->$tempconfigstr > 0 && !in_array($CFG->$tempconfigstr, $secondsaheads)) {
            array_push($secondsaheads, $CFG->$tempconfigstr);
        }
    }

    $whereclause = '(timestart > '.$timewindowend.') AND (';
    $flagor = false;
    foreach ($secondsaheads as $sahead) {
        if ($flagor) {
            $whereclause .= ' OR ';
        }
        $whereclause .= '(timestart - '.$sahead.' >= '.$timewindowstart.' AND '.
            'timestart - '.$sahead.' <= '.$timewindowend.')';
        $flagor = true;
    }
    $whereclause .= ')';

    if (isset($CFG->local_reminders_filterevents)) {
        if ($CFG->local_reminders_filterevents == REMINDERS_SEND_ONLY_VISIBLE) {
            $whereclause .= ' AND visible = 1';
        }
    }

    mtrace("   [Local Reminder] Time window: ".userdate($timewindowstart)." to ".userdate($timewindowend));

    $upcomingevents = $DB->get_records_select('event', $whereclause);
    if (!$upcomingevents) {
        mtrace("   [Local Reminder] No upcoming events. Aborting...");

        add_flag_record_db($timewindowend, 'no_events');
        return;
    }

    mtrace("   [Local Reminder] Found ".count($upcomingevents)." upcoming events. Continuing...");

    $fromuser = get_from_user();

    $multilang_filter = get_multilangsecond_filter(); // get multilang2 filter instanz

    $allemailfailed = true;
    foreach ($upcomingevents as $event) {
        $event = new calendar_event($event);

        $aheadday = 0;
        $diffinseconds = $event->timestart - $timewindowend;
        $fromcustom = false;

        if ($event->timestart - REMINDERS_1DAYBEFORE_INSECONDS >= $timewindowstart &&
            $event->timestart - REMINDERS_1DAYBEFORE_INSECONDS <= $timewindowend) {
            $aheadday = 1;
        } else if ($event->timestart - REMINDERS_3DAYSBEFORE_INSECONDS >= $timewindowstart &&
            $event->timestart - REMINDERS_3DAYSBEFORE_INSECONDS <= $timewindowend) {
            $aheadday = 3;
        } else if ($event->timestart - REMINDERS_7DAYSBEFORE_INSECONDS >= $timewindowstart &&
            $event->timestart - REMINDERS_7DAYSBEFORE_INSECONDS <= $timewindowend) {
            $aheadday = 7;
        } else {
            // Find if custom schedule has been defined by user.
            $tempconfigstr = 'local_reminders_'.$event->eventtype.'custom';
            if (isset($CFG->$tempconfigstr) && !empty($CFG->$tempconfigstr) && $CFG->$tempconfigstr > 0) {
                $customsecs = $CFG->$tempconfigstr;
                if ($event->timestart - $customsecs >= $timewindowstart &&
                    $event->timestart - $customsecs <= $timewindowend) {
                    $aheadday = $customsecs / (REMINDERS_DAYIN_SECONDS * 1.0);
                    $fromcustom = true;
                }
            }
        }

        mtrace("   [Local Reminder] Processing event in ahead of $aheadday days.");
        if ($diffinseconds < 0) {
            mtrace('   [Local Reminder] Skipping event because it might have expired.');
            continue;
        }
        mtrace("   [Local Reminder] Processing event#$event->id [Type: $event->eventtype, inaheadof=$aheadday days]...");

        if (!$fromcustom) {
            $optionstr = 'local_reminders_' . $event->eventtype . 'rdays';
            if (!isset($CFG->$optionstr)) {
                if ($event->modulename) {
                    $optionstr = 'local_reminders_duerdays';
                } else {
                    mtrace("   [Local Reminder] Couldn't find option for event $event->id [type: $event->eventtype]");
                    continue;
                }
            }

            $options = $CFG->$optionstr;

            if (empty($options) || $options == null) {
                mtrace("   [Local Reminder] No configuration for eventtype $event->eventtype " .
                    "[event#$event->id is ignored!]...");
                continue;
            }

            // This reminder will not be set up to send by configurations.
            if ($options[$aheaddaysindex[$aheadday]] == '0') {
                mtrace("   [Local Reminder] No reminder is due in ahead of $aheadday for eventtype $event->eventtype " .
                    "[event#$event->id is ignored!]...");
                continue;
            }

        } else {
            mtrace("   [Local Reminder] A reminder can be sent for event#$event->id ".
                ", detected through custom schedule.");
        }

        $reminderref = null;
        mtrace("   [Local Reminder] Finding out users for event#".$event->id."...");

        try {
            switch ($event->eventtype) {
                case 'site':
                    $reminderref = process_site_event($event, $aheadday);
                    break;

                case 'user':
                    $reminderref = process_user_event($event, $aheadday);
                    break;

                case 'category':
                    $reminderref = process_category_event($event, $aheadday, $categoryroleids);
                    break;

                case 'course':
                    $reminderref = process_course_event($event, $aheadday, $courseroleids);
                    break;

                case 'open':
                    // If we dont want to send reminders for activity openings.
                    if (isset($CFG->local_reminders_duesend) && $CFG->local_reminders_duesend == REMINDERS_ACTIVITY_ONLY_CLOSINGS) {
                        mtrace("  [Local Reminder] Reminders for activity openings has been restricted in the configs.");
                        break;
                    }
                case 'close':
                    // If we dont want to send reminders for activity closings.
                    if (isset($CFG->local_reminders_duesend) && $CFG->local_reminders_duesend == REMINDERS_ACTIVITY_ONLY_OPENINGS) {
                        mtrace("  [Local Reminder] Reminders for activity closings has been restricted in the configs.");
                        break;
                    }
                case 'due':
                    if (has_disabled_reminders_for_activity($event->courseid, $event->id)) {
                        mtrace("  [Local Reminder] Activity event $event->id reminders disabled in the course settings.");
                        break;
                    } else if (has_disabled_reminders_for_activity($event->courseid, $event->id, "days$aheadday")) {
                        mtrace("  [Local Reminder] Activity event $event->id reminders disabled for $aheadday days ahead.");
                        break;
                    }
                    $reminderref = process_activity_event($event, $aheadday, $activityroleids, REMINDERS_CALL_TYPE_PRE);
                    break;

                case 'group':
                    $reminderref = process_group_event($event, $aheadday);
                    break;

                default:
                    $reminderref = process_unknown_event($event, $aheadday, $activityroleids);
            }

        } catch (Exception $ex) {
            mtrace("  [Local Reminder - ERROR] Error occured when initializing ".
                "for event#[$event->id] (type: $event->eventtype) ".$ex->getMessage());
            mtrace("  [Local Reminder - ERROR] ".$ex->getTraceAsString());
            continue;
        }

        if ($reminderref == null) {
            mtrace("  [Local Reminder] Reminder is not available for the event $event->id [type: $event->eventtype]");
            continue;
        }

        $usize = $reminderref->get_total_users_to_send();
        if ($usize == 0) {
            mtrace("  [Local Reminder] No users found to send reminder for the event#$event->id");
            continue;
        }

        mtrace("  [Local Reminder] Starting sending reminders for $event->id [type: $event->eventtype]");
        $failedcount = 0;

        $sendusers = $reminderref->get_sending_users();
        foreach ($sendusers as $touser) {
            $eventdata = $reminderref->get_event_to_send($fromuser, $touser);

            if(!empty($multilang_filter)){
                $global_user = $USER; // store global user obj.
                $USER = $touser; // replace global user obj. with current touser obj. for correct language information when executing filter
                $eventdata->fullmessage = $multilang_filter->filter($eventdata->fullmessage);
                $eventdata->fullmessagehtml = $multilang_filter->filter($eventdata->fullmessagehtml);
                $eventdata->smallmessage = $multilang_filter->filter($eventdata->smallmessage);
                $USER = $global_user; // bring back global user obj
            }

            try {
                $mailresult = message_send($eventdata);
                mtrace('[LOCAL_REMINDERS] Mail Result: '.$mailresult);

                if (!$mailresult) {
                    throw new coding_exception("Could not send out message for event#$event->id to user $eventdata->userto");
                }
            } catch (moodle_exception $mex) {
                $failedcount++;
                mtrace('Error: local/reminders/lib.php local_reminders_cron(): '.$mex->getMessage());
            }
        }

        if ($failedcount > 0) {
            mtrace("  [Local Reminder] Failed to send $failedcount reminders to users for event#$event->id");
        } else {
            mtrace("  [Local Reminder] All reminders was sent successfully for event#$event->id !");
        }

        if ($usize != $failedcount) {
            $allemailfailed = false;
        }
        $reminderref->cleanup();
    }

    if (!$allemailfailed) {
        add_flag_record_db($timewindowend, 'sent');
        mtrace('  [Local Reminder] Marked this reminder execution as success.');
    } else {
        mtrace('  [Local Reminder] Failed to send any email to any user! Will retry again next time.');
    }
}

/**
 * Runs and sends reminders for overdue activities.
 *
 * @param int $currtime current time in epoch.
 * @return void
 */
function local_reminders_cron_overdue_activity($currtime) {
    // Loading roles allowed to receive reminder messages from configuration.
    $rolesofsystem = get_roles_for_reminders();
    $fromuser = get_from_user();
    send_overdue_activity_reminders($currtime, $rolesofsystem[1], $fromuser);
}

/**
 * Adds a database record to local_reminders table, to mark
 * that the current cron cycle is over. Then we flag the time
 * of end of the cron time window, so that no reminders sent
 * twice.
 *
 * @param int $timewindowend cron window time end.
 * @param string $crontype type of reminders cron.
 * @return void nothing.
 */
function add_flag_record_db($timewindowend, $crontype = '') {
    global $DB;

    $newrecord = new stdClass();
    $newrecord->time = $timewindowend;
    $newrecord->type = $crontype;
    $DB->insert_record("local_reminders", $newrecord);
}

/**
 * Returns false if and only if it is permitted as specified in the settings.
 * Otherwise returns true.
 *
 * @param string $changetype event change type.
 * @return boolean true if now allowed.
 */
function has_denied_for_events($changetype) {
    global $CFG;

    if ($changetype == REMINDERS_CALENDAR_EVENT_UPDATED) {
        return !isset($CFG->local_reminders_enable_whenchanged) || !$CFG->local_reminders_enable_whenchanged;
    } else if ($changetype == REMINDERS_CALENDAR_EVENT_ADDED) {
        return !isset($CFG->local_reminders_enable_whenadded) || !$CFG->local_reminders_enable_whenadded;
    } else if ($changetype == REMINDERS_CALENDAR_EVENT_REMOVED) {
        return !isset($CFG->local_reminders_enable_whenremoved) || !$CFG->local_reminders_enable_whenremoved;
    }
    return false;
}

/**
 * Calls when calendar event created/updated/deleted.
 *
 * @param object $updateevent calendar event instance.
 * @param object $changetype change type (added/updated/removed).
 * @return void.
 */
function when_calendar_event_updated($updateevent, $changetype) {
    global $CFG;

    // Not allowed to continue.
    if (has_denied_for_events($changetype)) {
        return;
    }

    $event = null;
    if ($changetype == REMINDERS_CALENDAR_EVENT_REMOVED) {
        $event = $updateevent->get_record_snapshot($updateevent->objecttable, $updateevent->objectid);
    } else {
        $event = calendar_event::load($updateevent->objectid);
    }

    $enabledoptionskey = 'local_reminders_enable_'.strtolower($event->eventtype).'forcalevents';
    if (!isset($CFG->$enabledoptionskey) || !$CFG->$enabledoptionskey) {
        return;
    }

    $currtime = time();
    $diffsecondsuntil = $event->timestart - $currtime;
    if ($diffsecondsuntil < 0) {
        return;
    }
    $aheadday = floor($diffsecondsuntil / (REMINDERS_DAYIN_SECONDS * 1.0));

    $reminderref = null;
    $tmprolesreminders = get_roles_for_reminders();
    $courseroleids = $tmprolesreminders[0];
    $activityroleids = $tmprolesreminders[1];
    $categoryroleids = $tmprolesreminders[2];
    $fromuser = get_from_user();

    switch ($event->eventtype) {
        case 'site':
            $reminderref = process_site_event($event, $aheadday);
            break;

        case 'user':
            $reminderref = process_user_event($event, $aheadday);
            break;

        case 'category':
            $reminderref = process_category_event($event, $aheadday, $categoryroleids, false);
            break;

        case 'course':
            $reminderref = process_course_event($event, $aheadday, $courseroleids, false);
            break;

        case 'open':
            // If we dont want to send reminders for activity openings.
            if (isset($CFG->local_reminders_duesend) && $CFG->local_reminders_duesend == REMINDERS_ACTIVITY_ONLY_CLOSINGS) {
                break;
            }
        case 'close':
            // If we dont want to send reminders for activity closings.
            if (isset($CFG->local_reminders_duesend) && $CFG->local_reminders_duesend == REMINDERS_ACTIVITY_ONLY_OPENINGS) {
                break;
            }
        case 'due':
            if (has_disabled_reminders_for_activity($event->courseid, $event->id)) {
                $showtrace && mtrace("  [Local Reminder] Activity event $event->id reminders disabled in the course settings.");
                break;
            }
            $reminderref = process_activity_event($event, $aheadday, $activityroleids, false);
            break;

        case 'group':
            $reminderref = process_group_event($event, $aheadday, false);
            break;

        default:
            $reminderref = process_unknown_event($event, $aheadday, $activityroleids, false);
    }

    if ($reminderref == null) {
        return;
    }

    $sendusers = $reminderref->get_sending_users();
    if ($reminderref->get_total_users_to_send() == 0) {
        return;
    }

    foreach ($sendusers as $touser) {
        $eventdata = $reminderref->get_updating_send_event($changetype, $fromuser, $touser);

        $mailresult = message_send($eventdata);
    }
    $reminderref->cleanup();
}

/**
 * Cleans the local_reminders table by deleting older unnecessary records.
 */
function clean_local_reminders_logs() {
    global $CFG, $DB, $PAGE;

    $cutofftime = time() - REMINDERS_7DAYSBEFORE_INSECONDS;
    mtrace("  [Local Reminders][CLEAN] clean cutoff time: $cutofftime");
    $recordcount = $DB->count_records_select(REMINDERS_CLEAN_TABLE, "time >= $cutofftime");
    if ($recordcount > 0) {
        mtrace('  [Local Reminders][CLEAN] Cleaning can be executed now as there are newer records.');
        $deletestatus = $DB->delete_records_select(REMINDERS_CLEAN_TABLE, "time < $cutofftime");
        mtrace('  [Local Reminders][CLEAN] Cleaning status: '.$deletestatus);
    } else {
        mtrace('  [Local Reminders][CLEAN] No records allow to clean since reminders cron has not bee executed for long time!');
    }
}

/**
 * Function to render settings customization per course.
 *
 * @param object $settingsnav settings navigation.
 * @param object $context current context.
 * @return void.
 */
function local_reminders_extend_settings_navigation($settingsnav, $context) {
    global $PAGE;

    // Only add this settings item on non-site course pages.
    if (!$PAGE->course or $PAGE->course->id == 1) {
        return;
    }

    // Only let users with the appropriate capability see this settings item.
    if (!has_capability('moodle/course:update', context_course::instance($PAGE->course->id))) {
        return;
    }

    if ($settingnode = $settingsnav->find('courseadmin', navigation_node::TYPE_COURSE)) {
        $name = get_string('admintreelabel', 'local_reminders');
        $url = new moodle_url('/local/reminders/coursesettings.php', array('courseid' => $PAGE->course->id));
        $navnode = navigation_node::create(
            $name,
            $url,
            navigation_node::NODETYPE_LEAF,
            'reminders',
            'reminders',
            new pix_icon('i/calendar', $name)
        );
        if ($PAGE->url->compare($url, URL_MATCH_BASE)) {
            $navnode->make_active();
        }
        $settingnode->add_node($navnode);
    }
}
