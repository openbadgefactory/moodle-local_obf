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
 * Mock Open Badge Factory API served by the Behat site.
 *
 * Use $CFG->wwwroot . '/local/obf/tests/fixtures/mock_obf_api.php' as the API URL of an OAuth2
 * connection; API paths follow it as path info, e.g. .../mock_obf_api.php/v2/client/XYZ1234/ping.
 * See local_obf_behat_mock_obf_api for the responses.
 *
 * @package    local_obf
 * @copyright  2013-2026, Open Badge Factory Oy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing
define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../../../config.php');

defined('BEHAT_SITE_RUNNING') || die();

require_once(__DIR__ . '/behat_mock_obf_api.php');

$method = strtolower($_SERVER['REQUEST_METHOD'] ?? 'get');
$path = $_SERVER['PATH_INFO'] ?? '';
$query = $_SERVER['QUERY_STRING'] ?? '';
$body = file_get_contents('php://input');

$headers = function_exists('getallheaders') ? getallheaders() : [];
$authorization = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$options = ['HTTPHEADER' => $authorization === '' ? [] : ['Authorization: ' . $authorization]];

$api = new local_obf_behat_mock_obf_api($CFG->wwwroot . '/local/obf/tests/fixtures/mock_obf_api.php');
$url = $path . ($query === '' ? '' : '?' . $query);
if ($method === 'get' || $method === 'delete') {
    $response = $api->$method($url, [], $options);
} else {
    $response = $api->$method($url, $body, $options);
}

http_response_code($api->get_info()['http_code']);
foreach ($api->get_raw_response() as $header) {
    if (stripos($header, 'HTTP/') !== 0) {
        header($header);
    }
}
echo $response;
