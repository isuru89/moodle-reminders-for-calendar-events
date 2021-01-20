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
 * Local helper functions for reminders cron function.
 *
 * @package    local_reminders
 * @author     Isuru Weerarathna <uisurumadushanka89@gmail.com>
 * @copyright  2012 Isuru Madushanka Weerarathna
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/course/lib.php');

require_once($CFG->dirroot . '/local/reminders/reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/site_reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/user_reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/course_reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/category_reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/group_reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/due_reminder.class.php');

/**
 * Returns a list of upcoming activities for the given course,
 *
 * @param int $courseid course id.
 * @param int $currtime epoch time to compare.
 * @return array list of event records.
 */
function get_upcoming_events_for_course($courseid, $currtime) {
    global $DB;

    $supportedevents = "('due', 'close', 'course', 'meeting_start')";
    return $DB->get_records_sql("SELECT *
        FROM {event}
        WHERE courseid = :courseid
            AND timestart > :cutofftime
            AND visible = 1
            AND eventtype IN $supportedevents
        ORDER BY timestart",
        array('courseid' => $courseid, 'cutofftime' => $currtime));
}

/**
 * Returns all settings associated with given course and event which
 * was set in course reminder settings.
 *
 * @param int $courseid course id.
 * @param int $eventid event id.
 * @return array all settings related to this course event.
 */
function fetch_course_activity_settings($courseid, $eventid) {
    global $DB;

    $records = $DB->get_records_sql("SELECT settingkey, settingvalue
        FROM {local_reminders_activityconf}
        WHERE courseid = :courseid AND eventid = :eventid",
        array('courseid' => $courseid, 'eventid' => $eventid));
    $pairs = array();
    if (!empty($records)) {
        foreach ($records as $record) {
            $pairs[$record->settingkey] = $record->settingvalue;
        }
    }
    return $pairs;
}

/**
 * Returns true if no reminders to send has been scheduled in course settings
 * page for the provided activity.
 *
 * @param int $courseid course id.
 * @param int $eventid event id.
 * @param string $keytocheck key to check for.
 * @return bool return true if reminders disabled for activity.
 */
function has_disabled_reminders_for_activity($courseid, $eventid, $keytocheck='enabled') {
    $activitysettings = fetch_course_activity_settings($courseid, $eventid);
    if (array_key_exists($keytocheck, $activitysettings) && !$activitysettings[$keytocheck]) {
        return true;
    }
    return false;
}

/**
 * This method will filter out all the activity events finished recently
 * and send reminders for users who still have not yet completed that activity.
 * Only once user will receive emails.
 *
 * @param int $curtime current time to check for cutoff.
 * @param array $activityroleids role ids for acitivities.
 * @param object $fromuser from user for emails.
 * @return void.
 */
function send_overdue_activity_reminders($curtime, $activityroleids, $fromuser) {
    global $DB, $CFG;

    mtrace('[LOCAL REMINDERS] Overdue Activity Reminder Cron Started @ '.$curtime);

    if (isset($CFG->local_reminders_enableoverdueactivityreminders) && !$CFG->local_reminders_enableoverdueactivityreminders) {
        mtrace('[LOCAL REMINDERS] Overdue Activity reminders are not enabled from settings! Skipped.');
        return;
    }

    $rangestart = $curtime - REMINDERS_DAYIN_SECONDS;
    $querysql = "SELECT e.*
        FROM {event} e
            LEFT JOIN {local_reminders_post_act} lrpa ON e.id = lrpa.eventid
        WHERE
            timestart >= $rangestart AND timestart < $curtime
            AND lrpa.eventid IS NULL
            AND (e.eventtype = 'due' OR e.eventtype = 'close')
            AND e.visible = 1";
    $allexpiredevents = $DB->get_records_sql($querysql);
    if (!$allexpiredevents || count($allexpiredevents) == 0) {
        mtrace('[LOCAL REMINDERS] No expired events found for this cron cycle! Skipped.');
        return;
    }

    mtrace('[LOCAL REMINDERS] Number of expired events found for this cron cycle: '.count($allexpiredevents));
    foreach ($allexpiredevents as $event) {
        $event = new calendar_event($event);

        if (has_disabled_reminders_for_activity($event->courseid, $event->id, 'enabledoverdue')) {
            mtrace("[LOCAL REMINDERS] Activity event $event->id overdue reminders disabled in the course settings");
            continue;
        }

        $reminderref = process_activity_event($event, -1, $activityroleids, true, REMINDERS_CALL_TYPE_OVERDUE);
        if (!isset($reminderref)) {
            mtrace('[LOCAL REMINDERS] Skipped post-activity event for '.$event->id);
            continue;
        }
        mtrace('[LOCAL REMINDERS] Processing post-activity event for '.$event->id);

        $sendusers = $reminderref->get_sending_users();
        foreach ($sendusers as $touser) {
            $eventdata = $reminderref->get_updating_send_event(REMINDERS_CALL_TYPE_OVERDUE, $fromuser, $touser);

            try {
                $mailresult = message_send($eventdata);
                mtrace('[LOCAL_REMINDERS] Post Activity Mail Result: '.$mailresult);

                if (!$mailresult) {
                    throw new coding_exception("Could not send out message for event#$event->id to user $eventdata->userto");
                }
            } catch (moodle_exception $mex) {
                mtrace('Error: local/reminders/locallib.php send_post_activity_reminders(): '.$mex->getMessage());
            }
        }

        $activityrecord = new stdClass();
        $activityrecord->sendtime = $curtime;
        $activityrecord->eventid = $event->id;
        $DB->insert_record('local_reminders_post_act', $activityrecord, false);
    }
}

