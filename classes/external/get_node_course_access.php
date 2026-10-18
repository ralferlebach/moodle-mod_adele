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

use context;
use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_adele\local\node_course_access;
use moodle_exception;

/**
 * The node courses of one embedding, and whether the person asking can open
 * each of them - for the teacher view of mod_adele.
 *
 * Read only. Everything is decided here, on the server, for the activity the
 * module context names: which courses belong to its path (the client cannot
 * add one), who may ask (the teacher view of this activity, with or without
 * the right to change a learner's path), and whether a course is open to
 * this person (Moodle's own can_access_course). The browser only renders the
 * answer.
 *
 * @package    mod_adele
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_node_course_access extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Context of the mod_adele activity'),
        ]);
    }

    /**
     * The node courses of the activity with their access status.
     *
     * @param int $contextid Context of the mod_adele activity.
     * @return array
     */
    public static function execute(int $contextid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['contextid' => $contextid]);
        $cm = self::require_teacher_view($params['contextid']);

        return [
            // The mode that acts: the activity's own while the site allows it
            // (issue local_adele #600, TP3).
            'mode' => \mod_adele\local\teacher_access_mode::for_instance((int) $cm->instance),
            'courses' => node_course_access::for_activity($cm),
        ];
    }

    /**
     * The course module of a mod_adele activity whose teacher view the
     * current user has - or an exception. Shared by the services of the
     * teacher view's course access.
     *
     * @param int $contextid Context of the mod_adele activity.
     * @return \stdClass The course module.
     */
    public static function require_teacher_view(int $contextid): \stdClass {
        $context = context::instance_by_id($contextid);
        if (!$context instanceof context_module) {
            throw new moodle_exception('invalidcontext', 'error');
        }
        $cm = get_coursemodule_from_id('adele', $context->instanceid, 0, false, MUST_EXIST);
        self::validate_context($context);

        $mode = \mod_adele\local\view_mode::for_context($context);
        if ($mode['view'] !== 'teacher') {
            throw new \required_capability_exception($context, 'local/adele:viewparticipants', 'nopermissions', '');
        }
        return $cm;
    }

    /**
     * Result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'mode' => new external_value(PARAM_ALPHANUMEXT, 'Effective access mode of the activity'),
            'courses' => new external_multiple_structure(new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Course id'),
                'shortname' => new external_value(PARAM_TEXT, 'Short name, formatted'),
                'fullname' => new external_value(PARAM_TEXT, 'Full name, formatted'),
                'nodeids' => new external_multiple_structure(
                    new external_value(PARAM_ALPHANUMEXT, 'A node of the path this course belongs to')
                ),
                'status' => new external_value(
                    PARAM_ALPHANUMEXT,
                    'open: the person can open the course; none: not; request: may ask; request_copy: nobody to ask'
                ),
                'url' => new external_value(PARAM_URL, 'The course, only when it is open to the person', VALUE_OPTIONAL),
            ])),
        ]);
    }
}
