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

namespace mod_adele\event;

/**
 * A teacher enrolled into a node course from the teacher view of the
 * activity (local_adele #600, TP4). Logged in the activity's context; the
 * related user is the person enrolled; other: courseid, roleid, mode.
 *
 * @package    mod_adele
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class node_course_enrolled extends \core\event\base {
    /**
     * Init.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'adele';
    }

    /**
     * Name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_node_course_enrolled', 'mod_adele');
    }

    /**
     * Description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->relateduserid}' enrolled into the course with id '{$this->other['courseid']}' "
            . "with the role with id '{$this->other['roleid']}' (mode '{$this->other['mode']}') from the learning path "
            . "activity with course module id '{$this->contextinstanceid}'.";
    }

    /**
     * The course enrolled into.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/course/view.php', ['id' => $this->other['courseid']]);
    }

    /**
     * Validation.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['courseid', 'roleid', 'mode'] as $key) {
            if (empty($this->other[$key])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
    }

    /**
     * Mapping for restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'adele', 'restore' => 'adele'];
    }

    /**
     * Mapping of other for restore.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return [
            'courseid' => ['db' => 'course', 'restore' => 'course'],
            'roleid' => ['db' => 'role', 'restore' => 'role'],
            'mode' => \core\event\base::NOT_MAPPED,
        ];
    }
}