/**
 * Process activity event and creates a reminder instance wrapping it.
 *
 * @param object $event calendar event.
 * @param int $aheadday number of days ahead.
 * @param array $activityroleids role ids for activities.
 * @param boolean $showtrace whether to print logs or not.
 * @param string $calltype calling type PRE|OVERDUE.
 * @return reminder_ref reminder reference instance.
 */
function process_activity_event($event, $aheadday, $activityroleids=null, $showtrace=true, $calltype=REMINDERS_CALL_TYPE_PRE) {
    global $CFG, $DB, $PAGE;
    if (isemptystring($event->modulename)) {
        return null;
    }

    try {
        // When a calendar event added, this is being called and moodle throws invalid module ID: ${a},
        // Due to it tries to get from a cache, but yet not exist.
        $courseandcm = get_course_and_cm_from_instance($event->instance, $event->modulename, $event->courseid);
    } catch (Exception $ex) {
        return null;
    }
    $course = $courseandcm[0];
    $cm = $courseandcm[1];
    if (is_course_hidden_and_denied($course)) {
        $showtrace && mtrace("  [Local Reminder] Course is hidden. No reminders will be sent.");
        return null;
    }
    $coursesettings = $DB->get_record('local_reminders_course', array('courseid' => $event->courseid));
    if (isset($coursesettings->status_activities) && $coursesettings->status_activities == 0) {
        $showtrace && mtrace("  [Local Reminder] Reminders for activities has been restricted in the configs.");
        return null;
    }

    if (!empty($course) && !empty($cm)) {
        $activityobj = fetch_module_instance($event->modulename, $event->instance, $event->courseid, $showtrace);
        $context = context_module::instance($cm->id);
        $PAGE->set_context($context);
        $sendusers = array();
        $reminder = new due_reminder($event, $course, $context, $cm, $aheadday);

        if ($event->courseid <= 0 && $event->userid > 0) {
            // A user overridden activity.
            $showtrace && mtrace("  [Local Reminder] Event #".$event->id." is a user overridden ".$event->modulename." event.");
            $user = $DB->get_record('user', array('id' => $event->userid));
            $sendusers[] = $user;
        } else if ($event->courseid <= 0 && $event->groupid > 0) {
            // A group overridden activity.
            $showtrace && mtrace("  [Local Reminder] Event #".$event->id." is a group overridden ".$event->modulename." event.");
            $group = $DB->get_record('groups', array('id' => $event->groupid));
            $sendusers = get_users_in_group($group);
        } else {
            // Here 'ra.id field added to avoid printing debug message,
            // from get_role_users (has odd behaivior when called with an array for $roleid param'.
            $sendusers = get_active_role_users($activityroleids, $context);

            // Filter user list,
            // see: https://docs.moodle.org/dev/Availability_API.
            $info = new \core_availability\info_module($cm);
            $sendusers = $info->filter_user_list($sendusers);
        }

        $reminder->set_activity($event->modulename, $activityobj);
        $filteredusers = $reminder->filter_authorized_users($sendusers, $calltype);
        return new reminder_ref($reminder, $filteredusers);
    }
    return null;
}

