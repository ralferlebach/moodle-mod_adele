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
 * Only an effective enrolment of one's own is a participant source
 * (mod_adele #30).
 *
 * The path: node 1 = the starting courses N1 and N2 (a stack), node 2 = C.
 * Host course H embeds it twice-free: once with the source "starting node"
 * (host mode "automatic"). A second host course H1 embeds it with the source
 * "this course". With the real observers: a suspended enrolment, one that has
 * not started, one that has ended, one through a disabled method - none
 * subscribes, none carries host access. Reactivating subscribes and grants,
 * and doing it twice changes nothing more. Suspending again takes host access
 * back - unless a second active source carries it. The site setting lets
 * suspended enrolments count again.
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_adele\local\host_policy::has_foreign_enrolment
 * @covers     \mod_adele_observer::user_enrolment_updated
 * @covers     \local_adele\enrol_state::effective_enrolment_sql
 * @covers     \local_adele\enrol_state::get_effectively_enrolled_courseids
 */
final class effective_source_test extends advanced_testcase {
    /** @var \stdClass[] Courses by key. */
    private array $courses = [];

    /** @var int The learning path. */
    private int $lpid = 0;

    /** @var \stdClass The learner. */
    private \stdClass $learner;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        foreach (['N1', 'N2', 'C', 'H', 'H1'] as $key) {
            $this->courses[$key] = $gen->create_course(['shortname' => "ES-$key"]);
        }
        $nodes = [
            ['id' => 'dndnode_1', 'type' => 'orcourses', 'parentCourse' => ['starting_node'],
                'data' => ['course_node_id' => [(int) $this->courses['N1']->id, (int) $this->courses['N2']->id]]],
            ['id' => 'dndnode_2', 'type' => 'custom', 'parentCourse' => ['dndnode_1'],
                'data' => ['course_node_id' => [(int) $this->courses['C']->id]]],
        ];
        $this->lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'Effective source path', 'description' => '', 'timecreated' => time(), 'timemodified' => time(),
            'createdby' => 2, 'json' => json_encode(['tree' => ['nodes' => $nodes, 'edges' => []]]),
        ]);
        $gen->create_module('adele', [
            'course' => $this->courses['H']->id, 'learningpathid' => $this->lpid,
            'participantslist' => [2], 'hostenrolmentmode' => 'visible',
        ]);
        $gen->create_module('adele', [
            'course' => $this->courses['H1']->id, 'learningpathid' => $this->lpid,
            'participantslist' => [1],
        ]);
        $this->learner = $gen->create_user();
        $this->runAdhocTasks();
    }

    /**
     * Enrol the learner as a student through the course's manual method.
     *
     * @param string $key The course.
     * @param int $status ENROL_USER_ACTIVE or ENROL_USER_SUSPENDED.
     * @param int $timestart Start, 0 for none.
     * @param int $timeend End, 0 for none.
     */
    private function enrol(string $key, int $status, int $timestart = 0, int $timeend = 0): void {
        $this->getDataGenerator()->enrol_user(
            $this->learner->id,
            $this->courses[$key]->id,
            'student',
            'manual',
            $timestart,
            $timeend,
            $status
        );
        $this->runAdhocTasks();
    }

    /**
     * Change the status of the learner's manual enrolment, the way Moodle's
     * participants page does (user_enrolment_updated).
     *
     * @param string $key The course.
     * @param int $status The new status.
     */
    private function set_status(string $key, int $status): void {
        global $DB;
        $instance = $DB->get_record('enrol', ['courseid' => $this->courses[$key]->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->update_user_enrol($instance, $this->learner->id, $status);
        $this->runAdhocTasks();
    }

    /**
     * Whether the learner is subscribed to the path.
     *
     * @return int Number of active subscriptions.
     */
    private function subscriptions(): int {
        global $DB;
        return $DB->count_records('local_adele_path_user', [
            'user_id' => $this->learner->id, 'learning_path_id' => $this->lpid, 'status' => 'active',
        ]);
    }

    /**
     * Whether the learner can enter host course H through ADELE.
     *
     * @return bool
     */
    private function host_access(): bool {
        return is_enrolled(context_course::instance($this->courses['H']->id), $this->learner, '', true);
    }

    /**
     * The learner's ADELE enrolment records in H.
     *
     * @return array
     */
    private function host_records(): array {
        global $DB;
        return $DB->get_records_sql(
            "SELECT ue.id, ue.status FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :c AND e.enrol = 'adele' AND ue.userid = :u",
            ['c' => $this->courses['H']->id, 'u' => $this->learner->id]
        );
    }

    /**
     * Suspended: nothing. Reactivated: subscribed and host access - twice
     * changes nothing more. Suspended again: host access goes.
     */
    public function test_suspended_carries_nothing_reactivation_grants_idempotently(): void {
        $this->enrol('N1', ENROL_USER_SUSPENDED);
        $this->assertSame(0, $this->subscriptions(), 'A suspended enrolment subscribes nobody.');
        $this->assertFalse($this->host_access(), 'Nor does it carry host access.');

        $this->set_status('N1', ENROL_USER_ACTIVE);
        $this->assertSame(1, $this->subscriptions(), 'Reactivated: subscribed.');
        $this->assertTrue($this->host_access(), 'Reactivated: host access.');
        $records = $this->host_records();

        $this->set_status('N1', ENROL_USER_ACTIVE);
        $this->assertSame(1, $this->subscriptions(), 'Twice: still one subscription.');
        $this->assertSame(array_keys($records), array_keys($this->host_records()), 'Twice: the same host enrolment.');

        $this->set_status('N1', ENROL_USER_SUSPENDED);
        $this->assertFalse($this->host_access(), 'Suspended again: host access goes.');
    }

    /**
     * A second, active source keeps host access when the first is suspended.
     */
    public function test_a_second_active_source_keeps_access(): void {
        $this->enrol('N1', ENROL_USER_ACTIVE);
        $this->enrol('N2', ENROL_USER_ACTIVE);
        $this->assertTrue($this->host_access());
        $this->set_status('N1', ENROL_USER_SUSPENDED);
        $this->assertTrue($this->host_access(), 'N2 still carries it.');
        $this->set_status('N2', ENROL_USER_SUSPENDED);
        $this->assertFalse($this->host_access(), 'Neither carries it.');
    }

    /**
     * Not started, ended, a disabled method: none is a source.
     */
    public function test_outside_the_time_window_or_disabled_method_carries_nothing(): void {
        global $DB;
        $now = \core\di::get(\core\clock::class)->time();
        $this->enrol('N1', ENROL_USER_ACTIVE, $now + DAYSECS);
        $this->assertSame(0, $this->subscriptions(), 'Not started yet.');
        $this->assertFalse($this->host_access());

        $this->enrol('N2', ENROL_USER_ACTIVE, 0, $now - DAYSECS);
        $this->assertSame(0, $this->subscriptions(), 'Ended.');
        $this->assertFalse($this->host_access());

        $other = $this->getDataGenerator()->create_course();
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_DISABLED, ['courseid' => $this->courses['N1']->id, 'enrol' => 'manual']);
        $this->assertSame([], \local_adele\enrol_state::get_effectively_enrolled_courseids(
            (int) $this->learner->id,
            [(int) $this->courses['N1']->id, (int) $other->id]
        ), 'A disabled method is no source either.');
    }

    /**
     * Source "this course": the same rule - suspended in H1 subscribes
     * nobody, reactivated subscribes.
     */
    public function test_this_course_source_follows_the_same_rule(): void {
        $this->enrol('H1', ENROL_USER_SUSPENDED);
        $this->assertSame(0, $this->subscriptions());
        $this->set_status('H1', ENROL_USER_ACTIVE);
        $this->assertSame(1, $this->subscriptions());
    }

    /**
     * The site setting: suspended enrolments count again (as before #30).
     */
    public function test_the_site_may_let_suspended_enrolments_count(): void {
        set_config('sourcesincludesuspended', 1, 'mod_adele');
        $this->enrol('N1', ENROL_USER_SUSPENDED);
        $this->assertSame(1, $this->subscriptions(), 'With the setting, a suspended enrolment is a source.');
        $this->assertTrue($this->host_access());
    }
}
