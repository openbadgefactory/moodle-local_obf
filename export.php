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

use classes\obf_assertion;
use classes\obf_client;
use classes\obf_badge;

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/client.php');
require_once(__DIR__ . '/classes/badge.php');
require_once($CFG->libdir . '/csvlib.class.php');

$clientid = optional_param('clientid', null, PARAM_ALPHANUM);
if (empty($clientid)) {
    $clientid = null;
}

$badgeid = optional_param('badgeid', null, PARAM_ALPHANUM);
$courseid = optional_param('courseid', null, PARAM_INT);
$action = optional_param('action', 'list', PARAM_ALPHANUM);


global $DB;

$url = new moodle_url('/local/obf/badge.php', array('action' => $action));
// Site context.
if (empty($courseid)) {
    require_login();
} else { // Course context.
    $url->param('courseid', $courseid);
    require_login($courseid);
}

$context = empty($courseid) ? context_system::instance() : context_course::instance($courseid);

require_capability('local/obf:viewhistory', $context);

$client = obf_client::connect($clientid, $USER);

$badge = empty($badgeid) ? null : obf_badge::get_instance($badgeid);


$searchparams = array(
    'api_consumer_id' => OBF_API_CONSUMER_ID,
    'order_by' => 'asc'
);

if (!empty($courseid)) {
    $searchparams['log_entry'] = 'course_id:' . (string)$courseid;
}

$badgeid = is_null($badge) ? null : $badge->get_id();
if (!empty($badgeid)) {
    $searchparams['badge_id'] = $badgeid;
}

$history = obf_assertion::get_assertions($client, $badge, null, -1, false, $searchparams);

// CSV output filename.
$filename = 'badge_history.csv';

// CSV headers.
$headers = array(get_string('exportbadgename', 'local_obf'), get_string('exportrecipients', 'local_obf'),
    get_string('exportissuedon', 'local_obf'), get_string('exportexpiresby', 'local_obf'),
    get_string('exportissuedfrom', 'local_obf'));

// Initialize CSV file.
$csvfile = new \csv_export_writer();
$csvfile->set_filename($filename);

// Write headers to the CSV file.
$csvfile->add_data($headers);

// Write history data to the CSV file.
foreach ($history as $assertion) {
    $users = $history->get_assertion_users($assertion);
    $logs = $assertion->get_log_entry('course_id');
    $courses = '';
    $coursefullname = '';
    if (!empty($logs)) {
        $course = $DB->get_record('course', array('id' => $courseid), '*');
        if (is_bool($course)) {
            $coursefullname = '';
        } else {
            $coursefullname = $course->fullname;
        }
        $activity = $assertion->get_log_entry('activity_name');

        if (!empty($activity)) {
            $coursefullname .= ' (' . $activity . ')';
        }
    }

    $recipients = array();
    foreach ($users as $user) {
        if (!isset($user->firstname) || !isset($user->lastname)) {
            if ($user == 'userremoved') {
                $recipients[] = '';
            } else {
                $recipients[] = $user;
            }
        } else {
            $recipients[] = $user->firstname . ' ' . $user->lastname;
        }
    }

    if ((isset($courseid) && $courseid == $logs) || empty($courseid)) {
        $rowdata = array($assertion->get_badge()->get_name(), implode(', ', $recipients),
            userdate($assertion->get_issuedon(), get_string('dateformatdate', 'local_obf')), $assertion->get_expires(), $coursefullname);

        // Add a record to the CSV file.
        $csvfile->add_data($rowdata);
    }
}

// Close the CSV file.
$csvfile->download_file();
