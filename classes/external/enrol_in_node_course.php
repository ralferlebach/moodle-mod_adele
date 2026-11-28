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

namespace mod_adele\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_adele\local\teacher_enrolment;

/**
 * Enrol oneself into a node course from the teacher view (local_adele #600,
 * TP4) - after the teacher confirmed it. The role is the site's for the
 * activity's mode; the browser names only the course, and everything is
 * checked again here.
 *
 * @package    mod_adele
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_in_node_course extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Context of the mod_adele activity'),
            'courseid' => new external_value(PARAM_INT, 'The node course to enrol into'),
        ]);
    }

    /**
     * Enrol.
     *
     * @param int $contextid Context of the mod_adele activity.
     * @param int $courseid The node course.
     * @return array
     */
    public static function execute(int $contextid, int $courseid): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['contextid' => $contextid, 'courseid' => $courseid]);
        $cm = get_node_course_access::require_teacher_view($params['contextid']);
        $roleid = teacher_enrolment::enrol($cm, $params['courseid']);
        $role = $DB->get_record('role', ['id' => $roleid], '*', MUST_EXIST);
        return [
            'enrolled' => true,
            'role' => role_get_name($role, \context_course::instance($params['courseid']), ROLENAME_ALIAS),
        ];
    }

    /**
     * Result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'enrolled' => new external_value(PARAM_BOOL, 'Enrolled'),
            'role' => new external_value(PARAM_TEXT, 'The role given'),
        ]);
    }
}
