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
 * Removed participants do not stay in the participant list (enrol_adele #7).
 *
 * Several people join and leave a node course again and again; once the
 * grace period of each departure is over, nobody who left is listed in the
 * host course any more - not as active, not as suspended - and nobody gets
 * a record twice. In the grace period the person is listed as suspended
 * (mod_adele #20). Deleting the embedding takes its enrolments with it.
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \enrol_adele\observer
 * @covers     \enrol_adele\task\remove_user_path_adhoc
 * @covers     \mod_adele_observer
 */
final class participant_list_cycles_test extends advanced_testcase {
    /** @var \stdClass The node course. */
    private \stdClass $node;

    /** @var \stdClass The host course. */
    private \stdClass $host;

    /** @var \stdClass[] The people. */
    private array $people = [];

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $this->node = $gen->create_course();
        $this->host = $gen->create_course();
        $nodes = [['id' => 'dndnode_1', 'type' => 'custom', 'parentCourse' => ['starting_node'],
            'data' => ['course_node_id' => [(int) $this->node->id]]]];
        $lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'enrol 7', 'description' => '', 'timecreated' => time(), 'timemodified' => time(),
            'createdby' => 2, 'json' => json_encode(['tree' => ['nodes' => $nodes, 'edges' => []]]),
        ]);
        $gen->create_module('adele', [
            'course' => $this->host->id, 'learningpathid' => $lpid, 'participantslist' => [3],
            'hostenrolmentmode' => 'visible',
        ]);
        for ($i = 1; $i <= 3; $i++) {
            $this->people[] = $gen->create_user();
        }
    }

    /**
     * Run the ad hoc tasks that are due now (a deferred one waits).
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
     * Who the host course's participant list shows, with status (all
     * enrolments, active or not, as the participants page lists them).
     *
     * @return array userid => 'active'|'suspended'
     */
    private function listed(): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT ue.id, ue.userid, ue.status FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :c",
            ['c' => $this->host->id]
        );
        $out = [];
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey((int) $row->userid, $out, 'One record per person.');
            $out[(int) $row->userid] = (int) $row->status === ENROL_USER_ACTIVE ? 'active' : 'suspended';
        }
        ksort($out);
        return $out;
    }

    /**
     * Three rounds of joining and leaving: after each grace period the list
     * is empty again.
     *
     * @return void
     */
    public function test_repeated_cycles_leave_nobody_behind(): void {
        global $DB;
        $clock = $this->mock_clock_with_frozen();
        $manual = $DB->get_record('enrol', ['courseid' => $this->node->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $ids = array_map(fn($p) => (int) $p->id, $this->people);
        sort($ids);
        for ($round = 1; $round <= 3; $round++) {
            foreach ($this->people as $person) {
                $this->getDataGenerator()->enrol_user($person->id, $this->node->id, 'student', 'manual');
            }
            $this->run_due_tasks();
            $this->assertSame(array_fill_keys($ids, 'active'), $this->listed(), "round $round: all in");

            foreach ($this->people as $person) {
                enrol_get_plugin('manual')->unenrol_user($manual, $person->id);
            }
            $this->run_due_tasks();
            $this->assertSame(array_fill_keys($ids, 'suspended'), $this->listed(), "round $round: in the grace period");
            foreach ($this->people as $person) {
                $this->assertFalse(is_enrolled(context_course::instance($this->host->id), $person, '', true));
            }

            $clock->bump(\enrol_adele\task\remove_user_path_adhoc::DELAY_SECONDS + 1);
            $this->run_due_tasks();
            $this->assertSame([], $this->listed(), "round $round: nobody left behind");
        }
    }

    /**
     * Deleting the embedding takes its enrolments with it.
     *
     * @return void
     */
    public function test_deleting_the_embedding_takes_its_enrolments(): void {
        global $DB;
        foreach ($this->people as $person) {
            $this->getDataGenerator()->enrol_user($person->id, $this->node->id, 'student', 'manual');
        }
        $this->runAdhocTasks();
        $this->assertCount(3, $this->listed());

        course_delete_module((int) $DB->get_field('course_modules', 'id', ['course' => $this->host->id]));
        $this->runAdhocTasks();
        $this->assertSame([], $this->listed(), 'Nobody listed after the embedding is gone.');
        $this->assertFalse($DB->record_exists('enrol', ['courseid' => $this->host->id, 'enrol' => 'adele']));
    }
}
