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
 * Message providers. See https://docs.moodle.org/dev/Message_API for details.
 *
 * @package    local_obf
 * @copyright  2013-2020, Open Badge Factory Oy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$messageproviders = array(
    'revoked' => array (
        'defaults' => array(
            'email' => MESSAGE_DISALLOWED,
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ),
    ),
    'issued' => array (
        'defaults' => array(
            'email' => MESSAGE_DISALLOWED,
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ),
    ),
    'issuedbadgetostudent' => array (
        'defaults' => array(
            'email' => MESSAGE_DISALLOWED,
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ),
        'capability' => 'local/obf:viewspecialnotif'
    ),
    'revokedbadgetostudent' => array (
        'defaults' => array(
            'email' => MESSAGE_DISALLOWED,
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ),
        'capability' => 'local/obf:viewspecialnotif'
    )
);
