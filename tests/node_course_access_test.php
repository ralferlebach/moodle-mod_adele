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

namespace mod_adele;

use advanced_testcase;
use context_module;
use mod_adele\external\get_node_course_access;
use mod_adele\local\node_course_access;

/**
 * Course access in the teacher view of mod_adele (issue "Kurszugriff in der
 * Lehrendenansicht", TP1: existing_only).
 *
 * Two node courses - one the teacher is in, one not - plus a stack with two
 * more, a course that has been deleted, a suspended enrolment and a hidden
 * course: the answer says "open" exactly where Moodle lets the person in,
 * gives a link only there, keeps the path's order, and comes only from the
 * path the activity embeds. Who may ask: the teacher view of this activity,
 * with or without editing rights - not a learner, not somebody outside.
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_adele\local\node_course_access
 * @covers     \mod_adele\external\get_node_course_access
 */
final class node_course_access_test extends advanced_testcase {
    /** @var array Courses by key. */
    private array $courses = [];

    /** @var \stdClass The host course. */
    private \stdClass $host;

    /** @var \stdClass The mod_adele activity. */
    private \stdClass $adele;

    /**
     * A path: node 1 = A, node 2 = stack (B, C), node 3 = a deleted course,
     * node 4 = D (hidden). Embedded in a host course.
     *
     * @return void
     */
    private function plant(): void {
        global $DB;
        foreach (['A', 'B', 'C'] as $key) {
            $this->courses[$key] = $this->getDataGenerator()->create_course([
                'shortname' => "NK-$key",
                'fullname' => "Node course $key",
            ]);
        }
        $this->courses['D'] = $this->getDataGenerator()->create_course([
            'shortname' => 'NK-D',
            'fullname' => 'Hidden node course',
            'visible' => 0,
        ]);
        $gone = $this->getDataGenerator()->create_course();
        $goneid = (int) $gone->id;
        delete_course($gone, false);
        $nodes = [
            ['id' => 'dndnode_1', 'type' => 'custom', 'parentCourse' => [],
                'data' => ['course_node_id' => [(int) $this->courses['A']->id]]],
            ['id' => 'dndnode_2', 'type' => 'orcourses', 'parentCourse' => ['dndnode_1'],
                'data' => ['course_node_id' => [(int) $this->courses['C']->id, (int) $this->courses['B']->id]]],
            ['id' => 'dndnode_3', 'type' => 'custom', 'parentCourse' => ['dndnode_2'],
                'data' => ['course_node_id' => [$goneid]]],
            ['id' => 'dndnode_4', 'type' => 'custom', 'parentCourse' => ['dndnode_3'],
                'data' => ['course_node_id' => [(int) $this->courses['D']->id, SITEID]]],
        ];
        $lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'Kurszugriff-Testpfad',
            'description' => '',
            'timecreated' => time(),
            'timemodified' => time(),
            'createdby' => 2,
            'json' => json_encode(['tree' => ['nodes' => $nodes, 'edges' => []]]),
        ]);
        $this->host = $this->getDataGenerator()->create_course();
        $this->adele = $this->getDataGenerator()->create_module('adele', [
            'course' => $this->host->id,
            'learningpathid' => $lpid,
            // Participant source "this course": enrolling a learner subscribes
            // them; the deleted node course must not get in the way (#601).
            'participantslist' => [1],
            'hostenrolmentmode' => 'visible',
            'userlist' => 1,
            'view' => 1,
        ]);
    }

    /**
     * The answer for the current user, as course shortname => status/url.
     *
     * @return array
     */
    private function answer(): array {
        $cm = get_coursemodule_from_instance('adele', $this->adele->id, $this->host->id, false, MUST_EXIST);
        $out = [];
        foreach (node_course_access::for_activity($cm) as $row) {
            $out[$row['shortname']] = $row['status'] . (isset($row['url']) ? ' +link' : '') . ' ' . implode(',', $row['nodeids']);
        }
        return $out;
    }

    /**
     * A teacher of the host course who is in A (active), in B (suspended),
     * in D (hidden course), and not in C.
     *
     * @return void
     */
    public function test_open_exactly_where_moodle_lets_the_teacher_in(): void {
        $this->resetAfterTest();
        $this->plant();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->host->id, 'teacher');
        $this->getDataGenerator()->enrol_user($teacher->id, $this->courses['A']->id, 'teacher');
        $this->getDataGenerator()->enrol_user(
            $teacher->id,
            $this->courses['B']->id,
            'teacher',
            'manual',
            0,
            0,
            ENROL_USER_SUSPENDED
        );
        $this->getDataGenerator()->enrol_user($teacher->id, $this->courses['D']->id, 'student');
        $this->setUser($teacher);

        $this->assertSame([
            'NK-A' => 'open +link dndnode_1',
            // Stack: in the node's own order (C before B).
            'NK-C' => 'none dndnode_2',
            'NK-B' => 'none dndnode_2',
            'NK-D' => 'none dndnode_4',
        ], $this->answer(), 'Suspended is no access, a hidden course needs the right to see it; '
            . 'the deleted course and the site have no row.');
    }

    /**
     * The site administrator can open every course without an enrolment.
     *
     * @return void
     */
    public function test_administrator_opens_everything(): void {
        $this->resetAfterTest();
        $this->plant();
        $this->setAdminUser();
        $answer = $this->answer();
        $this->assertCount(4, $answer);
        foreach ($answer as $short => $state) {
            $this->assertStringStartsWith('open +link', $state, $short);
        }
    }

    /**
     * The web service: the teacher view with and without editing rights may
     * ask; a learner and somebody outside the course may not; a context of
     * another kind is refused.
     *
     * @return void
     */
    public function test_who_may_ask(): void {
        $this->resetAfterTest();
        $this->plant();
        $cm = get_coursemodule_from_instance('adele', $this->adele->id, $this->host->id, false, MUST_EXIST);
        $contextid = context_module::instance($cm->id)->id;

        foreach (['editingteacher', 'teacher'] as $role) {
            $user = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($user->id, $this->host->id, $role);
            $this->setUser($user);
            $result = get_node_course_access::execute($contextid);
            $this->assertSame(\mod_adele\local\teacher_access_mode::EXISTING_ONLY, $result['mode'], $role);
            $this->assertCount(4, $result['courses'], $role);
        }

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->host->id, 'student');
        $this->setUser($student);
        try {
            get_node_course_access::execute($contextid);
            $this->fail('A learner must not get the teacher answer.');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString('nopermissions', $e->errorcode);
        }

        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);
        try {
            get_node_course_access::execute($contextid);
            $this->fail('Somebody outside the course must not get an answer.');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->errorcode);
        }

        $this->setAdminUser();
        $this->expectException(\moodle_exception::class);
        get_node_course_access::execute(\context_course::instance($this->host->id)->id);
    }
}
