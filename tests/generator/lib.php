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
 * Data generator.
 *
 * @package    local_obf
 * @copyright  2013-2026, Open Badge Factory Oy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Data generator for local_obf.
 *
 * @copyright  2013-2026, Open Badge Factory Oy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_obf_generator extends component_generator_base {

    /**
     * Create an OAuth2 API connection.
     *
     * @param array|stdClass $record Fields of local_obf_oauth2. Optional 'roles' is a comma-separated
     *      list of role shortnames that may issue badges with this connection.
     * @return stdClass The local_obf_oauth2 record.
     */
    public function create_connection($record = null) {
        global $CFG, $DB;

        $record = (array)$record;
        $roles = array_filter(array_map('trim', explode(',', $record['roles'] ?? '')));
        unset($record['roles']);

        $record = (object)array_merge([
            'client_id' => 'XYZ1234',
            'client_secret' => 'XYZ1234',
            'client_name' => 'Behat Test Organisation',
            // Mock OBF API served by the Behat site, see tests/fixtures/mock_obf_api.php.
            'obf_url' => $CFG->wwwroot . '/local/obf/tests/fixtures/mock_obf_api.php',
            'access_token' => 'MOCKACCESSTOKEN',
            'token_expires' => time() + DAYSECS,
        ], $record);
        $record->id = $DB->insert_record('local_obf_oauth2', $record);

        foreach ($roles as $shortname) {
            $roleid = $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
            // No id column, so insert_record() can't be used.
            $DB->execute('INSERT INTO {local_obf_oauth2_role} (oauth2_id, role_id) VALUES (?, ?)', [$record->id, $roleid]);
        }

        return $record;
    }
}
