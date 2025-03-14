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
 * OBF Client.
 *
 * @package    local_obf
 * @copyright  2013-2021, Open Badge Factory Oy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace classes;

use context_course;
use context_coursecat;
use context_system;
use core\message\message;
use curl;
use dml_write_exception;
use Exception;
use html_writer;
use local_obf_html;
use moodle_url;
use stdClass;
use url;
use \Collator;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib.php');
require_once($CFG->libdir . '/filelib.php');

/**
 * Class for handling the communication to Open Badge Factory API using legacy authentication.
 *
 * @copyright  2013-2021, Open Badge Factory Oy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class obf_client {
    /**
     * @var $client Static obf_client singleton
     */
    private static $client = null;

    /**
     * @var string Static current client id
     */
    private static $clientid = null;


    /**
     * @var curl|null Transport. Curl.
     */
    private $transport = null;

    /**
     * @var object local_obf_oauth2 table row
     */
    private $oauth2 = null;

    /**
     * @var int HTTP code for handling errors, such as deleted badges.
     */
    private $httpcode = null;
    /**
     * @var string Last error message.
     */
    private $error = '';
    /**
     * @var array Raw response.
     */
    private $rawresponse = null;
    /**
     * @var bool Store raw response?
     */
    private $enablerawresponse = false;

    /**
     * @var array event id => api details lookup table
     */
    private $eventlookup = null;

    const RETRIEVE_ALL = 'all';
    const RETRIEVE_LOCAL = 'local';

    /**
     * Returns the client instance.
     *
     * @param curl|null $transport
     * @return obf_client The client.
     */
    public static function get_instance($transport = null) {
        global $DB;
        if (is_null(self::$client)) {

            self::$client = new self();

            $oauth2 = $DB->get_records('local_obf_oauth2', null, 'client_name');
            if (count($oauth2) > 0) {
                // Use the first one by default.
                $o2 = current($oauth2);
                if (self::$clientid) {
                    foreach ($oauth2 as $o) {
                        if ($o->client_id === self::$clientid) {
                            $o2 = $o;
                            break;
                        }
                    }
                }
                self::$client->set_oauth2($o2);
            }

            if (!is_null($transport)) {
                self::$client->set_transport($transport);
            }
        }

        return self::$client;
    }

    /**
     * Set current active OAuth2 connection. Returns the client instance.
     *
     * @param string client_id in local_obf_oauth2 table row
     * @return obf_client The client.
     */
    public static function connect($id, $user = null, $transport = null) {
        self::$client = null;
        self::$clientid = $id;

        $ok = true;

        $legacyid = get_config('local_obf', 'obfclientid');
        $available = self::get_available_clients($user);

        if (empty($legacyid) && empty($available)) {
            // No connection available.
            $ok = false;
        } else if ($legacyid) {
            // Legacy connection.
            if ($id) {
                $ok = $id === $legacyid;
            }
        } else if (!is_null($user)) {
            // OAuth2 connections.
            $ok = is_null($id) ? !empty($available) : isset($available[$id]);
        }

        if (!$ok) {
            throw new Exception(get_string('apierror0', 'local_obf'), 0);
        }

        return self::get_instance($transport);
    }

    /**
     * Get configured OAuth2 clients
     *
     * @return array id and name pairs
     */
    public static function get_available_clients($user = null) {
        global $CFG, $DB, $USER;

        if (is_null($user)) {
            $user = $USER;
        }

        if ($user === '*' || in_array($user->id, explode(',', $CFG->siteadmins))) {
            // Can see all connected clients.
            return $DB->get_records_menu('local_obf_oauth2', null, 'client_name', 'client_id, client_name');
        }

        // Get connected clients based on user role access (role can be in any context).
        $sql =
            "SELECT DISTINCT o.client_id, o.client_name FROM {local_obf_oauth2} o
            INNER JOIN {local_obf_oauth2_role} r ON o.id = r.oauth2_id
            INNER JOIN {role_assignments} ra ON r.role_id = ra.roleid
            WHERE ra.userid = ?
            ORDER BY o.client_name";

        return $DB->get_records_sql_menu($sql, array($user->id));
    }

    /**
     * Checks if there is at least one client_id stored in the database or if the obfclientid config is not empty.
     *
     * @return bool Returns true if at least one client_id is found, false otherwise.
     */
    public static function has_client_id() {
        global $DB;
        return $DB->count_records('local_obf_oauth2') > 0 || !empty(get_config('local_obf', 'obfclientid'));
    }

    /**
     * Checks that the OBF client id is stored to plugin settings.
     *
     * @throws Exception If the client id is missing.
     */
    public function require_client_id() {
        if (empty($this->oauth2->client_id) && empty(get_config('local_obf', 'obfclientid'))) {
            throw new Exception(get_string('apierror0', 'local_obf'), 0);
        }
    }

    /**
     * Get OBF api url
     *
     * @return string
     */
    private function obf_url() {
        if (isset($this->oauth2->obf_url)) {
            $url = $this->oauth2->obf_url;
        } else {
            $url = get_config('local_obf', 'apiurl');
        }
        return rtrim($url, '/');
    }

    /**
     * Get current active client id
     *
     * @return string
     */
    public function client_id() {
        if (isset($this->oauth2->client_id)) {
            return $this->oauth2->client_id;
        }
        return get_config('local_obf', 'obfclientid');
    }

    /**
     * Get current active client id
     *
     * @return string
     */
    public function local_events() {
        return get_config('local_obf', 'apidataretrieve') == self::RETRIEVE_LOCAL;
    }

    /**
     * Set current active API client credentials
     *
     * @param object $input Input row from local_obf_oauth2 table
     * @return null
     */
    public function set_oauth2($input) {

        if (!preg_match('/^https?:\/\/.+/', $input->obf_url)) {
            throw new Exception('Invalid parameter $obf_url');
        }
        if (!preg_match('/^\w+$/', $input->client_id)) {
            throw new Exception('Invalid parameter $clientid');
        }
        if (!preg_match('/^\w+$/', $input->client_secret)) {
            throw new Exception('Invalid parameter $clientsecret');
        }

        self::$clientid = $input->client_id;

        $input->obf_url = rtrim($input->obf_url, '/');

        $this->oauth2 = $input;
    }

    /**
     * Get access token. Request a new access token using client credentials if needed.
     *
     * @return array access token and expiration timestamp
     */
    public function oauth2_access_token() {
        global $DB;

        $this->require_client_id();

        if (!isset($this->oauth2->access_token) || $this->oauth2->token_expires < time()) {

            $url = $this->obf_url() . '/v1/client/oauth2/token';

            $params = array(
                'grant_type' => 'client_credentials',
                'client_id' => $this->oauth2->client_id,
                'client_secret' => $this->oauth2->client_secret
            );

            $curl = $this->get_transport();
            $options = $this->get_curl_options(false);

            $res = $curl->post($url, http_build_query($params), $options);
            $res = json_decode($res);

            if (!isset($res)) {
                $res = new stdClass(); // Initialiser $res comme un objet.
                $res->error = get_string('apierror503', 'local_obf');
            }

            if (isset($res->error)) {
                throw new Exception(get_string('failedtogetaccesstoken', 'local_obf') . $res->error);
            }

            $this->oauth2->access_token = $res->access_token;
            $this->oauth2->token_expires = time() + $res->expires_in;

            $sql = "UPDATE {local_obf_oauth2} SET access_token = ?, token_expires = ? WHERE client_id = ?";
            $DB->execute($sql, array($this->oauth2->access_token, $this->oauth2->token_expires, $this->oauth2->client_id));
        }

        return array(
            'access_token' => $this->oauth2->access_token,
            'token_expires' => $this->oauth2->token_expires
        );
    }

    /**
     * Returns a new curl-instance.
     *
     * @return \curl
     */
    public function get_transport() {
        if (!is_null($this->transport)) {
            return $this->transport;
        }

        // Use Moodle's curl-object if no transport is defined.
        return new curl();
    }

    /**
     * Set object transport.
     *
     * @param curl $transport
     */
    public function set_transport($transport) {
        $this->transport = $transport;
    }

    /**
     * Returns the default CURL-settings for a request.
     *
     * @return array
     */
    private function get_curl_options($auth = true) {

        // Don't verify localhost dev server.
        $url = $this->obf_url();
        $secure = strpos($url, 'https://localhost/') !== 0;

        $opt = array(
            'RETURNTRANSFER' => true,
            'FOLLOWLOCATION' => false,
            'SSL_VERIFYHOST' => $secure ? 2 : 0,
            'SSL_VERIFYPEER' => $secure ? 1 : 0
        );

        if ($auth) {
            if (isset($this->oauth2)) {
                $token = $this->oauth2_access_token();
                $opt['HTTPHEADER'] = array("Authorization: Bearer " . $token['access_token']);
            } else {
                $opt['SSLCERT'] = $this->get_cert_filename();
                $opt['SSLKEY'] = $this->get_pkey_filename();
            }
        }

        return $opt;
    }

    /**
     * Decode line-delimited json
     *
     * @param string $input response string
     * @return array The json-decoded response.
     */
    private function decode_ldjson($input) {
        $out = array();
        foreach (explode("\r\n", $input) as $chunk) {
            if ($chunk) {
                $out[] = json_decode($chunk, true);
            }
        }
        return $out;
    }

    /**
     * Get raw response.
     *
     * @return string[] Raw response.
     */
    public function get_raw_response() {
        return $this->rawresponse;
    }

    /**
     * Enable/disable storing raw response.
     *
     * @param bool $enable
     * @return obf_client This object.
     */
    public function set_enable_raw_response($enable) {
        $this->enablerawresponse = $enable;
        $this->rawresponse = null;
        return $this;
    }

    /**
     * Makes a CURL-request to OBF API (new style).
     *
     * @param string $method The HTTP method.
     * @param string $url The API path.
     * @param array $params The params of the request.
     * @return string The response string.
     * @throws Exception In case something goes wrong.
     */
    public function request($method, $url = '', $params = array(), $retry = true, $otheroauth2 = null) {
        global $DB;

        $curl = $this->get_transport();
        $options = $this->get_curl_options();

        if ($method === 'get') {
            $response = $curl->get($url, $params, $options);
        } else if ($method === 'post') {
            $response = $curl->post($url, json_encode($params), $options);
        } else if ($method === 'put') {
            $response = $curl->put($url, json_encode($params), $options);
        } else if ($method === 'delete') {
            $response = $curl->delete($url, $params, $options);
        } else {
            throw new Exception('unknown method ' . $method);
        }

        $this->rawresponse = null;
        if ($this->enablerawresponse) {
            $this->rawresponse = $curl->get_raw_response();
        }

        $info = $curl->get_info();

        if ($info['http_code'] === 403 && empty(get_config('local_obf', 'obfclientid'))) {
            if ($retry) {
                // Try again one time.
                return $this->request($method, $url, $params, false, $otheroauth2);
            }
            // Try with all other available connections.
            if (isset($this->oauth2)) {
                if (is_null($otheroauth2)) {
                    $otheroauth2 = $DB->get_records_select('local_obf_oauth2', 'client_id != ?', array($this->oauth2->client_id));
                }
                if (!empty($otheroauth2)) {
                    $this->set_oauth2(array_shift($otheroauth2));
                    return $this->request($method, $url, $params, true, $otheroauth2);
                }
            }
        }

        $this->httpcode = $info['http_code'];
        $this->error = '';

        // Codes 2xx should be ok.
        if (is_numeric($this->httpcode) && ($this->httpcode < 200 || $this->httpcode >= 300)) {
            $this->error = isset($response['error']) ? $response['error'] : '';
            $appendtoerror = defined('PHPUNIT_TEST') && PHPUNIT_TEST ? ' ' . $method . ' ' . $url : '';
            throw new Exception(get_string('apierror' . $this->httpcode, 'local_obf',
                    $this->error) . $appendtoerror, $this->httpcode);
        }

        return $response;
    }

    /* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

    /**
     * Tests the connection to OBF API.
     *
     * @return int Returns the error code on failure and -1 on success.
     */
    public function test_connection() {
        if (!self::has_client_id()) {
            return 0;
        }
        try {
            $url = $this->obf_url() . '/v1/ping/' . $this->client_id();
            $this->request('get', $url);
            return -1;
        } catch (Exception $exc) {
            return $exc->getCode();
        }
    }

    /**
     * Get all the badges from the API.
     *
     * @param string[] $categories Filter badges by these categories.
     * @return array The badges data.
     */
    public function get_badges(array $categories = array(), $query = '') {
        global $DB;

        $params = array('draft' => 0, 'external' => 1);

        // Checks rules.
        // Add categories to request if special rules are set.
        $courseid = optional_param('courseid', null, PARAM_INT);
        if ($courseid) {
            $course = get_course($courseid);

            $categoryid = $course->category;

            // Get the category path.
            $categorypath = $DB->get_field('course_categories', 'path', ['id' => $categoryid]);

            // Split the category path into an array of category IDs.
            $categoryids = explode('/', trim($categorypath, '/'));

            // Add the current category ID to the array.
            $categoryids[] = $categoryid;

            // Prepare the placeholders for the SQL query.
            $placeholders = implode(',', array_fill(0, count($categoryids), '?'));

            // If any rules are define on site we will prevent display categories in case there is no rule for current categ.
            $anyrulesdefinesql = "SELECT * FROM {local_obf_rulescateg}";
            $anyrules = $DB->get_records_sql($anyrulesdefinesql);

            // Construct the SQL query.
            $sql = "SELECT * FROM {local_obf_rulescateg} WHERE oauth2_id = ? AND (coursecategorieid IN ($placeholders))";
            $params = [obf_client::get_instance()->oauth2->id];
            $params = array_merge($params, $categoryids);

            $rules = $DB->get_records_sql($sql, $params);

            $haszero = false; // Variable to track if at least one occurrence of zero is found.

            foreach ($rules as $rule) {
                if ($rule->badgecategoriename === '0' || $rule->badgecategoriename === null) {
                    $haszero = true; // An occurrence of zero is found.
                    break; // Exit the loop as soon as an occurrence is found.
                }

                if (!in_array($rule->badgecategoriename, $categories)) {
                    $categories[] = $rule->badgecategoriename;
                }
            }

            if ($haszero) {
                $categories = []; // Reset $categories to null if an occurrence of zero is found.
            }
        }

        if (empty($rules) && !empty($anyrules)) {
            return [];
        }

        if (count($categories) > 0) {
            $params['category'] = implode('|', $categories);
        }
        if (!empty($query)) {
            $params['query'] = $query;
        }

        $url = $this->obf_url() . '/v1/badge/' . $this->client_id();
        $res = $this->request('get', $url, $params);

        $out = $this->decode_ldjson($res);

        $coll = new Collator('root');
        $coll->setStrength( Collator::PRIMARY );
        usort($out, function ($a, $b) use ($coll) {
            return $coll->compare($a['name'], $b['name']);
        });

        return $out;
    }

    /**
     * Get all the badges from the API for all configured OAuth2 clients.
     *
     * @param string[] $categories Filter badges by these categories.
     * @return array The badges data.
     */
    public function get_badges_all(array $categories = array(), $query = '') {
        global $DB;

        $oauth2 = $DB->get_records('local_obf_oauth2', null, 'client_name');

        if (empty($oauth2)) {
            return $this->get_badges($categories, $query);
        }

        $prevoauth2 = $this->oauth2;

        $out = [];
        foreach ($oauth2 as $o2) {
            $this->set_oauth2($o2);
            $out = array_merge($out, $this->get_badges($categories, $query));
        }
        $this->set_oauth2($prevoauth2);

        return $out;
    }

    /**
     * Get a single badge from the API.
     *
     * @param string $badgeid
     * @return array The badge data.
     * @throws Exception If the request fails
     */
    public function get_badge($badgeid) {
        $url = $this->obf_url() . '/v1/badge/' . $this->client_id() . '/' . $badgeid;
        $res = $this->request('get', $url);

        return json_decode($res, true);
    }

    /**
     * Get issuer data from the API.
     *
     * @return array The issuer data.
     * @throws Exception If the request fails
     */
    public function get_issuer() {
        $url = $this->obf_url() . '/v1/client/' . $this->client_id();
        $res = $this->request('get', $url);

        return json_decode($res, true);
    }

    public function get_client_info() {
        return $this->get_issuer();
    }

    /**
     * Get badge issuing events from the API.
     *
     * @param string $badgeid The id of the badge.
     * @param string $email The email address of the recipient.
     * @param array $params Optional extra params for the query.
     * @return array The event data.
     */
    public function get_assertions($badgeid = null, $email = null, $params = array()) {

        if (is_null($badgeid) && !is_null($email)) {
            return array();
        }
        if ($this->local_events()) {
            $params['api_consumer_id'] = OBF_API_CONSUMER_ID;
        }
        if (!is_null($badgeid)) {
            $params['badge_id'] = $badgeid;
        }
        if (!is_null($email) && $email != "") {
            $params['email'] = $email;
        }

        $url = $this->obf_url() . '/v1/event/' . $this->client_id();
        $res = $this->request('get', $url, $params);

        return $this->decode_ldjson($res);
    }

    /**
     * Get single recipient's all badge issuing events from the API for all connections.
     *
     * @param string $badgeid The id of the badge.
     * @param string $email The email address of the recipient.
     * @param array $params Optional extra params for the query.
     * @return array The event data.
     */
    public function get_assertions_all($email, $params = array()) {
        global $DB;

        if ($this->local_events()) {
            $params['api_consumer_id'] = OBF_API_CONSUMER_ID;
        }
        $params['email'] = $email;

        if (get_config('local_obf', 'obfclientid')) {
            // Legacy connection, only one client.
            $url = $this->obf_url() . '/v1/event/' . $this->client_id();
            $res = $this->request('get', $url, $params);
            return $this->decode_ldjson($res);
        }

        $this->eventlookup = [];

        $prevo2 = $this->oauth2;

        $oauth2 = $DB->get_records('local_obf_oauth2');

        $out = [];
        if (!empty($oauth2)) {
            foreach ($oauth2 as $o2) {
                $this->set_oauth2($o2);

                $host = $this->obf_url();
                $url = $host . '/v1/event/' . $this->client_id();
                $res = $this->request('get', $url, $params);

                foreach ($this->decode_ldjson($res) as $r) {
                    // Collect host info and add to $out
                    $this->eventlookup[$r['id']] = ['host' => $host];
                    $out[] = $r;
                }

            }
        }
        $this->set_oauth2($prevo2);

        return $out;
    }

    /**
     * Get single issuing event from the API.
     *
     * @param string $eventid The id of the event.
     * @return array The event data.
     */
    public function get_event($eventid) {
        $url = $this->obf_url() . '/v1/event/' . $this->client_id() . '/' . $eventid;
        $res = $this->request('get', $url);

        return json_decode($res, true);
    }

    /**
     * Get event's revoked assertions from the API.
     *
     * @param string $eventid The id of the event.
     * @return array The revoked data.
     */
    public function get_revoked($eventid) {
        $url = $this->obf_url() . '/v1/event/' . $this->client_id() . '/' . $eventid . '/revoked';
        $res = $this->request('get', $url);

        return json_decode($res, true);
    }

    /**
     * Get badge categories from the API.
     *
     * @return array The category data.
     */
    public function get_categories() {
        $url = $this->obf_url() . '/v1/badge/' . $this->client_id() . '/_/categorylist';
        $res = $this->request('get', $url);

        return json_decode($res, true);
    }

    /**
     * Delete a badge. Use with caution.
     */
    public function delete_badge($badgeid) {
        $url = $this->obf_url() . '/v1/badge/' . $this->client_id() . '/' . $badgeid;
        $this->request('delete', $url);
    }

    /**
     * Deletes all client badges. Use with caution.
     */
    public function delete_badges() {
        $url = $this->obf_url() . '/v1/badge/' . $this->client_id();
        $this->request('delete', $url);
    }

    /**
     * Exports a badge to Open Badge Factory
     *
     * @param obf_badge $badge The badge.
     */
    public function export_badge(obf_badge $badge) {
        $params = array(
            'name' => $badge->get_name(),
            'description' => $badge->get_description(),
            'image' => $badge->get_image(),
            'css' => $badge->get_criteria_css(),
            'criteria_html' => $badge->get_criteria_html(),
            'email_subject' => $badge->get_email()->get_subject(),
            'email_body' => $badge->get_email()->get_body(),
            'email_link_text' => $badge->get_email()->get_link_text(),
            'email_footer' => $badge->get_email()->get_footer(),
            'expires' => '',
            'tags' => array(),
            'draft' => $badge->is_draft()
        );

        $url = $this->obf_url() . '/v1/badge/' . $this->client_id();
        $this->request('post', $url, $params);
    }

    /**
     * Issues a badge.
     *
     * @param obf_badge $badge The badge to be issued.
     * @param string[] $recipients The recipient list, array of emails.
     * @param int $issuedon The issuance date as a Unix timestamp
     * @param string $email The email to send (template).
     * @param string $criteriaaddendum The criteria addendum.
     */

    public function issue_badge(obf_badge $badge, $recipients, $issuedon, $email, $criteriaaddendum, $course, $activity) {
        global $CFG, $DB;

        // Before doing anything we test connection.
        // If test_connection failed we throw an Error.
        $httpcode = $this->test_connection();
        if ($httpcode !== -1) {
            throw new Exception(get_string('connectionerror', 'local_obf'), $httpcode);
        }

        $recipientsnameemail = [];

        $userfields = 'id, email, ' . implode(', ',
                ['firstnamephonetic', 'lastnamephonetic', 'middlename', 'alternatename', 'firstname', 'lastname']
            );

        $users = $DB->get_records_list('user', 'email', $recipients, '', $userfields);
        $now = time();
        $sql = "INSERT INTO {local_obf_history_emails} (user_id, email, timestamp) VALUES (?,?,?)";

        $courseid = $badge->get_course_id(); // ID cours.

        // Declare message content for managers notifications.
        $managerfullmessage = '';
        $managerfullmessagehtml = '';

        foreach ($users as $user) {

            try {
                $DB->execute($sql, array($user->id, $user->email, $now));
            } catch (dml_write_exception $e) {
                // Ignore duplicate entry errors.
                if (!$DB->record_exists('local_obf_history_emails', array('user_id' => $user->id, 'email' => $user->email))) {
                    throw $e;
                }
            }
            // Add username, if available.
            if ($user->firstname && $user->lastname && $user->email && !preg_match('/[><]/', $user->email)) {
                $recipientsnameemail[] = fullname($user) . ' <' . $user->email . '>';
            } else {
                $recipientsnameemail[] = $user->email;
            }

            // Sending notification.
            // Get the user ID who is receiving the badge.
            $userid = $user->id;

            // Compose the message.
            $message = new message();
            $message->component = 'local_obf'; // The component triggering the message.
            $message->name = 'issued'; // The name of your custom message.
            // The user sending the message (can be an admin or system).
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = \core_user::get_user($userid); // The user receiving the message.
            $badgename = $badge->get_name();
            $message->subject = get_string('congratsbadgeearned', 'local_obf', $badgename);
            $courseurl = new moodle_url('/course/view.php', array('id' => $courseid));
            if (empty($courseid)) {
                $courseid = 1;
            }
            $coursename = get_course($courseid)->fullname;

            $courselink = html_writer::link($courseurl, $coursename);
            $badgelink = $badge->get_name();

            // We need both.
            $message->fullmessage = get_string('newbadgeearned', 'local_obf',
                array('courselink' => $courselink, 'badgelink' => $badgelink));
            $message->fullmessagehtml = get_string('newbadgeearned', 'local_obf',
                array('courselink' => $courselink, 'badgelink' => $badgelink));
            $message->fullmessageformat = FORMAT_MARKDOWN;

            // Send the message.
            $usermessages[] = $message;

            $courseurl = new moodle_url('/course/view.php', array('id' => $courseid));
            $courselink = html_writer::link($courseurl, $coursename);

            // Prepare message for managers.
            $managerfullmessage = $managerfullmessage . '<br>'
                . get_string('badgeissuedbody', 'local_obf', [
                'badgename' => $badgename,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'courselink' => $courselink,
            ]) . '<br>';
            $managerfullmessagehtml = $managerfullmessagehtml . '<br>'
            . get_string('badgeissuedbody', 'local_obf', [
                'badgename' => $badgename,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'courselink' => $courselink,
            ]) . '<br>';
        }

        // Send notification to teachers.
        $capability = 'local/obf:viewspecialnotif'; // Capability name.
        if (get_course($courseid)->category) {
            $contextrole = context_coursecat::instance(get_course($courseid)->category);
        } else {
            $contextrole = context_system::instance();
        }

        // Get the roles matching the capability.
        $roles = get_roles_with_cap_in_context($contextrole, $capability);

        // Get the users with the matching roles in the course.
        $roleids = array_keys($roles[0]);
        $managerusers = array();
        foreach ($roleids as $roleid) {
            $roleusers = get_role_users($roleid, context_course::instance($courseid), false, 'u.*');
            // Add role users to $managerusers only if they don't already exist.
            foreach ($roleusers as $roleuser) {
                $userexists = false;

                foreach ($managerusers as $manageruser) {
                    if ($manageruser->id == $roleuser->id) {
                        $userexists = true;
                        break;
                    }
                }

                if (!$userexists) {
                    $managerusers[] = $roleuser;
                }
            }
        }

        // If no users found, send the notification to platform admins.
        if (empty($managerusers) && $courseid == 1) {
            $managerusers = get_admins();
        }

        // Loop Users list.
        foreach ($managerusers as $manageruser) {
            // Access Users data.
            // Compose the message.
            $messagemanagerbadgeissue = new message();

            $userid = $manageruser->id;

            $messagemanagerbadgeissue->component = 'local_obf'; // The component triggering the message.
            $messagemanagerbadgeissue->name = 'issuedbadgetostudent'; // The name of your custom message.
            // The user sending the message (can be an admin or system).
            $messagemanagerbadgeissue->userfrom = \core_user::get_noreply_user();
            $messagemanagerbadgeissue->userto = \core_user::get_user($userid); // The user receiving the message.
            if (empty($courseid)) {
                $courseid = 1;
            }

            $messagemanagerbadgeissue->subject = get_string('badgeissuedsubject', 'local_obf', [
                'badgename' => $badgename
            ]);

            $messagemanagerbadgeissue->fullmessage = $managerfullmessage;
            $messagemanagerbadgeissue->fullmessagehtml = $managerfullmessagehtml;

            $messagemanagerbadgeissue->fullmessageformat = FORMAT_MARKDOWN;

            // Send the message.
            $managermessages[] = $messagemanagerbadgeissue;
        }

        $coursename = $badge->get_course_name($course);

        $params = array(
            'recipient' => $recipientsnameemail,
            'issued_on' => $issuedon,
            'api_consumer_id' => OBF_API_CONSUMER_ID,
            'log_entry' => array('course_id' => strval($course),
                'course_name' => $coursename,
                'activity_name' => $activity,
                'wwwroot' => $CFG->wwwroot),
            'show_report' => 1
        );

        if (!is_null($email)) {
            $params['email_subject'] = $email->get_subject();
            $params['email_body'] = $email->get_body();
            $params['email_footer'] = $email->get_footer();
            $params['email_link_text'] = $email->get_link_text();
        }

        if (!empty($criteriaaddendum)) {
            $params['badge_override'] = array('criteria_add' => $criteriaaddendum);
        }

        if (!is_null($badge->get_expires()) && $badge->get_expires() > 0) {
            $params['expires'] = $badge->get_expires();
        }

        $url = $this->obf_url() . '/v1/badge/' . $this->client_id() . '/' . $badge->get_id();
        $this->request('post', $url, $params);

        // Sending notifications messages only if request is done with no error.
        // At this point we assume that error are handle in the request method.
        if (isset($usermessages)) {
            foreach ($usermessages as $message) {
                message_send($message);
            }
        }

        if (isset($managermessages)) {
            foreach ($managermessages as $message) {
                message_send($message);
            }
        }
    }

    /**
     * Revoke an issued event.
     *
     * @param string $eventid
     * @param string[] $emails Array of emails to revoke the event for.
     */
    public function revoke_event($eventid, $emails) {
        $emails = array_map('urlencode', $emails);
        $url = $this->obf_url() . '/v1/event/' . $this->client_id() . '/' . $eventid . '/?email=' . implode('|', $emails);
        $this->request('delete', $url);
    }

    public function pub_get_badge($badgeid, $eventid) {
        if ($this->eventlookup && isset($this->eventlookup[$eventid]['host'])) {
            $host = $this->eventlookup[$eventid]['host'];
        }
        else {
            $host = $this->obf_url();
        }

        $url = $host . '/v1/badge/_/' . $badgeid . '.json';
        $params = array('v' => '1.1', 'event' => $eventid);
        try {
            $res = $this->request('get', $url, $params);
            return json_decode($res, true);
        } catch (Exception $e) {
            debugging('');
        }
    }

    // LEGACY api auth.

    /**
     * Deauthenticates the plugin.
     */
    public function deauthenticate() {
        @unlink($this->get_cert_filename());
        @unlink($this->get_pkey_filename());

        unset_config('obfclientid', 'local_obf');
        unset_config('apiurl', 'local_obf');
    }

    /**
     * creates apiurl
     *
     * @return url
     */
    private function url_checker($url) {
        if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
            $url = "https://" . $url;
        }
        if (!preg_match("/\/$/", $url)) {
            $url = $url . "/";
        }

        return $url;
    }

    /**
     * set v1 to end of url.
     * example: https://openbadgefactory.com/v1
     *
     * @param  $url
     * @return url/v1
     */
    private function api_url_maker($url) {
        $version = "v1";
        return $url . $version;
    }

    public function get_branding_image_url($imagename = 'issued_by') {
        return $this->obf_url() . '/v1/badge/_/' . $imagename . '.png';
    }

    public function get_branding_image($imagename = 'issued_by') {
        $curl = $this->get_transport();
        $curlopts = $this->get_curl_options();
        $curlopts['FOLLOWLOCATION'] = true;
        $image = $curl->get($this->get_branding_image_url($imagename), array(), $curlopts);
        if ($curl->info['http_code'] !== 200) {
            return null;
        }
        return $image;
    }

    /**
     * Tries to authenticate the plugin against OBF API.
     *
     * @param string $signature The request token from OBF.
     * @return boolean Returns true on success.
     * @throws Exception If something goes wrong.
     */
    public function authenticate($signature, $url) {
        $pkidir = realpath($this->get_pki_dir());

        // Certificate directory not writable.
        if (!is_writable($pkidir)) {
            throw new Exception(get_string('pkidirnotwritable', 'local_obf',
                $pkidir));
        }

        $signature = trim($signature);
        $token = base64_decode($signature);
        $curl = $this->get_transport();
        $curlopts = $this->get_curl_options(false);

        $url = $this->url_checker($url);

        $apiurl = $this->api_url_maker($url);

        // For localhost test server.
        if (strpos($apiurl, 'https://localhost/') === 0) {
            $curlopts['SSL_VERIFYHOST'] = 0;
            $curlopts['SSL_VERIFYPEER'] = 0;
        }

        // We don't need these now, we haven't authenticated yet.
        unset($curlopts['SSLCERT']);
        unset($curlopts['SSLKEY']);

        $pubkey = $curl->get($apiurl . '/client/OBF.rsa.pub', array(), $curlopts);

        // CURL-request failed.
        if ($pubkey === false) {
            throw new Exception(get_string('pubkeyrequestfailed', 'local_obf') .
                ': ' . $curl->error);
        }

        // Server gave us an error.
        if ($curl->info['http_code'] !== 200) {
            throw new Exception(get_string('pubkeyrequestfailed', 'local_obf') . ': ' .
                get_string('apierror' . $curl->info['http_code'], 'local_obf'));
        }

        $decrypted = '';

        // Get the public key...
        $key = openssl_pkey_get_public($pubkey);

        // ... That didn't go too well.
        if ($key === false) {
            throw new Exception(get_string('pubkeyextractionfailed', 'local_obf') .
                ': ' . openssl_error_string());
        }

        // Couldn't decrypt data with provided key.
        if (openssl_public_decrypt($token, $decrypted, $key,
                OPENSSL_PKCS1_PADDING) === false) {
            throw new Exception(get_string('tokendecryptionfailed', 'local_obf') .
                ': ' . openssl_error_string());
        }

        $json = json_decode($decrypted);

        // Yay, we have the client-id. Let's store it somewhere.
        set_config('obfclientid', $json->id, 'local_obf');
        set_config('apiurl', $url, 'local_obf');

        // Create a new private key.
        $config = array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA);
        $privkey = openssl_pkey_new($config);

        // Export the new private key to a file for later use.
        openssl_pkey_export_to_file($privkey, $this->get_pkey_filename());

        $csrout = '';
        $dn = array('commonName' => $json->id);

        // Create a new CSR with the private key we just created.
        $csr = openssl_csr_new($dn, $privkey);

        // Export the CSR into string.
        if (openssl_csr_export($csr, $csrout) === false) {
            throw new Exception(get_string('csrexportfailed', 'local_obf'));
        }

        if (empty($csrout)) {
            $opensslerrors = 'CSR output empty.';
            while (($opensslerror = openssl_error_string()) !== false) {
                $opensslerrors .= $opensslerror . " \n ";
            }
            throw new Exception($opensslerrors);
        }

        $postdata = json_encode(array('signature' => $signature, 'request' => $csrout));
        $cert = $curl->post($apiurl . '/client/' . $json->id . '/sign_request',
            $postdata, $curlopts);

        // Fetching certificate failed.
        if ($cert === false) {
            throw new Exception(get_string('certrequestfailed', 'local_obf') . ': ' . $curl->error);
        }

        $httpcode = $curl->info['http_code'];

        // Server gave us an error.
        if ($httpcode !== 200) {
            $jsonresp = json_decode($cert);
            $extrainfo = is_null($jsonresp) ? get_string('apierror' . $httpcode,
                'local_obf') : $jsonresp->error;

            throw new Exception(get_string('certrequestfailed', 'local_obf') . ': ' . $extrainfo);
        }

        // Everything's ok, store the certificate into a file for later use.
        file_put_contents($this->get_cert_filename(), $cert);

        return true;
    }

    /**
     * Returns the expiration date of the OBF certificate as a unix timestamp.
     *
     * @return mixed The expiration date or false if the certificate is missing.
     */
    public function get_certificate_expiration_date() {
        $certfile = $this->get_cert_filename();

        if (!file_exists($certfile)) {
            return false;
        }

        $cert = file_get_contents($certfile);
        $ssl = openssl_x509_parse($cert);

        return $ssl['validTo_time_t'];
    }

    /**
     * Get absolute filename of certificate key-file.
     *
     * @return string
     */
    public function get_pkey_filename() {
        return $this->get_pki_dir() . 'obf.key';
    }

    /**
     * Get absolute filename of certificate pem-file.
     *
     * @return string
     */
    public function get_cert_filename() {
        return $this->get_pki_dir() . 'obf.pem';
    }

    /**
     * Get absolute path of certificate directory.
     *
     * @return string
     */
    public function get_pki_dir() {
        global $CFG;
        return $CFG->dataroot . '/local_obf/pki/';
    }

    /**
     * Get absolute path of certificate directory.
     *
     * @return object
     */
    public function get_oauth2id() {
        return $this->oauth2;
    }
}
