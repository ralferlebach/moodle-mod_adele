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
use enrol_adele\local\instance_manager;

/**
 * Node-course enrolments after the activity was saved (mod_adele #20).
 *
 * The activity is saved first; only afterwards do learners join node
 * courses. For sources 2 (a starting node) and 3 (any node) the join
 * subscribes them and enrols them into the host course - through
 * enrol_adele's host instance, by no other method. Leaving the last
 * qualifying node course suspends that host enrolment; leaving one of two
 * keeps it. Source 1 is unchanged: only members of the host course are
 * subscribed, and a node-course join carries nobody in.
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_adele_observer
 * @covers     \mod_adele\local\host_policy
 * @covers     \enrol_adele\observer
 * @covers     \enrol_adele\local\reconciler::reconcile_host_user
 */
final class node_enrolment_after_save_test extends advanced_testcase {
    /** @var \stdClass[] Courses: N1 (starting node), N2 (second node), H (host). */
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
        foreach (['N1', 'N2', 'H'] as $key) {
            $this->courses[$key] = $gen->create_course(['shortname' => "I20-$key"]);
        }
        $nodes = [
            ['id' => 'dndnode_1', 'type' => 'custom', 'parentCourse' => ['starting_node'],
                'data' => ['course_node_id' => [(int) $this->courses['N1']->id]]],
            ['id' => 'dndnode_2', 'type' => 'custom', 'parentCourse' => ['dndnode_1'],
                'data' => ['course_node_id' => [(int) $this->courses['N2']->id]]],
        ];
        $this->lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'Issue 20', 'description' => '', 'timecreated' => time(), 'timemodified' => time(),
            'createdby' => 2, 'json' => json_encode(['tree' => ['nodes' => $nodes, 'edges' => []]]),
        ]);
        $this->learner = $gen->create_user();
    }

    /**
     * Run the ad hoc tasks that are due now, the way cron does: unlike
     * runAdhocTasks(), a task scheduled for later (the deferred removal of
     * remove_user_path_adhoc) waits for its time.
     *
     * @return void
     */
    private function run_due_tasks(): void {
        global $DB;
        $lock = $this->createMock(\core\lock\lock::class);
        do {
            $now = \core\di::get(\core\clock::class)->time();
            $records = $DB->get_records_select('task_adhoc', 'nextruntime <= ?', [$now], 'id');
            foreach ($records as $record) {
                $task = \core\task\manager::adhoc_task_from_record($record);
                $task->set_lock($lock);
                $task->execute();
                \core\task\manager::adhoc_task_complete($task);
            }
        } while ($records);
    }

    /**
     * Save the activity in H with one participant source.
     *
     * @param int $source 1, 2 or 3.
     * @return void
     */
    private function save(int $source): void {
        $this->getDataGenerator()->create_module('adele', [
            'course' => $this->courses['H']->id, 'learningpathid' => $this->lpid,
            'participantslist' => [$source], 'hostenrolmentmode' => 'visible',
        ]);
        $this->run_due_tasks();
    }

    /**
     * Join a node course by an ordinary (manual) enrolment.
     *
     * @param string $key N1 or N2.
     * @return void
     */
    private function join(string $key): void {
        $this->getDataGenerator()->enrol_user($this->learner->id, $this->courses[$key]->id, 'student', 'manual');
        $this->run_due_tasks();
    }

    /**
     * Leave a node course.
     *
     * @param string $key N1 or N2.
     * @return void
     */
    private function leave(string $key): void {
        global $DB;
        $instance = $DB->get_record('enrol', ['courseid' => $this->courses[$key]->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->unenrol_user($instance, $this->learner->id);
        $this->run_due_tasks();
    }

    /**
     * Whether the learner is subscribed.
     *
     * @return bool
     */
    private function subscribed(): bool {
        global $DB;
        return $DB->record_exists('local_adele_path_user', [
            'user_id' => $this->learner->id, 'learning_path_id' => $this->lpid, 'status' => 'active',
        ]);
    }

    /**
     * The learner's enrolments in H: method/kind => status.
     *
     * @return array
     */
    private function host(): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT ue.id, e.enrol, e.customint2, ue.status FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :c AND ue.userid = :u",
            ['c' => $this->courses['H']->id, 'u' => $this->learner->id]
        );
        $out = [];
        foreach ($rows as $row) {
            $kind = $row->enrol === 'adele' ? ((int) $row->customint2 === instance_manager::KIND_HOST ? '/host' : '/other') : '';
            $out[$row->enrol . $kind] = (int) $row->status;
        }
        return $out;
    }

    /**
     * Whether the learner can enter H.
     *
     * @return bool
     */
    private function in_host(): bool {
        return is_enrolled(context_course::instance($this->courses['H']->id), $this->learner, '', true);
    }

    /**
     * Source 2: joining the starting node after the save subscribes and
     * enrols into H through enrol_adele's host instance; the second node alone
     * does not qualify.
     *
     * @return void
     */
    public function test_source_2_starting_node_after_save(): void {
        $this->save(2);
        $this->assertSame([], $this->host());

        $this->join('N2');
        $this->assertFalse($this->subscribed(), 'The second node is no starting node.');
        $this->assertSame([], $this->host());

        $this->join('N1');
        $this->assertTrue($this->subscribed(), 'Subscribed by the join after the save.');
        $this->assertSame(['adele/host' => ENROL_USER_ACTIVE], $this->host(), 'Only enrol_adele, no manual enrolment.');
        $this->assertTrue($this->in_host());
    }

    /**
     * Source 3: any node qualifies; leaving one of two keeps access, leaving
     * the last suspends the host enrolment (the same record, not deleted).
     *
     * @return void
     */
    public function test_source_3_any_node_last_membership_suspends(): void {
        global $DB;
        $this->save(3);
        $this->join('N2');
        $this->assertTrue($this->subscribed(), 'Any node qualifies.');
        $this->assertSame(['adele/host' => ENROL_USER_ACTIVE], $this->host());
        $ueid = (int) $DB->get_field_sql(
            "SELECT ue.id FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :c AND e.enrol = 'adele' AND ue.userid = :u",
            ['c' => $this->courses['H']->id, 'u' => $this->learner->id]
        );

        $this->join('N1');
        $this->leave('N2');
        $this->assertSame(['adele/host' => ENROL_USER_ACTIVE], $this->host(), 'N1 still qualifies.');
        $this->assertTrue($this->in_host());

        $this->leave('N1');
        $this->assertSame(['adele/host' => ENROL_USER_SUSPENDED], $this->host(), 'Last membership gone: suspended.');
        $this->assertFalse($this->in_host());
        $this->assertTrue(
            $DB->record_exists('user_enrolments', ['id' => $ueid, 'status' => ENROL_USER_SUSPENDED]),
            'The same enrolment, suspended, not replaced.'
        );

        $this->join('N2');
        $this->assertSame(['adele/host' => ENROL_USER_ACTIVE], $this->host(), 'Rejoining reactivates.');
        $this->assertTrue($DB->record_exists('user_enrolments', ['id' => $ueid, 'status' => ENROL_USER_ACTIVE]));
    }

    /**
     * The departure proves durable: once the grace period of
     * remove_user_path_adhoc is over, the suspended host enrolment is removed
     * together with the subscription (mod_adele #21); in the grace period it
     * stays suspended.
     *
     * @return void
     */
    public function test_durable_departure_removes_the_suspended_host_enrolment(): void {
        $clock = $this->mock_clock_with_frozen();
        $this->save(2);
        $this->join('N1');
        $this->assertSame(['adele/host' => ENROL_USER_ACTIVE], $this->host());

        $this->leave('N1');
        $this->assertSame(['adele/host' => ENROL_USER_SUSPENDED], $this->host(), 'Suspended at once.');
        $clock->bump(\enrol_adele\task\remove_user_path_adhoc::DELAY_SECONDS - 10);
        $this->run_due_tasks();
        $this->assertSame(['adele/host' => ENROL_USER_SUSPENDED], $this->host(), 'Still suspended in the grace period.');
        $this->assertTrue($this->subscribed(), 'The subscription waits, too.');

        $clock->bump(20);
        $this->run_due_tasks();
        $this->assertSame([], $this->host(), 'Removed after the grace period.');
        $this->assertFalse($this->subscribed());
    }

    /**
     * Source 1 unchanged: a node-course join carries nobody into H; a member
     * of H is subscribed and keeps their own enrolment only.
     *
     * @return void
     */
    public function test_source_1_unchanged(): void {
        $this->save(1);
        $this->join('N1');
        $this->assertFalse($this->subscribed(), 'A node course is no source 1.');
        $this->assertSame([], $this->host());

        $this->getDataGenerator()->enrol_user($this->learner->id, $this->courses['H']->id, 'student', 'manual');
        $this->run_due_tasks();
        $this->assertTrue($this->subscribed(), 'The host member is subscribed.');
        $this->assertSame(['manual' => ENROL_USER_ACTIVE], $this->host(), 'No ADELE host enrolment for source 1.');
    }
}
