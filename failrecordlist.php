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
 * Display a list of failed badge issue records.
 *
 * @package     local_obf
 * @author      Sylvain Revenu | Pimenko 2024
 * @copyright   2013-2020, Open Badge Factory Oy
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use classes\criterion\obf_criterion_item;
use classes\obf_issuefailedrecord;

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/obf_issuefailedrecord.php');
require_once(__DIR__ . '/lib.php');

global $DB, $PAGE, $OUTPUT;

$context = context_system::instance();

require_login();
require_capability(
    'moodle/site:config',
    $context,
);

$PAGE->set_context($context);
$PAGE->set_url('/local/obf/failrecordlist.php');
$PAGE->set_title(
    get_string(
        'failrecordlist',
        'local_obf',
    ),
);
$PAGE->set_heading(
    get_string(
        'failrecordlist',
        'local_obf',
    ),
);
$PAGE->set_pagelayout('admin');

$action = optional_param(
    'action',
    '',
    PARAM_ALPHA,
);
$id = optional_param(
    'id',
    0,
    PARAM_INT,
);

if ($action === 'delete' && confirm_sesskey() && $id > 0) {
    obf_delete_failed_record($id);
    redirect(new moodle_url('/local/obf/failrecordlist.php'));
}

$records = $DB->get_records('local_obf_issuefailedrecord');

$createRecord = static function($record) {
    $recordObject = new obf_issuefailedrecord($record);
    $courses = $recordObject->getinformations()['criteriondata']->get_items();

    $courseslinks = [];
    foreach ($courses as $criterioncourse) {
        $courseid = $criterioncourse->get_courseid();

        // Don't display moodle course instance.
        if ($courseid > 0) {
            $course = get_course($courseid);
            $courseslinks[] = [
                'id' => $courseid,
                'link' => (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
                'name' => $course->fullname
            ];
        }
    }

    return [
        'id' => $recordObject->getid(),
        'badgename' => $recordObject->getinformations()['badge']->get_name(),
        'recipients' => $recordObject->getformattedrecipients(),
        'timestamp' => userdate($recordObject->gettimestamp()),
        'email' => $recordObject->getemail(),
        'status' => $recordObject->getstatus(),
        'deleteurl' => (new moodle_url(
            '/local/obf/failrecordlist.php',
            [
                'action' => 'delete',
                'id' => $recordObject->getid(),
                'sesskey' => sesskey()
            ],
        ))->out(false),
        'courseslinks' => $courseslinks
    ];
};

$failedrecords = array_values(array_map($createRecord, $records));

$data = [ 'records' => $failedrecords ];

echo $OUTPUT->header();

echo $OUTPUT->render_from_template(
    'local_obf/issuefailedrecord',
    $data,
);

echo $OUTPUT->footer();