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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_adele\local\access_request;

/**
 * What the request dialog needs for one node course: the people to ask, the
 * prefilled message and, if nobody can be asked, the site's fallback text
 * (local_adele #600, TP2). Nothing is sent here.
 *
 * @package    mod_adele
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_access_request extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Context of the mod_adele activity'),
            'courseid' => new external_value(PARAM_INT, 'The node course to ask for'),
        ]);
    }

    /**
     * The dialog's data.
     *
     * @param int $contextid Context of the mod_adele activity.
     * @param int $courseid The node course.
     * @return array
     */
    public static function execute(int $contextid, int $courseid): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['contextid' => $contextid, 'courseid' => $courseid]);
        $cm = get_node_course_access::require_teacher_view($params['contextid']);
        $target = access_request::require_possible($cm, $params['courseid']);

        $recipients = [];
        foreach (access_request::recipients((int) $target->id, (int) $USER->id) as $user) {
            $recipients[] = ['id' => (int) $user->id, 'fullname' => fullname($user)];
        }
        $fallback = trim((string) get_config('mod_adele', 'teachercourseaccessrequestfallback'));
        return [
            'courseid' => (int) $target->id,
            'coursename' => format_string($target->fullname, true, ['context' => \context_course::instance($target->id)]),
            'recipients' => $recipients,
            'message' => access_request::draft($cm, $target),
            'fallback' => $fallback === '' ? '' : format_text($fallback, FORMAT_PLAIN),
            'maxlength' => access_request::MAX_MESSAGE,
        ];
    }

    /**
     * Result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'The node course'),
            'coursename' => new external_value(PARAM_TEXT, 'Its full name, formatted'),
            'recipients' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'User id'),
                'fullname' => new external_value(PARAM_TEXT, 'Full name'),
            ])),
            'message' => new external_value(PARAM_TEXT, 'The prefilled, editable message'),
            'fallback' => new external_value(PARAM_RAW, 'The site\'s text for a course without anybody to ask (HTML)'),
            'maxlength' => new external_value(PARAM_INT, 'Longest message accepted'),
        ]);
    }
}
