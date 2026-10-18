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
 * TP1 of the issue "Kurszugriff in der Lehrendenansicht": the only mode is
 * existing_only - nobody is enrolled and nothing is requested here.
 *
 * @package    mod_adele
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class node_course_access {
    /** @var string Only courses the person can already open get a link. */
    public const MODE_EXISTING_ONLY = 'existing_only';

    /** @var string The person can open the course. */
    public const STATUS_OPEN = 'open';

    /** @var string The person cannot open the course. */
    public const STATUS_NONE = 'none';

    /**
     * The node courses of the learning path an activity embeds, each with the
     * nodes it belongs to and its access status for the current user.
     *
     * @param \stdClass|\cm_info $cm The mod_adele course module.
     * @return array[] One entry per course, in path order.
     */
    public static function for_activity($cm): array {
        global $DB;

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
            if ($open) {
                $entry['url'] = (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false);
            }
            $result[] = $entry;
        }
        return $result;
    }
}
