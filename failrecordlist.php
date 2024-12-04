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
        'pluginname',
        'local_obf',
    ),
);
$PAGE->set_heading(
    get_string(
        'pluginname',
        'local_obf',
    ),
);

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

echo $OUTPUT->header();

$records = $DB->get_records('local_obf_issuefailedrecord');

$failedRecords = array_values(array_map(function($record) {
    $recordObject = new obf_issuefailedrecord($record);
    return [
        'id' => $recordObject->getId(),
        'recipients' => $recordObject->getRecipients(),
        'timestamp' => userdate($recordObject->getTimestamp()),
        'email' => $recordObject->getEmail(),
        'criteriaAddendum' => $recordObject->getCriteriaAddendum(),
        'deleteUrl' => (new moodle_url(
            '/local/obf/failrecordlist.php',
            [
                'action' => 'delete',
                'id' => $recordObject->getId(),
                'sesskey' => sesskey()
            ],
        ))->out(false),
    ];
},
    $records));

$data = [ 'records' => $failedRecords ];
echo $OUTPUT->render_from_template(
    'local_obf/issuefailedrecord',
    $data,
);

echo $OUTPUT->footer();