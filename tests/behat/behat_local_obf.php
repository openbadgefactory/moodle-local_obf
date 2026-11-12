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
 * Behat steps for local_obf.
 *
 * @package    local_obf
 * @copyright  2013-2026, Open Badge Factory Oy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Behat steps for local_obf.
 *
 * The Behat site talks to a mock OBF API ({@see local_obf_behat_mock_obf_api}), whose
 * organisation has the badges "Behat Test Badge" and "Second Behat Badge".
 *
 * @copyright  2013-2026, Open Badge Factory Oy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_obf extends behat_base {

    /**
     * Convert page names to URLs for steps like 'When I am on the "local_obf > [page name]" page'.
     *
     * Recognised page names are:
     * | Settings        | OAuth2 API connections |
     * | Badge list      | All badges of the site |
     * | Awarding history | Site awarding history |
     *
     * @param string $page name of the page.
     * @return moodle_url the corresponding URL.
     */
    protected function resolve_page_url(string $page): moodle_url {
        switch (core_text::strtolower($page)) {
            case 'settings':
                return new moodle_url('/local/obf/config.php');
            case 'badge list':
                return new moodle_url('/local/obf/badge.php', ['action' => 'list']);
            case 'awarding history':
                return new moodle_url('/local/obf/badge.php', ['action' => 'history']);
            default:
                throw new Exception('Unrecognised local_obf page "' . $page . '."');
        }
    }

    /**
     * Convert page names to URLs for steps like 'When I am on the "[identifier]" "local_obf > [page type]" page'.
     *
     * Recognised page names are:
     * | pagetype         | identifier  | description                       |
     * | Badge            | Badge name  | Badge details                     |
     * | Awarding rules   | Badge name  | Awarding rules of the badge       |
     * | Badge history    | Badge name  | Awarding history of the badge     |
     * | Course badges    | Course name | Badges in the course              |
     *
     * @param string $type identifies which type of page this is.
     * @param string $identifier identifies the particular page.
     * @return moodle_url the corresponding URL.
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        switch (core_text::strtolower($type)) {
            case 'badge':
                return $this->badge_url($identifier, 'details');
            case 'awarding rules':
                return $this->badge_url($identifier, 'criteria');
            case 'badge history':
                return $this->badge_url($identifier, 'history');
            case 'course badges':
                return new moodle_url('/local/obf/badge.php',
                    ['action' => 'list', 'courseid' => $this->get_course_id($identifier)]);
            default:
                throw new Exception('Unrecognised local_obf page type "' . $type . '."');
        }
    }

    /**
     * URL of a badge page.
     *
     * @param string $name Badge name in the mock OBF API.
     * @param string $show Tab of the badge page.
     * @return moodle_url
     */
    private function badge_url(string $name, string $show): moodle_url {
        require_once(__DIR__ . '/../fixtures/behat_mock_obf_api.php');
        foreach (local_obf_behat_mock_obf_api::default_badges() as $badge) {
            if ($badge['content'][0]['name'] === $name) {
                return new moodle_url('/local/obf/badge.php', ['action' => 'show', 'id' => $badge['id'], 'show' => $show]);
            }
        }
        throw new ExpectationException('The mock OBF API has no badge "' . $name . '"', $this->getSession());
    }
}
