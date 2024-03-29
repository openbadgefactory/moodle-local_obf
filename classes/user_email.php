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
 * User email.
 * Validation of user emails.
 *
 * @package    local_obf
 * @copyright  2013-2020, Open Badge Factory Oy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace classes;
use Exception;
use moodle_database;
use moodle_url;
use stdClass;

/**
 * User email -class.
 * Validation of user emails.
 *
 * @copyright  2013-2020, Open Badge Factory Oy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class obf_user_email {
    const MAX_TOKEN_AGE = 86400; // Maximum age of the token in seconds (24*60*60 ~ 1 day).

    /**
     * Indicates the verification status of the address.
     * Set to true if the address is verified.
     *
     * @var bool
     */
    protected $verified;

    /**
     * User ID
     *
     * @var int
     */
    protected $userid;

    /**
     * Token used for verification
     *
     * @var string
     */
    protected $token;

    /**
     * Email address
     *
     * @var string
     */
    protected $email;

    /**
     * Unix timestamp when the token was created.
     *
     * @var int
     */
    protected $timestamp;

    public function get_verified() {
        return $this->verified;
    }

    public function get_userid() {
        return $this->userid;
    }

    public function get_token() {
        return $this->token;
    }

    public function get_email() {
        return $this->email;
    }

    public function get_timestamp() {
        return $this->timestamp;
    }

    public function set_verified($verified) {
        $this->verified = $verified;
        return $this;
    }

    public function set_userid($userid) {
        $this->userid = $userid;
        return $this;
    }

    public function set_token($token) {
        $this->token = $token;
        return $this;
    }

    public function set_email($email) {
        $this->email = $email;
        return $this;
    }

    public function set_timestamp($timestamp) {
        $this->timestamp = $timestamp;
        return $this;
    }

    public static function is_user_email_verified($userid, $email, $token = null, $updaterecords = true) {
        global $DB;
        $table = 'local_obf_user_emails';
        $record = $DB->get_record($table, array('user_id' => $userid, 'email' => $email, 'verified' => 1));
        if (!empty($token) && !$record) {
            $mintimestamp = time() - self::MAX_TOKEN_AGE;
            $sql = 'SELECT * FROM {' . $table . '} WHERE user_id = :user_id AND '
                . 'email = :email AND token = :token AND timestamp > :mintimestamp';
            $record = $DB->get_record_sql($sql,
                array('user_id' => $userid, 'email' => $email, 'token' => $token, 'mintimestamp' => $mintimestamp)
            );
            if ($updaterecords && $record) {
                $record->verified = 1;
                $DB->update_record($table, $record);
            }
        }
        return (boolean) $record;
    }

    public static function create_user_email_token($userid, $email, $sendemail = true) {
        global $DB;
        if (empty($userid) || empty($email)) {
            throw new Exception("User ID or Email is empty!", 1);

        }
        $table = 'local_obf_user_emails';
        $token = generate_password();
        $record = $DB->get_record($table, array('user_id' => $userid, 'email' => $email));
        if ($record && $record->verified != 1) {
            $record->token = $token;
            $record->timestamp = time();
            $DB->update_record($table, $record);
        } else if (!$record) {
            $record = new stdClass();
            $record->user_id = $userid;
            $record->email = $email;
            $record->token = $token;
            $record->timestamp = time();
            $DB->insert_record($table, $record);
        } else {
            // Developer note: Make sure this is never thrown or is handled.
            throw new Exception('A verified token already exists.', 2);
        }
        if ($sendemail && $token) {
            self::send_token_email($userid, $email, $token);
        }
        return $token;
    }

    public static function send_token_email($userid, $email, $token) {
        global $CFG, $DB;
        $user = $DB->get_record('user', array('id' => $userid));
        if (preg_match('/^2.9/', $CFG->release)) {
            $message = new \core\message\message();
        } else {
            $message = new stdClass();
        }
        $user->email = $email;
        $from = get_admin();
        $subject = get_string('emailverifytokenemailsubject', 'local_obf');
        $messagetext = get_string('emailverifytokenemailbody2', 'local_obf') . "\n\n" . $token . "\n";
        return email_to_user($user, $from, $subject, $messagetext);
    }
}
