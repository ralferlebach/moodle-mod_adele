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
use context_course;

/**
 * An enrolment ADELE made for one path is no source for another path that
 * shares the course (mod_adele #37) - when subscribing and when deciding,
 * on leaving, whether anything still carries the person (A-4).
 *
 * Path A: node 1 = N. Path B: node 1 = N (starting node), node 2 = N2.
 * A is embedded in HA with the source "this course"; B in HB with the
 * sources "this course", "starting node" and "any course of the path".
 * A learner of HA is subscribed to A, and enrol_adele enrols them into N -
 * an enrolment of ADELE's own. That enrolment must not subscribe them to B,
 * nor carry them into HB, neither live nor when B's activity is saved or the
 * host access is reconciled. Leaving HB, they are not carried by it either.
 * Control: an enrolment of their own in N is a source for B.
 *
 * Decision (documented in docs/pflichtenheft.md, 2026-10-17): enrolments
 * ADELE created do not count as a participant source, for any of the three
 * sources and for the A-4 rule alike; the one definition is
 * local_adele\enrol_state::effective_enrolment_sql().
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_adele\local\host_policy
 * @covers     \mod_adele_observer
 * @covers     \enrol_adele\observer::is_user_carried
 */
final class adele_source_shared_node_test extends advanced_testcase {
    /** @var \stdClass[] Courses by key. */
    private array $courses = [];

    /** @var int[] Paths by key. */
    private array $paths = [];

    /** @var \stdClass[] Activities by key. */
    private array $activities = [];

