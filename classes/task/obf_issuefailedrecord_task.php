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
 * Cron task for failed issue badgre to try again.
 *
 * @package     local_obf
 * @author      Sylvain Revenu | Pimenko 2024
 * @copyright   2013-2020, Open Badge Factory Oy
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_obf\task;

use cache_helper;
use classes\criterion\obf_criterion;
use classes\obf_badge;
use classes\obf_client;
use classes\obf_email;
use classes\obf_issue_event;
use classes\obf_issuefailedrecord;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../obf_issuefailedrecord.php');
require_once(__DIR__ . '/../criterion/obf_criterion.php');
require_once(__DIR__ . '/../client.php');
require_once(__DIR__ . '/../email.php');
require_once(__DIR__ . '/../event.php');

class obf_issuefailedrecord_task extends \core\task\scheduled_task {

    public function get_name() {
        return get_string(
            'processobfissuefailedrecord',
            'local_obf',
        );
    }

    public function execute() {
        global $DB;

        $records = $DB->get_records('local_obf_issuefailedrecord');

        foreach ($records as $record) {
            if ($record->status == "pending" || $record->status == "error" || $record->status == null) {
                $issuefailed = new obf_issuefailedrecord($record);
                $client = obf_client::get_instance();
                $user = $DB->get_record(
                    'user',
                    [ 'email' => $issuefailed->getRecipients()[0] ],
                );

                $badge = obf_badge::get_instance(
                    $issuefailed->getEmail()['badgeid'],
                    $client,
                );

                // Handle case where user already receive the badge.
                $deletedRecord = false;
                $assertions = $badge->get_assertions();
                foreach ($assertions as $assertion) {
                    if (in_array(
                        $issuefailed->getRecipients()[0],
                        $assertion->get_recipients(),
                    )) {
                        $deletedRecord = $DB->delete_records(
                            'local_obf_issuefailedrecord',
                            [ 'id' => $record->id ],
                        );
                    }
                }

                if ($deletedRecord) {
                    continue;
                }

                $criterion = new obf_criterion();
                $criterion->set_badge($badge);
                $criterion->set_clientid($client->client_id());

                $email = new obf_email();
                $email->set_id($issuefailed->getEmail()['id']);
                $email->set_body($issuefailed->getEmail()['body']);
                $email->set_subject($issuefailed->getEmail()['subject']);
                $email->set_badge_id($issuefailed->getEmail()['badgeid']);
                $email->set_footer($issuefailed->getEmail()['footer']);
                $email->set_link_text($issuefailed->getEmail()['linktext']);

                try {
                    $eventid = $badge->issue(
                        $issuefailed->getRecipients(),
                        $issuefailed->getTimestamp(),
                        $email,
                        $issuefailed->getCriteriaAddendum(),
                        $criterion->get_items(),
                    );
                } catch (Exception $e) {
                    $DB->set_field(
                        'local_obf_issuefailedrecord',
                        'status',
                        "error",
                        [ 'id' => $record->id ],
                    );
                    break;
                }

                $criterion->set_met_by_user($user->id);

                if ($eventid && !is_bool($eventid)) {
                    $issuevent = new obf_issue_event(
                        $eventid,
                        $DB,
                    );
                    $issuevent->set_criterionid($criterion->get_id());
                    $issuevent->save($DB);
                }
                cache_helper::invalidate_by_event(
                    'new_obf_assertion',
                    [ $user->id ],
                );

                $DB->set_field(
                    'local_obf_issuefailedrecord',
                    'status',
                    "success",
                    [ 'id' => $record->id ],
                );
            } else if ($record->status == "success") {
                $DB->delete_records(
                    'local_obf_issuefailedrecord',
                    [ 'id' => $record->id ],
                );
            }
        }
    }
}