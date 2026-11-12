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
 * Mock Open Badge Factory API used by the Behat site.
 *
 * @package    local_obf
 * @copyright  2013-2026, Open Badge Factory Oy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Curl-compatible mock of the OBF public API V2.
 *
 * The source of truth is the OpenAPI spec "OBF public API V2", version 202608
 * (https://openbadgefactory.com/obf-api-v2/). Responses contain only the fields of the spec
 * schemas, request params are validated against the spec, and example values are taken from
 * the spec where it has them. Endpoints that are not in the spec answer 404.
 *
 * The one exception is the public API v1 badge class, GET /v1/badge/_/{badge_id}.json, which the
 * plugin uses by design for public badge details (no personal data, no authentication). API v2 has
 * no public equivalent and the v1 documentation doesn't cover it, so its format is taken from real
 * responses of openbadgefactory.com (Open Badges 1.1 BadgeClass, captured 2026-10-01).
 *
 * The Behat site serves it over HTTP from mock_obf_api.php: an OAuth2 connection whose API URL
 * points there makes the plugin talk to it with Moodle's real curl. Issued events and created
 * badges are stored in plugin config, so the state is reset between scenarios.
 *
 * @copyright  2013-2026, Open Badge Factory Oy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_obf_behat_mock_obf_api {
    /** Client id (organisation id), spec example of client_id. */
    const CLIENT_ID = 'XYZ1234';

    /** Client secret that the token endpoint accepts, spec example of client_secret. */
    const CLIENT_SECRET = 'XYZ1234';

    /** Access token returned by the token endpoint and required by every other endpoint. */
    const ACCESS_TOKEN = 'MOCKACCESSTOKEN';

    /** Client name returned by GET /v2/client/{client_id}. */
    const CLIENT_NAME = 'Behat Test Organisation';

    /** Spec example of ctime and mtime. */
    const EXAMPLE_TIME = 1234567890;

    /** @var array Info of the last request, like curl::get_info(). */
    private $info = ['http_code' => 200];

    /** @var string[] Response headers of the last request, like curl::get_raw_response(). */
    private $rawresponse = [];

    /** @var string API URL of this mock, used in the URLs of public v1 badge responses. */
    private $baseurl;

    /**
     * Constructor.
     *
     * @param string $baseurl API URL of this mock.
     */
    public function __construct($baseurl = '') {
        $this->baseurl = rtrim($baseurl, '/');
    }

    /**
     * HTTP GET.
     *
     * @param string $url
     * @param array $params
     * @param array $options
     * @return string
     */
    public function get($url, $params = [], $options = []) {
        return $this->handle('get', $url, (array)$params, $options);
    }

    /**
     * HTTP POST.
     *
     * @param string $url
     * @param string|array $params JSON or url-encoded body.
     * @param array $options
     * @return string
     */
    public function post($url, $params = '', $options = []) {
        return $this->handle('post', $url, $this->decode_body($params), $options);
    }

    /**
     * HTTP PUT.
     *
     * @param string $url
     * @param string|array $params JSON body.
     * @param array $options
     * @return string
     */
    public function put($url, $params = '', $options = []) {
        return $this->handle('put', $url, $this->decode_body($params), $options);
    }

    /**
     * HTTP DELETE.
     *
     * @param string $url
     * @param array $params
     * @param array $options
     * @return string
     */
    public function delete($url, $params = [], $options = []) {
        return $this->handle('delete', $url, (array)$params, $options);
    }

    /**
     * Info of the last request.
     *
     * @return array
     */
    public function get_info() {
        return $this->info;
    }

    /**
     * Response headers of the last request. The spec documents no response headers.
     *
     * @return string[]
     */
    public function get_raw_response() {
        return $this->rawresponse;
    }

    /**
     * Badges that exist in the mock organisation from the start, in the Badge schema.
     *
     * @return array[] Badge id => badge.
     */
    public static function default_badges() {
        $image = 'data:image/png;base64,' . base64_encode(file_get_contents(__DIR__ . '/../behat/badge.png'));
        $badges = [];
        foreach (['BADGE0001' => 'Behat Test Badge', 'BADGE0002' => 'Second Behat Badge'] as $id => $name) {
            $badges[$id] = [
                'id' => $id,
                'category' => [],
                'image' => $image,
                'primary_language' => 'en',
                'content' => [[
                    'language' => 'en',
                    'name' => $name,
                    'description' => $name . ' description',
                    'criteria' => $name . ' criteria',
                    'tag' => [],
                    'alignment' => [],
                ]],
                'creator' => null,
                'intent' => null,
                'client_alias_id' => [],
                'email_message' => null,
                'expires' => 0,
                'draft' => false,
                'ctime' => self::EXAMPLE_TIME,
                'mtime' => self::EXAMPLE_TIME,
            ];
        }
        return $badges;
    }

    /**
     * Route a request to its handler.
     *
     * @param string $method
     * @param string $url
     * @param array $params Query or body params.
     * @param array $options Curl options, used for the Authorization header.
     * @return string Response body.
     */
    private function handle($method, $url, array $params, array $options) {
        $this->info = ['http_code' => 200];
        $this->rawresponse = [];

        $path = (string)parse_url($url, PHP_URL_PATH);
        $query = [];
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $params = array_merge($query, $params);

        // Paths and operation ids of the spec.
        $routes = [
            ['post', '#^/v2/client/oauth2/token$#', 'client_oauth2_token'],
            ['get', '#^/v2/client/(\w{1,100})/ping$#', 'ping'],
            ['get', '#^/v2/client/(\w{1,100})/alias$#', 'list_aliases'],
            ['get', '#^/v2/client/(\w{1,100})$#', 'get_client'],
            ['get', '#^/v2/badge/(\w{1,100})$#', 'list_badges'],
            ['post', '#^/v2/badge/(\w{1,100})$#', 'create_badge'],
            ['get', '#^/v2/badge/(\w{1,100})/(\w{1,100})$#', 'get_badge'],
            ['delete', '#^/v2/badge/(\w{1,100})/(\w{1,100})$#', 'delete_badge'],
            ['post', '#^/v2/event/(\w{1,100})/(\w{1,100})/issue$#', 'issue_badge'],
            ['get', '#^/v2/event/(\w{1,100})$#', 'list_events'],
            ['get', '#^/v2/event/(\w{1,100})/recipient$#', 'list_event_recipients'],
            ['get', '#^/v2/event/(\w{1,100})/(\w{1,100})$#', 'get_event'],
            ['put', '#^/v2/event/(\w{1,100})/(\w{1,100})/revoke$#', 'revoke_badge'],
            // Public API v1, see the class comment.
            ['get', '#^/v1/badge/_/(\w{1,100})\.json$#', 'v1_public_badge'],
        ];
        $public = ['client_oauth2_token', 'v1_public_badge'];

        foreach ($routes as [$routemethod, $pattern, $handler]) {
            if ($routemethod !== $method || !preg_match($pattern, $path, $matches)) {
                continue;
            }
            array_shift($matches);
            if (!in_array($handler, $public)) {
                $clientid = array_shift($matches);
                if (!$this->is_authorized($options) || $clientid !== self::CLIENT_ID) {
                    return $this->forbidden();
                }
            }
            return $this->{'handle_' . $handler}($path, $params, ...$matches);
        }

        return $this->error(404, 'No such endpoint in OBF public API V2: ' . strtoupper($method) . ' ' . $path, $path);
    }

    /**
     * POST /v2/client/oauth2/token (clientOauth2Token).
     *
     * @param string $path
     * @param array $params
     * @return string
     */
    private function handle_client_oauth2_token($path, array $params) {
        if (($params['grant_type'] ?? '') !== 'client_credentials') {
            return $this->error(400, 'grant_type must be "client_credentials"', $path);
        }
        if (($params['client_id'] ?? '') !== self::CLIENT_ID || ($params['client_secret'] ?? '') !== self::CLIENT_SECRET) {
            // Real API (checked 2026-10-01): HTTP 403, ErrorResult without "message".
            $this->info['http_code'] = 403;
            $this->rawresponse = ['HTTP/1.1 403 Forbidden', 'Content-Type: application/json'];
            return json_encode(['timestamp' => $this->timestamp(), 'status' => 403, 'error' => 'Forbidden', 'path' => $path]);
        }
        return $this->json(['access_token' => self::ACCESS_TOKEN, 'token_type' => 'bearer', 'expires_in' => 18000]);
    }

    /**
     * GET /v2/client/{client_id}/ping (ping).
     *
     * @param string $path
     * @param array $params
     * @return string
     */
    private function handle_ping($path, array $params) {
        return $this->json(['success' => true]);
    }

    /**
     * GET /v2/client/{client_id}/alias (listAliases).
     *
     * @param string $path
     * @param array $params
     * @return string
     */
    private function handle_list_aliases($path, array $params) {
        return $this->json(['result' => []]);
    }

    /**
     * GET /v2/client/{client_id} (getClient).
     *
     * @param string $path
     * @param array $params
     * @return string
     */
    private function handle_get_client($path, array $params) {
        return $this->json([
            'id' => self::CLIENT_ID,
            'name' => self::CLIENT_NAME,
            'email' => 'firstname.lastname@example.com',
            'url' => 'http://example.com',
            'description' => '',
            'image' => '',
            'tier' => 'pro',
            'paid_until' => self::EXAMPLE_TIME,
            'aliases' => 0,
            'issuing_limit' => 0,
            'country' => '',
            'type' => '',
            'reply_to' => 'firstname.lastname@example.com',
            'billing_email' => 'firstname.lastname@example.com',
            'vat_id' => '',
            'searchable' => false,
            'verified' => false,
            'ctime' => self::EXAMPLE_TIME,
            'mtime' => self::EXAMPLE_TIME,
        ]);
    }

    /**
     * GET /v2/badge/{client_id} (listBadges), BadgeListElement items.
     *
     * @param string $path
     * @param array $params
     * @return string
     */
    private function handle_list_badges($path, array $params) {
        if ($error = $this->validate_paging($path, $params)) {
            return $error;
        }
        $draft = $this->to_bool($params['draft'] ?? false);
        $badges = array_values(array_filter($this->get_state()['badges'], fn($b) => $b['draft'] === $draft));
        $result = array_map(function($badge) {
            $content = $badge['content'][0];
            return [
                'id' => $badge['id'],
                'name' => $content['name'],
                'description' => $content['description'],
                'image' => 'http://example.com/badge/' . $badge['id'] . '/image.png',
                'primary_language' => $badge['primary_language'],
                'language' => array_column($badge['content'], 'language'),
                'category' => $badge['category'],
                'tag' => $content['tag'] ?? [],
                'client_alias_id' => $badge['client_alias_id'],
                'creator_id' => null,
                'draft' => $badge['draft'],
                'ctime' => $badge['ctime'],
                'mtime' => $badge['mtime'],
            ];
        }, $badges);
        return $this->json(['result' => $this->paginate($result, $params), 'total' => count($result)]);
    }

    /**
     * POST /v2/badge/{client_id} (createBadge), BadgeInput body.
     *
     * @param string $path
     * @param array $params
     * @return string
     */
    private function handle_create_badge($path, array $params) {
        foreach (['primary_language', 'content', 'image'] as $field) {
            if (empty($params[$field])) {
                return $this->error(400, 'Missing required field ' . $field, $path);
            }
        }
        if (!preg_match('#^data:image/png;base64,[a-zA-Z0-9+/=]+$#', $params['image'])) {
            return $this->error(400, 'image must be a PNG data URL', $path);
        }
        foreach ($params['content'] as $content) {
            foreach (['language', 'name', 'description', 'criteria'] as $field) {
                if (!isset($content[$field])) {
                    return $this->error(400, 'Missing required field content.' . $field, $path);
                }
            }
        }

        $state = $this->get_state();
        $id = 'BADGE' . sprintf('%04d', count($state['badges']) + 1);
        $state['badges'][$id] = [
            'id' => $id,
            'category' => $params['category'] ?? [],
            'image' => $params['image'],
            'primary_language' => $params['primary_language'],
            'content' => $params['content'],
            'creator' => null,
            'intent' => $params['intent'] ?? null,
            'client_alias_id' => $params['client_alias_id'] ?? [],
            'email_message' => $params['email_message'] ?? null,
            'expires' => $params['expires'] ?? 0,
            'draft' => $this->to_bool($params['draft'] ?? true),
            'ctime' => time(),
            'mtime' => time(),
        ];
        $this->set_state($state);
        return $this->operation_result('badge', $id, 1);
    }

    /**
     * GET /v2/badge/{client_id}/{badge_id} (getBadge), Badge schema.
     *
     * @param string $path
     * @param array $params
     * @param string $badgeid
     * @return string
     */
    private function handle_get_badge($path, array $params, $badgeid) {
        $badge = $this->get_state()['badges'][$badgeid] ?? null;
        if (!$badge) {
            return $this->error(404, 'Badge not found', $path);
        }
        return $this->json($badge);
    }

    /**
     * DELETE /v2/badge/{client_id}/{badge_id} (deleteBadge).
     *
     * @param string $path
     * @param array $params
     * @param string $badgeid
     * @return string
     */
    private function handle_delete_badge($path, array $params, $badgeid) {
        $state = $this->get_state();
        if (!isset($state['badges'][$badgeid])) {
            return $this->error(404, 'Badge not found', $path);
        }
        unset($state['badges'][$badgeid]);
        $this->set_state($state);
        return $this->operation_result('badge', $badgeid, 1);
    }

    /**
     * POST /v2/event/{client_id}/{badge_id}/issue (issueBadge).
     *
     * @param string $path
     * @param array $params
     * @param string $badgeid
     * @return string
     */
    private function handle_issue_badge($path, array $params, $badgeid) {
        $state = $this->get_state();
        $badge = $state['badges'][$badgeid] ?? null;
        if (!$badge) {
            return $this->error(404, 'Badge not found', $path);
        }
        if (!array_key_exists('api_consumer_id', $params)) {
            return $this->error(400, 'Missing required field api_consumer_id', $path);
        }
        $recipients = $params['recipient'] ?? null;
        if (!is_array($recipients) || count($recipients) < 1 || count($recipients) > 10000) {
            return $this->error(400, 'recipient must have 1-10000 items', $path);
        }
        foreach ($recipients as $recipient) {
            if (!is_array($recipient) || !array_key_exists('email', $recipient) || !array_key_exists('name', $recipient)) {
                return $this->error(400, 'Each recipient requires email and name', $path);
            }
            if (!preg_match('/^[^@<]{1,64}@[^@>]{1,255}$/', (string)$recipient['email'])) {
                return $this->error(400, 'Invalid recipient email', $path);
            }
        }

        $now = time();
        $eventid = 'EVENT' . sprintf('%04d', count($state['events']) + 1);
        $issuedon = isset($params['issued_on']) ? (int)$params['issued_on'] : $now;
        $expireson = isset($params['expires_on']) ? (int)$params['expires_on'] : null;
        $clientaliasid = $params['client_alias_id'] ?? null;
        $state['events'][$eventid] = [
            'id' => $eventid,
            'name' => $params['event_name'] ?? $badge['content'][0]['name'],
            'client_alias_id' => $clientaliasid,
            'badge_id' => $badgeid,
            'badge' => [
                'id' => $badgeid,
                'image' => $badge['image'],
                'primary_language' => $badge['primary_language'],
                'content' => $badge['content'],
                'creator' => $badge['creator'],
            ],
            'expires_on' => $expireson,
            'issued_on' => $issuedon,
            'recipient_count' => count($recipients),
            'email_message' => $params['email_message'] ?? null,
            'log_entry' => isset($params['log_entry']) ? json_encode($params['log_entry']) : null,
            'api_consumer_id' => $params['api_consumer_id'],
            'earnable_application_id' => null,
            'ctime' => $now,
            'mtime' => $now,
        ];
        foreach ($recipients as $recipient) {
            $assertionid = substr(sha1($eventid . $recipient['email']), 0, 20);
            $state['recipients'][] = [
                'event_id' => $eventid,
                'badge_id' => $badgeid,
                'client_alias_id' => $clientaliasid,
                'assertion_id' => $assertionid,
                'email' => $recipient['email'],
                'name' => $recipient['name'],
                'receive_url' => 'http://example.com/receive/' . $assertionid,
                'assertion_json_url' => 'http://example.com/assertion/' . $assertionid . '.json',
                'received' => false,
                'in_passport' => false,
                'pdf_downloaded' => false,
                'issued_on' => $issuedon,
                'expires_on' => $expireson,
                'revoked' => false,
            ];
        }
        $this->set_state($state);
        return $this->operation_result('event', $eventid, count($recipients));
    }

    /**
     * GET /v2/event/{client_id} (listEvents), EventListElement items.
     *
     * @param string $path
     * @param array $params
     * @return string
     */
    private function handle_list_events($path, array $params) {
        if ($error = $this->validate_paging($path, $params)) {
            return $error;
        }
        $state = $this->get_state();
        $events = array_filter($state['events'], function($event) use ($params, $state) {
            if (!empty($params['email'])) {
                $emails = array_column(array_filter($state['recipients'], fn($r) => $r['event_id'] === $event['id']), 'email');
                if (!in_array(strtolower($params['email']), array_map('strtolower', $emails))) {
                    return false;
                }
            }
            return $this->event_matches($event, $params);
        });
        $events = $this->order($events, $params);
        $result = array_map(fn($e) => [
            'id' => $e['id'],
            'name' => $e['name'],
            'client_alias_id' => $e['client_alias_id'],
            'badge_id' => $e['badge_id'],
            'expires_on' => $e['expires_on'],
            'issued_on' => $e['issued_on'],
            'recipient_count' => $e['recipient_count'],
            'api_consumer_id' => $e['api_consumer_id'],
            'earnable_application_id' => $e['earnable_application_id'],
            'log_entry' => $e['log_entry'],
            'ctime' => $e['ctime'],
            'mtime' => $e['mtime'],
        ], $events);
        return $this->json(['result' => $this->paginate($result, $params), 'total' => count($result)]);
    }

    /**
     * GET /v2/event/{client_id}/recipient (listEventRecipients), EventRecipient items.
     *
     * @param string $path
     * @param array $params
     * @return string
     */
    private function handle_list_event_recipients($path, array $params) {
        if ($error = $this->validate_paging($path, $params)) {
            return $error;
        }
        $state = $this->get_state();
        $recipients = array_filter($state['recipients'], function($r) use ($params, $state) {
            if (!empty($params['event_id']) && $r['event_id'] !== $params['event_id']) {
                return false;
            }
            if (!empty($params['email']) && strcasecmp($r['email'], $params['email']) !== 0) {
                return false;
            }
            return $this->event_matches($state['events'][$r['event_id']], $params);
        });
        // Recipients are ordered by the time of their event.
        $ctimes = array_map(fn($r) => $state['events'][$r['event_id']]['ctime'], $recipients);
        array_multisort($ctimes, ($params['order_by'] ?? 'asc') === 'desc' ? SORT_DESC : SORT_ASC, $recipients);
        return $this->json(['result' => $this->paginate(array_values($recipients), $params), 'total' => count($recipients)]);
    }

    /**
     * GET /v2/event/{client_id}/{event_id} (getEvent), Event schema.
     *
     * @param string $path
     * @param array $params
     * @param string $eventid
     * @return string
     */
    private function handle_get_event($path, array $params, $eventid) {
        $event = $this->get_state()['events'][$eventid] ?? null;
        if (!$event) {
            return $this->error(404, 'Event not found', $path);
        }
        return $this->json([
            'id' => $event['id'],
            'name' => $event['name'],
            'client_alias_id' => $event['client_alias_id'],
            'badge' => $event['badge'],
            'expires_on' => $event['expires_on'],
            'issued_on' => $event['issued_on'],
            'email_message' => $event['email_message'],
            'log_entry' => $event['log_entry'],
            'api_consumer_id' => $event['api_consumer_id'],
            'earnable_application_id' => $event['earnable_application_id'],
            'ctime' => $event['ctime'],
            'mtime' => $event['mtime'],
        ]);
    }

    /**
     * PUT /v2/event/{client_id}/{event_id}/revoke (revokeBadge).
     *
     * @param string $path
     * @param array $params
     * @param string $eventid
     * @return string
     */
    private function handle_revoke_badge($path, array $params, $eventid) {
        $state = $this->get_state();
        if (!isset($state['events'][$eventid])) {
            return $this->error(404, 'Event not found', $path);
        }
        $emails = $params['recipient'] ?? null;
        if (!is_array($emails) || count($emails) < 1) {
            return $this->error(400, 'recipient must have at least 1 item', $path);
        }
        $emails = array_map('strtolower', $emails);
        $count = 0;
        foreach ($state['recipients'] as &$recipient) {
            if ($recipient['event_id'] === $eventid && in_array(strtolower($recipient['email']), $emails)) {
                $recipient['revoked'] = true;
                $count++;
            }
        }
        unset($recipient);
        $this->set_state($state);
        return $this->operation_result(null, null, $count);
    }

    /**
     * GET /v1/badge/_/{badge_id}.json?v=1.1&event={event_id} (public Open Badges 1.1 BadgeClass).
     *
     * @param string $path
     * @param array $params
     * @param string $badgeid
     * @return string
     */
    private function handle_v1_public_badge($path, array $params, $badgeid) {
        $badge = $this->get_state()['badges'][$badgeid] ?? null;
        if (!$badge) {
            return $this->error(404, 'Badge not found', $path);
        }
        $base = $this->baseurl . '/v1/badge/_/' . $badgeid;
        $event = isset($params['event']) ? 'event=' . $params['event'] : '';
        $content = $badge['content'][0];
        return $this->json([
            'id' => $base . '.json?v=1.1' . ($event ? '&' . $event : ''),
            '@context' => 'https://w3id.org/openbadges/v1',
            'name' => $content['name'],
            'image' => $base . '.png?' . ($event ? $event . '&' : '') . 'ext=.png',
            'description' => $content['description'],
            'type' => 'BadgeClass',
            'criteria' => $base . '/criteria.html' . ($event ? '?' . $event : ''),
            'issuer' => $this->baseurl . '/v1/client/?key=' . self::CLIENT_ID . '&v=1.1' . ($event ? '&' . $event : ''),
            'tags' => $content['tag'] ?? [],
        ]);
    }

    /**
     * Whether an event matches the event search params shared by listEvents and listEventRecipients.
     *
     * @param array $event
     * @param array $params
     * @return bool
     */
    private function event_matches(array $event, array $params) {
        foreach (['event_name' => 'name', 'api_consumer_id' => 'api_consumer_id', 'badge_id' => 'badge_id',
                'client_alias_id' => 'client_alias_id'] as $param => $field) {
            if (!empty($params[$param]) && (string)$event[$field] !== (string)$params[$param]) {
                return false;
            }
        }
        if (!empty($params['begin']) && $event['ctime'] < (int)$params['begin']) {
            return false;
        }
        if (!empty($params['end']) && $event['ctime'] > (int)$params['end']) {
            return false;
        }
        if (!empty($params['log_entry'])) {
            // Format "course_id:123,other_attribute:abc", every pair has to match.
            $logentry = json_decode((string)$event['log_entry'], true) ?: [];
            foreach (explode(',', $params['log_entry']) as $pair) {
                [$key, $value] = array_pad(explode(':', $pair, 2), 2, '');
                if (!isset($logentry[$key]) || (string)$logentry[$key] !== $value) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Sort events by creation time according to the order_by param (default asc).
     *
     * @param array $events
     * @param array $params
     * @return array
     */
    private function order(array $events, array $params) {
        $events = array_values($events);
        usort($events, fn($a, $b) => $a['ctime'] <=> $b['ctime'] ?: strcmp($a['id'], $b['id']));
        return ($params['order_by'] ?? 'asc') === 'desc' ? array_reverse($events) : $events;
    }

    /**
     * Validate limit, offset and order_by params.
     *
     * @param string $path
     * @param array $params
     * @return string|null Error response, or null when the params are valid.
     */
    private function validate_paging($path, array $params) {
        if (isset($params['limit']) && ((int)$params['limit'] < 1 || (int)$params['limit'] > 1000)) {
            return $this->error(400, 'limit must be between 1 and 1000', $path);
        }
        if (isset($params['offset']) && (int)$params['offset'] < 0) {
            return $this->error(400, 'offset must be at least 0', $path);
        }
        if (isset($params['order_by']) && !in_array($params['order_by'], ['asc', 'desc'], true)) {
            return $this->error(400, 'order_by must be asc or desc', $path);
        }
        return null;
    }

    /**
     * Apply limit and offset params to a result list.
     *
     * @param array $rows
     * @param array $params
     * @return array
     */
    private function paginate(array $rows, array $params) {
        return array_slice(array_values($rows), (int)($params['offset'] ?? 0), (int)($params['limit'] ?? 1000));
    }

    /**
     * Whether the request has a valid bearer token.
     *
     * @param array $options Curl options.
     * @return bool
     */
    private function is_authorized(array $options) {
        foreach ($options['HTTPHEADER'] ?? [] as $header) {
            if (strcasecmp(trim($header), 'Authorization: Bearer ' . self::ACCESS_TOKEN) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * OperationResult response.
     *
     * @param string|null $resourcetype
     * @param string|null $resourceid
     * @param int $count
     * @return string
     */
    private function operation_result($resourcetype, $resourceid, $count) {
        return $this->json(['success' => true, 'message' => '', 'resource_type' => $resourcetype,
            'resource_id' => $resourceid, 'count' => $count]);
    }

    /**
     * Successful JSON response.
     *
     * @param array $data
     * @return string
     */
    private function json(array $data) {
        $this->info['http_code'] = 200;
        $this->rawresponse = ['HTTP/1.1 200 OK', 'Content-Type: application/json'];
        return json_encode($data);
    }

    /**
     * ErrorResult response.
     *
     * @param int $code HTTP status code.
     * @param string $message
     * @param string $path
     * @return string
     */
    private function error($code, $message, $path) {
        $reasons = [400 => 'Bad Request', 401 => 'Unauthorized', 404 => 'Not Found'];
        $this->info['http_code'] = $code;
        $this->rawresponse = ['HTTP/1.1 ' . $code . ' ' . $reasons[$code], 'Content-Type: application/json'];
        return json_encode([
            'timestamp' => $this->timestamp(),
            'status' => $code,
            'error' => $reasons[$code],
            'message' => $message,
            'path' => $path,
        ]);
    }

    /**
     * ErrorResult timestamp in the format of the real API, e.g. "2026-10-01T11:48:35.551+00:00".
     *
     * @return string
     */
    private function timestamp() {
        return (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.vP');
    }

    /**
     * Response to a rejected access token, or a token used for another client.
     *
     * The spec documents no error responses. The real API answers both cases with HTTP 403 and
     * the plain text body "403 Forbidden" (checked against openbadgefactory.com, 2026-10-01).
     *
     * @return string
     */
    private function forbidden() {
        $this->info['http_code'] = 403;
        $this->rawresponse = ['HTTP/1.1 403 Forbidden', 'Content-Type: text/plain'];
        return '403 Forbidden';
    }

    /**
     * Boolean value of a query or body param.
     *
     * @param mixed $value
     * @return bool
     */
    private function to_bool($value) {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Decode a JSON or url-encoded request body.
     *
     * @param string|array $body
     * @return array
     */
    private function decode_body($body) {
        if (is_array($body)) {
            return $body;
        }
        $json = json_decode((string)$body, true);
        if (is_array($json)) {
            return $json;
        }
        $params = [];
        parse_str((string)$body, $params);
        return $params;
    }

    /**
     * Current mock API state.
     *
     * @return array
     */
    private function get_state() {
        $state = json_decode((string)get_config('local_obf', 'behat_mock_api_state'), true);
        if (!is_array($state)) {
            $state = ['badges' => self::default_badges(), 'events' => [], 'recipients' => []];
        }
        return $state;
    }

    /**
     * Store the mock API state.
     *
     * @param array $state
     */
    private function set_state(array $state) {
        set_config('behat_mock_api_state', json_encode($state), 'local_obf');
    }
}
