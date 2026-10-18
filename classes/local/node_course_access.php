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

namespace mod_adele\local;

use context_course;
use moodle_url;

/**
 * Which node courses of an embedding a person can open (teacher view).
 *
 * The courses come from the learning path the activity embeds - never from
 * the request - in the order of the path's nodes and, within a stack, in the
 * order stored for the node. Whether a course is open is Moodle's answer
 * (can_access_course, active enrolments only): an enrolment, the right to
 * view without one (administrators, managers), guest access. A course the
 * person cannot open gets no link.
 *
 * Whatever the activity's teacher access mode (teacher_access_mode), a
 * course that is open gets its link; the mode only decides what happens
 * without access: with "request", the row offers a request (access_request),
 * with an enrolment mode an enrolment (teacher_enrolment) or the reason why
 * not. Nobody is enrolled and nothing is sent here.
 *
 * @package    mod_adele
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class node_course_access {
    /** @var string The person can open the course. */
    public const STATUS_OPEN = 'open';

    /** @var string The person cannot open the course. */
    public const STATUS_NONE = 'none';

    /** @var string No access; the person may send a request to the course's responsible people. */
    public const STATUS_REQUEST = 'request';

    /** @var string No access; "request" mode, but the course has nobody to ask: a text to copy. */
    public const STATUS_REQUEST_COPY = 'request_copy';

    /** @var string No access; an enrolment mode, and the person may enrol (TP4). */
    public const STATUS_ENROL = 'enrol';

    /** @var string No access; an enrolment mode, but not for this person or course (TP4). */
    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * The node courses of a learning path that exist, in path order; course
     * id => the nodes it belongs to.
     *
     * @param int $learningpathid
     * @return array
     */
    public static function path_courses(int $learningpathid): array {
        global $DB;
        $json = $learningpathid
            ? json_decode((string) $DB->get_field('local_adele_learning_paths', 'json', ['id' => $learningpathid]), true)
            : null;
        $nodes = [];
        foreach (($json['tree']['nodes'] ?? []) as $node) {
            foreach ((array) ($node['data']['course_node_id'] ?? []) as $courseid) {
                $courseid = (int) $courseid;
                if ($courseid > 0 && $courseid != SITEID) {
                    $nodes[$courseid][] = (string) ($node['id'] ?? '');
                }
            }
        }
        if (!$nodes) {
            return [];
        }
        $existing = $DB->get_fieldset_select(
            'course',
            'id',
            'id ' . $DB->get_in_or_equal(array_keys($nodes))[0],
            $DB->get_in_or_equal(array_keys($nodes))[1]
        );
        return array_intersect_key($nodes, array_flip(array_map('intval', $existing)));
    }

    /**
     * The node courses of the learning path an activity embeds, each with the
     * nodes it belongs to and its access status for the current user.
     *
     * @param \stdClass|\cm_info $cm The mod_adele course module.
     * @return array[] One entry per course, in path order.
     */
    public static function for_activity($cm): array {
        global $DB, $USER;
        $mode = teacher_access_mode::for_instance((int) $cm->instance);
        $request = $mode === teacher_access_mode::REQUEST;
        $enrol = in_array($mode, teacher_access_mode::ENROL_MODES, true);

        $learningpathid = (int) $DB->get_field('adele', 'learningpathid', ['id' => $cm->instance]);
        $json = $learningpathid
            ? json_decode((string) $DB->get_field('local_adele_learning_paths', 'json', ['id' => $learningpathid]), true)
            : null;

        // Course id => node ids, in the order the path lists its nodes.
        $nodes = [];
        foreach (($json['tree']['nodes'] ?? []) as $node) {
            $courseids = $node['data']['course_node_id'] ?? [];
            foreach ((array) $courseids as $courseid) {
                $courseid = (int) $courseid;
                if ($courseid <= 0 || $courseid == SITEID) {
                    continue;
                }
                $nodes[$courseid][] = (string) ($node['id'] ?? '');
            }
        }
        if (!$nodes) {
            return [];
        }

        $courses = $DB->get_records_list('course', 'id', array_keys($nodes), '', 'id, shortname, fullname, visible, category');
        $result = [];
        foreach ($nodes as $courseid => $nodeids) {
            if (empty($courses[$courseid])) {
                // A node pointing at a course that no longer exists: no action.
                continue;
            }
            $course = $courses[$courseid];
            $context = context_course::instance($courseid);
            $open = can_access_course($course, null, '', true);
            $entry = [
                'courseid' => $courseid,
                'shortname' => format_string($course->shortname, true, ['context' => $context]),
                'fullname' => format_string($course->fullname, true, ['context' => $context]),
                'nodeids' => array_values(array_unique(array_filter($nodeids))),
                'status' => $open ? self::STATUS_OPEN : self::STATUS_NONE,
            ];
            if (!$open && $request) {
                // Mode "request": ask the people responsible for this course -
                // or, if it has none, a text to copy; never a button that
                // pretends to work (local_adele #600, TP2).
                $entry['status'] = access_request::recipients($courseid, (int) $USER->id)
                    ? self::STATUS_REQUEST
                    : self::STATUS_REQUEST_COPY;
            }
            if (!$open && $enrol) {
                // An enrolment mode (TP4): enrol oneself - where everything
                // holds for this person and course; said why not otherwise.
                $why = teacher_enrolment::refusal($cm, $courseid, (int) $USER->id);
                if ($why === null) {
                    $entry['status'] = self::STATUS_ENROL;
                    $role = $DB->get_record('role', ['id' => teacher_access_mode::target_role($mode)]);
                    $entry['role'] = role_get_name($role, $context, ROLENAME_ALIAS);
                    $entry['roledescription'] = role_get_description($role);
                } else {
                    $entry['status'] = self::STATUS_UNAVAILABLE;
                    $entry['reason'] = get_string($why, 'mod_adele');
                }
            }
            if ($open) {
                $entry['url'] = (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false);
            }
            $result[] = $entry;
        }
        return $result;
    }
}
