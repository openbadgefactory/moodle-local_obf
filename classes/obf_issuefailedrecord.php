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

namespace classes;

/**
 * Class to handle obf_issuefailedrecord elements.
 *
 * @package    local_obf
 * @author  Sylvain Revenu | Pimenko 2024
 * @copyright  2013-2020, Open Badge Factory Oy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class obf_issuefailedrecord {

    protected $id;
    protected $recipients;
    protected $timestamp;
    protected $email;
    protected $criteriaAddendum;
    protected $status;
    protected $items;

    /**
     * Constructor for initializing object properties from a given record.
     *
     * @param object $record An object containing properties for initialization.
     * @return void
     */
    public function __construct($record) {
        $this->id = $record->id;
        $this->recipients = $record->recipients;
        $this->timestamp = $record->time;
        $this->email = $record->email;
        $this->criteriaAddendum = $record->criteriaaddendum;
        $this->status = $record->status;
    }

    public function getId() {
        return $this->id;
    }

    public function getRecipients() {
        return json_decode($this->recipients);
    }

    public function getTimestamp() {
        return $this->timestamp;
    }

    public function getEmail() {
        $email = $this->email;
        return json_decode($email,true);
    }

    public function getCriteriaAddendum() {
        return $this->criteriaAddendum;
    }

    public function getStatus() {
        return $this->status;
    }

    public function delete() {
        global $DB;

        if ($DB->delete_records('local_obf_issuefailedrecord', array('id' => $this->id))) {
            return true;
        }
        return false;
    }
}