/**
 * Process unknown event and creates a reminder instance wrapping it if unknown
 * event is a module level activity.
 *
 * @param object $event calendar event.
 * @param int $aheadday number of days ahead.
 * @param array $activityroleids role ids for activities.
 * @param boolean $showtrace whether to print logs or not.
 * @return reminder_ref reminder reference instance.
 */
function process_unknown_event($event, $aheadday, $activityroleids=null, $showtrace=true) {
    global $DB, $PAGE;
    if (isemptystring($event->modulename)) {
        $showtrace && mtrace("  [Local Reminder] Unknown event type [$event->eventtype]");
        return null;
    }

    $course = $DB->get_record('course', array('id' => $event->courseid));
    $cm = get_coursemodule_from_instance($event->modulename, $event->instance, $event->courseid);

    if (!empty($course) && !empty($cm)) {
        if (is_course_hidden_and_denied($course)) {
            $showtrace && mtrace("  [Local Reminder] Course is hidden. No reminders will be sent.");
            return null;
        } else if (has_disabled_reminders_for_activity($event->courseid, $event->id)) {
            $showtrace && mtrace("  [Local Reminder] Activity event $event->id reminders disabled in the course settings.");
            return null;
        } else if (has_disabled_reminders_for_activity($event->courseid, $event->id, "days$aheadday")) {
            mtrace("  [Local Reminder] Activity event $event->id reminders disabled for $aheadday days ahead.");
            return null;
        }

        $activityobj = fetch_module_instance($event->modulename, $event->instance, $event->courseid, $showtrace);
        $context = context_module::instance($cm->id);
        $PAGE->set_context($context);
        $sendusers = get_active_role_users($activityroleids, $context);

        if (strcmp($event->eventtype, 'gradingdue') == 0 && isset($context)) {
            $filteredusers = array();
            foreach ($sendusers as $guser) {
                if (has_capability('mod/assign:grade', $context, $guser)) {
                    $filteredusers[] = $guser;
                }
            }
            $sendusers = $filteredusers;
        }
        $reminder = new due_reminder($event, $course, $context, $cm, $aheadday);
        $reminder->set_activity($event->modulename, $activityobj);
        return new reminder_ref($reminder, $sendusers);
    }
    return null;
}

/**
 * Process course event and creates a reminder instance wrapping it.
 *
 * @param object $event calendar event.
 * @param int $aheadday number of days ahead.
 * @param array $courseroleids role ids for course.
 * @param boolean $showtrace whether to print logs or not.
 * @return reminder_ref reminder reference instance.
 */
function process_course_event($event, $aheadday, $courseroleids=null, $showtrace=true) {
    global $DB, $PAGE;

    $course = $DB->get_record('course', array('id' => $event->courseid));
    if (is_course_hidden_and_denied($course)) {
        $showtrace && mtrace("  [Local Reminder] Course is hidden. No reminders will be sent.");
        return null;
    } else if (has_disabled_reminders_for_activity($event->courseid, $event->id)) {
        $showtrace && mtrace("  [Local Reminder] Specific course reminders are disabled. Skipping.");
        return null;
    }

    $coursesettings = $DB->get_record('local_reminders_course', array('courseid' => $event->courseid));
    if (isset($coursesettings->status_course) && $coursesettings->status_course == 0) {
        $showtrace && mtrace("  [Local Reminder] Reminders for course events has been restricted.");
        return null;
    }

    if (!empty($course)) {
        $sendusers = array();
        get_users_of_course($course->id, $courseroleids, $sendusers);

        $reminder = new course_reminder($event, $course, $aheadday);
        return new reminder_ref($reminder, $sendusers);
    }
    return null;
}

/**
 * Process course category event and creates a reminder instance wrapping it.
 *
 * @param object $event calendar event.
 * @param int $aheadday number of days ahead.
 * @param array $courseroleids role ids for course.
 * @param boolean $showtrace whether to print logs or not.
 * @return reminder_ref reminder reference instance.
 */