    /** @var \stdClass The learner. */
    private \stdClass $learner;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        foreach (['N', 'N2', 'HA', 'HB'] as $key) {
            $this->courses[$key] = $gen->create_course(['shortname' => "SN-$key"]);
        }
        $node = fn(string $id, array $courses, array $parents) => [
            'id' => $id, 'type' => 'custom', 'parentCourse' => $parents,
            'data' => ['course_node_id' => array_map(fn($k) => (int) $this->courses[$k]->id, $courses)],
        ];
        $trees = [
            'A' => [$node('dndnode_1', ['N'], ['starting_node'])],
            'B' => [$node('dndnode_1', ['N'], ['starting_node']), $node('dndnode_2', ['N2'], ['dndnode_1'])],
        ];
        foreach ($trees as $key => $nodes) {
            $this->paths[$key] = (int) $DB->insert_record('local_adele_learning_paths', (object) [
                'name' => "Shared node $key", 'description' => '', 'timecreated' => time(), 'timemodified' => time(),
                'createdby' => 2, 'json' => json_encode(['tree' => ['nodes' => $nodes, 'edges' => []]]),
            ]);
        }
        $this->activities['A'] = $gen->create_module('adele', [
            'course' => $this->courses['HA']->id, 'learningpathid' => $this->paths['A'], 'participantslist' => [1],
        ]);
        $this->activities['B'] = $gen->create_module('adele', [
            'course' => $this->courses['HB']->id, 'learningpathid' => $this->paths['B'],
            'participantslist' => [1, 2, 3], 'hostenrolmentmode' => 'visible',
        ]);
        $this->learner = $gen->create_user();
        $gen->enrol_user($this->learner->id, $this->courses['HA']->id, 'student');
        $this->runAdhocTasks();
        // Path A's node opens: enrol_adele enrols into N through its target
        // instance for A - the way its reconciler does when the node becomes
        // accessible (fired events included).
        $instance = \enrol_adele\local\instance_manager::ensure_instance($this->paths['A'], (int) $this->courses['N']->id);
        enrol_get_plugin('adele')->enrol_user(
            $instance,
            $this->learner->id,
            \enrol_adele\local\instance_manager::get_role_id()
        );
        $this->runAdhocTasks();
    }

    /**
     * Whether the learner is subscribed to a path.
     *
     * @param string $key A or B.
     * @return bool
     */
    private function on(string $key): bool {
        global $DB;
        return $DB->record_exists('local_adele_path_user', [
            'user_id' => $this->learner->id, 'learning_path_id' => $this->paths[$key], 'status' => 'active',
        ]);
    }

    /**
     * The learner's enrolment methods in a course, e.g. ['adele' => active].
     *
     * @param string $key The course.
     * @return array Method => status.
     */
    private function methods(string $key): array {
        global $DB;
        return $DB->get_records_sql_menu(
            "SELECT e.enrol, ue.status FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :c AND ue.userid = :u",
            ['c' => $this->courses[$key]->id, 'u' => $this->learner->id]
        );
    }

    /**
     * Whether the learner can enter HB.
     *
     * @return bool
     */
    private function in_hb(): bool {
        return is_enrolled(context_course::instance($this->courses['HB']->id), $this->learner, '', true);
    }

    /**
     * The precondition, then: no path B, no HB - live, on saving B, after
     * reconciling HB.
     */
    public function test_an_adele_enrolment_in_the_shared_course_carries_nothing_onto_path_b(): void {
        global $DB;
        $this->assertTrue($this->on('A'), 'Precondition: on path A through HA.');
        $this->assertSame(
            ['adele' => (string) ENROL_USER_ACTIVE],
            array_map('strval', $this->methods('N')),
            'Precondition: in N only through ADELE.'
        );

        $this->assertFalse($this->on('B'), 'Live: not on path B.');
        $this->assertFalse($this->in_hb(), 'Live: not in HB.');

        $record = $DB->get_record('adele', ['id' => $this->activities['B']->id], '*', MUST_EXIST);
        foreach (['enroll_all_participants', 'enroll_starting_nodes_participants', 'enroll_any_nodes_participants'] as $sweep) {
            \mod_adele_observer::$sweep($record, (object) ['courseid' => $this->courses['HB']->id, 'userid' => get_admin()->id]);
        }
        $this->runAdhocTasks();
        $this->assertFalse($this->on('B'), 'Saving B: not on path B.');
        $this->assertFalse($this->in_hb(), 'Saving B: not in HB.');

        \enrol_adele\local\reconciler::reconcile_host_embedding($this->paths['B'], (int) $this->courses['HB']->id);
        $this->assertFalse($this->in_hb(), 'Reconciled: not in HB.');
    }

    /**
     * A-4: on path B through HB, the learner leaves HB - the ADELE enrolment
     * in N (a starting node of B) does not carry them; their own does.
     */
    public function test_leaving_uses_the_same_rule(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $gen->enrol_user($this->learner->id, $this->courses['HB']->id, 'student');
        $this->runAdhocTasks();
        $this->assertTrue($this->on('B'), 'On path B through HB.');
        $this->assertTrue(
            \enrol_adele\observer::is_user_carried($this->paths['B'], (int) $this->learner->id),
            'Carried while in HB.'
        );

        $manual = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $this->courses['HB']->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $manual->unenrol_user($instance, $this->learner->id);
        $this->assertFalse(
            \enrol_adele\observer::is_user_carried($this->paths['B'], (int) $this->learner->id),
            'Only the ADELE enrolment in N is left: it carries nothing.'
        );
        $this->assertFalse($this->in_hb());

        $gen->enrol_user($this->learner->id, $this->courses['N']->id, 'student', 'manual');
        $this->assertTrue(
            \enrol_adele\observer::is_user_carried($this->paths['B'], (int) $this->learner->id),
            'Control: an enrolment of one\'s own in N carries.'
        );
    }

    /**
     * Control: an enrolment of one's own in the shared course is a source
     * for path B and carries into HB.
     */
    public function test_an_own_enrolment_in_the_shared_course_is_a_source(): void {
        $this->getDataGenerator()->enrol_user($this->learner->id, $this->courses['N']->id, 'student', 'manual');
        $this->runAdhocTasks();
        $this->assertTrue($this->on('B'), 'On path B through the own enrolment in N.');
        $this->assertTrue($this->in_hb(), 'And in HB.');
    }

    /**
     * A-4 for sources 2/3: on path B only through an own enrolment in N (a
     * starting node), the learner leaves N - nothing of their own carries
     * them any more (the ADELE enrolment in N from path A does not): host
     * access goes at once, the subscription after the grace period. Path A,
     * carried by HA, stays.
     */
    public function test_leaving_the_node_course_of_source_2_leaves_path_b(): void {
        global $DB;
        $clock = $this->mock_clock_with_frozen();
        $this->getDataGenerator()->enrol_user($this->learner->id, $this->courses['N']->id, 'student', 'manual');
        $this->runAdhocTasks();
        $this->assertTrue($this->on('B'), 'On path B through the own enrolment in N.');
        $this->assertTrue($this->in_hb());

        $instance = $DB->get_record('enrol', ['courseid' => $this->courses['N']->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->unenrol_user($instance, $this->learner->id);
        $this->assertFalse($this->in_hb(), 'Host access goes at once.');

        $clock->bump(\enrol_adele\task\remove_user_path_adhoc::DELAY_SECONDS + 1);
        $this->runAdhocTasks();
        $this->assertFalse($this->on('B'), 'After the grace period: off path B.');
        $this->assertTrue($this->on('A'), 'Path A, carried by HA, stays.');
    }
}
