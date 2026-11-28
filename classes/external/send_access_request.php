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
use mod_adele\local\access_request;

/**
 * Send a request for access to one node course (local_adele #600, TP2) -
 * only when the teacher clicks "Send". Every condition is checked again
 * here, whatever the browser showed.
 *
 * @package    mod_adele
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_access_request extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Context of the mod_adele activity'),
            'courseid' => new external_value(PARAM_INT, 'The node course to ask for'),
            'recipientid' => new external_value(PARAM_INT, 'The person to ask, one of get_access_request\'s recipients'),
            'message' => new external_value(PARAM_TEXT, 'The teacher\'s message'),
        ]);
    }

    /**
     * Send.
     *
     * @param int $contextid Context of the mod_adele activity.
     * @param int $courseid The node course.
     * @param int $recipientid The recipient.
     * @param string $message The message.
     * @return array
     */
    public static function execute(int $contextid, int $courseid, int $recipientid, string $message): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'courseid' => $courseid,
            'recipientid' => $recipientid,
            'message' => $message,
        ]);
        $cm = get_node_course_access::require_teacher_view($params['contextid']);
        $target = access_request::require_possible($cm, $params['courseid']);
        access_request::send($cm, $target, $params['recipientid'], $params['message']);
        $recipient = \core_user::get_user($params['recipientid']);
        return ['sent' => true, 'recipient' => fullname($recipient)];
    }

    /**
     * Result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'sent' => new external_value(PARAM_BOOL, 'Sent'),
            'recipient' => new external_value(PARAM_TEXT, 'Whom it was sent to'),
        ]);
    }
}