function process_category_event($event, $aheadday, $courseroleids=null, $showtrace=true) {
    global $CFG;

    $catid = $event->categoryid;
    $cat = null;
    // From Moodle 3.6+ coursecat is deprecated.
    if (class_exists('core_course_category')) {
        $cat = core_course_category::get($catid, IGNORE_MISSING);
    } else {
        require_once($CFG->libdir . '/coursecatlib.php');
        $cat = coursecat::get($catid, IGNORE_MISSING);
    }
    if (is_null($cat)) {
        // Course category not found or not visible.
        $showtrace && mtrace("  [LOCAL REMINDERS] Course category is not visible or exists! Skipping.");
        return null;
    }
    $showtrace && mtrace("   [LOCAL REMINDERS] Course category: $catid => $cat->name");
    $childrencourses = $cat->get_courses(['recursive' => true]);
    $allusers = array();
    $currenttime = time();
    $allcourses = isset($CFG->local_reminders_category_noforcompleted) && !$CFG->local_reminders_category_noforcompleted;
    foreach ($childrencourses as $course) {
        if ($allcourses || $currenttime < $course->enddate) {
            get_users_of_course($course->id, $courseroleids, $allusers);
        } else {
            $showtrace && mtrace("   [LOCAL REMINDERS]   - Course skipped: $course->id => $course->fullname");
        }
    }
    $showtrace && mtrace("   [LOCAL REMINDERS] Total users to send = ".count($allusers));

    $reminder = new category_reminder($event, $cat, $aheadday);
    return new reminder_ref($reminder, $allusers);
}

/**
 * Process group event and creates a reminder instance wrapping it.
 *
 * @param object $event calendar event.
 * @param int $aheadday number of days ahead.
 * @param boolean $showtrace whether to print logs or not.
 * @return reminder_ref reminder reference instance.
 */
function process_group_event($event, $aheadday, $showtrace=true) {
    global $DB, $PAGE;

    $group = $DB->get_record('groups', array('id' => $event->groupid));
    if (!empty($group)) {
        if (isset($group->courseid) && !empty($group->courseid)) {
            $PAGE->set_context(context_course::instance($group->courseid));
        }
        $coursesettings = $DB->get_record('local_reminders_course', array('courseid' => $group->courseid));
        if (isset($coursesettings->status_group) && $coursesettings->status_group == 0) {
            $showtrace && mtrace("  [Local Reminder] Reminders for group events has been restricted in the configs.");
            return null;
        }

        $reminder = new group_reminder($event, $group, $aheadday);

        // Add module details, if this event is a mod type event.
        if (!isemptystring($event->modulename) && $event->courseid > 0) {
            $activityobj = fetch_module_instance($event->modulename, $event->instance, $event->courseid, $showtrace);
            $reminder->set_activity($event->modulename, $activityobj);
        }
        $sendusers = get_users_in_group($group);
        return new reminder_ref($reminder, $sendusers);
    }
}

/**
 * Process user event and creates a reminder instance wrapping it.
 *
 * @param object $event calendar event.
 * @param int $aheadday number of days ahead.
 * @return reminder_ref reminder reference instance.
 */
function process_user_event($event, $aheadday) {
    global $DB;

    $user = $DB->get_record('user', array('id' => $event->userid));

    if (!empty($user)) {
        $reminder = new user_reminder($event, $user, $aheadday);
        $sendusers[] = $user;
        return new reminder_ref($reminder, $sendusers);
    }
    return null;
}

/**
 * Process site event and creates a reminder instance wrapping it.
 *
 * @param object $event calendar event.
 * @param int $aheadday number of days ahead.
 * @return reminder_ref reminder reference instance.
 */
function process_site_event($event, $aheadday) {
    global $DB, $PAGE;

    $reminder = new site_reminder($event, $aheadday);
    $sendusers = $DB->get_records_sql("SELECT *
        FROM {user}
        WHERE id > 1 AND deleted=0 AND suspended=0 AND confirmed=1;");
    $PAGE->set_context(context_system::instance());
    return new reminder_ref($reminder, $sendusers);
}

/**
 * Returns course roles and activity role ids globally defined in moodle.
 *
 * @return array containing two elements course roles ids and activity role ids.
 */
function get_roles_for_reminders() {
    global $CFG;

    $allroles = get_all_roles();
    $courseroleids = array();
    $activityroleids = array();
    $categoryroleids = array();
    if (!empty($allroles)) {
        $flag = 0;
        foreach ($allroles as $arole) {
            $roleoptionactivity = $CFG->local_reminders_activityroles;
            if (isset($roleoptionactivity[$flag]) && $roleoptionactivity[$flag] == '1') {
                $activityroleids[] = $arole->id;
            }
            $roleoption = $CFG->local_reminders_courseroles;
            if (isset($roleoption[$flag]) && $roleoption[$flag] == '1') {
                $courseroleids[] = $arole->id;
            }
            $roleoptioncat = $CFG->local_reminders_categoryroles;
            if (isset($roleoptioncat[$flag]) && $roleoptioncat[$flag] == '1') {
                $categoryroleids[] = $arole->id;
            }
            $flag++;
        }
    }
    return array(
        $courseroleids,
        $activityroleids,
        $categoryroleids
    );
}

/**
 * Appends all users in the course for the given array.
 *
 * @param int $courseid course id to search users for.
 * @param array $courseroleids course role id array.
 * @param array $arraytoappend user array to append new unique users.
 * @return void nothing.
 */
function get_users_of_course($courseid, $courseroleids, &$arraytoappend) {
    global $PAGE;

    $context = context_course::instance($courseid);
    $PAGE->set_context($context);
    $roleusers = get_role_users($courseroleids, $context, true, 'ra.id as ra_id, u.*');
    $senduserids = array_map(
        function($u) {
            return $u->id;
        }, $roleusers);
    $senduserrefs = array_combine($senduserids, $roleusers);
    foreach ($senduserids as $userid) {
        if (!array_key_exists($userid, $arraytoappend)) {
            $arraytoappend[$userid] = $senduserrefs[$userid];
        }
    }
}

/**
 * This function formats the due time of the event appropiately. If this event
 * has a duration then formatted time will be [starttime]-[endtime].
 *
 * @param object $user user object
 * @param object $event event instance
 * @param array $tzstyle css style string for tz
 * @param boolean $includetz whether to include timezone or not.
 * @param string $mode mode of rendering. html or plain.
 * @return string formatted time string
 */
function format_event_time_duration($user, $event, $tzstyle=null, $includetz=true, $mode='html') {
    $followedtimeformat = get_string('strftimedaydate', 'langconfig');
    $usertimeformat = get_correct_timeformat_user($user);

    $tzone = 99;
    if (isset($user) && !empty($user)) {
        $tzone = core_date::get_user_timezone($user);
    }

    $addflag = false;
    $formattedtimeprefix = userdate($event->timestart, $followedtimeformat, $tzone);
    $formattedtime = userdate($event->timestart, $usertimeformat, $tzone);
    $sdate = usergetdate($event->timestart, $tzone);
    if ($event->timeduration > 0) {
        $etime = $event->timestart + $event->timeduration;
        $ddate = usergetdate($etime, $tzone);

        // Falls in the same day.
        if ($sdate['year'] == $ddate['year'] && $sdate['mon'] == $ddate['mon'] && $sdate['mday'] == $ddate['mday']) {
            // Bug fix for not correctly displaying times in incorrect formats.
            // Issue report: https://tracker.moodle.org/browse/CONTRIB-3647?focusedCommentId=408657.
            $formattedtime .= ' - '.userdate($etime, $usertimeformat, $tzone);
            $addflag = true;
        } else {
            $formattedtime .= ' - '.
                userdate($etime, $followedtimeformat, $tzone)." ".
                userdate($etime, $usertimeformat, $tzone);
        }

        if ($addflag) {
            $formattedtime = $formattedtimeprefix.'  ['.$formattedtime.']';
        } else {
            $formattedtime = $formattedtimeprefix.' '.$formattedtime;
        }

    } else {
        $formattedtime = $formattedtimeprefix.' '.$formattedtime;
    }

    if (!$includetz) {
        return $formattedtime;
    }

    $tzstr = local_reminders_tz_info::get_human_readable_tz($tzone);
    if ($mode == 'html') {
        if (!isemptystring($tzstyle)) {
            $tzstr = '<span style="'.$tzstyle.'">'.$tzstr.'</span>';
        } else {
            $tzstr = '<span style="font-size:13px;color: #888;">'.$tzstr.'</span>';
        }
        return $formattedtime.' &nbsp;&nbsp;'.$tzstr;
    } else {
        return $formattedtime.' - '.$tzstr;
    }
}

/**
 * This function would return time formats relevent for the given user.
 * Sometimes a user might have changed time display format in his/her preferences.
 *
 * @param object $user user instance to get specific time format.
 * @return string date time format for user.
 */
function get_correct_timeformat_user($user) {
    static $langtimeformat = null;
    if ($langtimeformat === null) {
        $langtimeformat = get_string('strftimetime', 'langconfig');
    }

    // We get user time formattings... if such exist, will return non-empty value.
    $utimeformat = get_user_preferences('calendar_timeformat', '', $user);
    if (empty($utimeformat)) {
        $utimeformat = get_config(null, 'calendar_site_timeformat');
    }
    return empty($utimeformat) ? $langtimeformat : $utimeformat;
}

/**
 * Returns array of users active (not suspended) in the provided contexts and
 * at the same time belongs to the given roles.
 *
 * @param array $activityroleids role ids
 * @param object $context context to search for users
 * @return array of user records
 */
function get_active_role_users($activityroleids, $context) {
    return get_role_users($activityroleids, $context, true, 'ra.id, u.*',
        null, false, '', '', '',
        'ue.status = :userenrolstatus',
        array('userenrolstatus' => ENROL_USER_ACTIVE));
}

/**
 * Returns all users belong to the given group.
 *
 * @param object $group group object as received from db.
 * @return array users in an array
 */
function get_users_in_group($group) {
    global $DB;

    $sendusers = array();
    $groupmemberroles = groups_get_members_by_role($group->id, $group->courseid, 'u.id');
    if ($groupmemberroles) {
        foreach ($groupmemberroles as $roleid => $roledata) {
            foreach ($roledata->users as $member) {
                $sendusers[] = $DB->get_record('user', array('id' => $member->id));
            }
        }
    }
    return $sendusers;
}

/**
 * Returns true if the activity belongs to a hidden course. And prevents sending reminders.
 *
 * @param object $course course instance.
 * @return bool status of course hidden filter should apply or not.
 */
function is_course_hidden_and_denied($course) {
    global $CFG;

    if (isset($CFG->local_reminders_filterevents)) {
        if ($CFG->local_reminders_filterevents == REMINDERS_SEND_ONLY_VISIBLE && $course->visible == 0) {
            return true;
        }
    }
    return false;
}

/**
 * Returns true if input string is empty/whitespaces only, otherwise false.
 *
 * @param string $str text to compare.
 * @return boolean true if string is empty or whitespace.
 */
function isemptystring($str) {
    return !isset($str) || empty($str) || trim($str) === '';
}

/**
 * Function to retrive module instace from corresponding module
 * table. This function is written because when sending reminders
 * it can restrict showing some fields in the message which are sensitive
 * to user. (Such as some descriptions are hidden until defined date)
 * Function is very similar to the function in datalib.php/get_coursemodule_from_instance,
 * but by below it returns all fields of the module.
 *
 * Eg: can get the quiz instace from quiz table, can get the new assignment
 * instace from assign table, etc.
 *
 * @param string $modulename name of module type, eg. resource, assignment,...
 * @param int $instance module instance number (id in resource, assignment etc. table)
 * @param int $courseid optional course id for extra validation
 * @param boolean $showtrace optional to print trace logs.
 * @return individual module instance (a quiz, a assignment, etc).
 *          If fails returns null
 */
function fetch_module_instance($modulename, $instance, $courseid=0, $showtrace=true) {
    global $DB;

    $params = array('instance' => $instance, 'modulename' => $modulename);

    $courseselect = "";

    if ($courseid) {
        $courseselect = "AND cm.course = :courseid";
        $params['courseid'] = $courseid;
    }

    $sql = "SELECT m.*
              FROM {course_modules} cm
                   JOIN {modules} md ON md.id = cm.module
                   JOIN {".$modulename."} m ON m.id = cm.instance
             WHERE m.id = :instance AND md.name = :modulename
                   $courseselect";

    try {
        return $DB->get_record_sql($sql, $params, IGNORE_MISSING);
    } catch (moodle_exception $mex) {
        $showtrace && mtrace('  [Local Reminder - ERROR] Failed to fetch module instance! '.$mex.getMessage);
        return null;
    }
}

/**
 * Returns the from user instance which should be send notifications.
 *
 * @return object from user object.
 */
function get_from_user() {
    global $CFG;

    $fromuser = core_user::get_noreply_user();
    if (isset($CFG->local_reminders_sendasname) && !empty($CFG->local_reminders_sendasname)) {
        $fromuser->firstname = $CFG->local_reminders_sendasname;
    }
    if (isset($CFG->local_reminders_sendas) && $CFG->local_reminders_sendas == REMINDERS_SEND_AS_ADMIN) {
        $fromuser = get_admin();
    }
    return $fromuser;
}

/**
 * Returns filter_multilangsecond instance if filter is installed.
 *
 * @return filter_multilangsecond|null
 */
function get_multilangsecond_filter(){
    $filterinfo = core_plugin_manager::instance()->get_plugin_info('filter_multilang2');
    if(!is_null($filterinfo)){
        require_once($filterinfo->rootdir . '/filter.php');
        return new filter_multilang2(null,  array());
    }else{
        return null;
    }
}

/**
 * Reminder specific timezone data holder.
 *
 * Note: you must have at least Moodle 3.5 or higher.
 *
 * @package    local_reminders
 * @copyright  2012 Isuru Madushanka Weerarathna
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_reminders_tz_info extends \core_date {
    /**
     * hold the timezone mappings.
     *
     * @var array
     */
    protected static $mapping;

    /**
     * Returns human readable timezone name for given timezone.
     *
     * @param string $tz input time zone.
     * @return string human readable tz.
     */
    public static function get_human_readable_tz($tz) {
        if (!isset(self::$mapping)) {
            static::load_tz_info();
        }

        if (is_numeric($tz)) {
            return static::get_localised_timezone($tz);
        }
        if (array_key_exists($tz, self::$mapping)) {
            return self::$mapping[$tz];
        }
        return static::get_localised_timezone($tz);
    }

    /**
     * Load timezone information from base class.
     *
     * @return void.
     */
    private static function load_tz_info() {
        self::$mapping = array();
        foreach (static::$badzones as $detailname => $abbr) {
            if (!is_numeric($detailname)) {
                self::$mapping[$abbr] = $detailname;
            }
        }
    }
}

/**
 * Reminder reference class.
 *
 * @package    local_reminders
 * @copyright  2012 Isuru Madushanka Weerarathna
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reminder_ref {
    /**
     * created reminder reference.
     *
     * @var local_reminder
     */
    protected $reminder;
    /**
     * Array of users to send this reminder.
     *
     * @var array
     */
    protected $sendusers;

    /**
     * Creates new reminder reference.
     *
     * @param local_reminder $reminder created reminder.
     * @param array $sendusers array of users.
     */
    public function __construct($reminder, $sendusers) {
        $this->reminder = $reminder;
        $this->sendusers = $sendusers;
    }

    /**
     * Returns total number of users eligible to send this reminder.
     *
     * @return int total number of users.
     */
    public function get_total_users_to_send() {
        return count($this->sendusers);
    }

    /**
     * Returns the ultimate notification event instance to send for given user.
     *
     * @param object $fromuser from user.
     * @param object $touser user to send.
     * @return object new notification instance.
     */
    public function get_event_to_send($fromuser, $touser) {
        return $this->reminder->get_sending_event($fromuser, $touser);
    }

    /**
     * Returns the notification event instance based on change type.
     *
     * @param string $changetype change type PRE|OVERDUE.
     * @param object $fromuser from user.
     * @param object $touser user to send.
     * @return object new notification instance.
     */
    public function get_updating_send_event($changetype, $fromuser, $touser) {
        return $this->reminder->get_updating_event_message($changetype, $fromuser, $touser);
    }

    /**
     * Returns eligible sending users as array.
     *
     * @return array users eligible to receive message.
     */
    public function get_sending_users() {
        return $this->sendusers;
    }

    /**
     * Cleanup the reminder memory.
     *
     * @return void nothing.
     */
    public function cleanup() {
        unset($this->sendusers);
        if (isset($this->reminder)) {
            $this->reminder->cleanup();
        }
    }
}
