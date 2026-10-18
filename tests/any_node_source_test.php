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
use enrol_adele\local\instance_manager;

/**
 * Participant source 3, "any course of the path", applied when the activity
 * is saved (mod_adele #19).
 *
 * Everybody already enrolled in any node course - starting node or not - is
 * subscribed once and enrolled into the host course once, however many node
 * courses they are in. Somebody in no node course is left alone. Sources 1
 * and 2 keep their behaviour.
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_adele_observer::saved_module
 * @covers     \mod_adele_observer::enroll_any_nodes_participants
 * @covers     \mod_adele_observer::enroll_starting_nodes_participants
 * @covers     \mod_adele_observer::enroll_all_participants
 */
final class any_node_source_test extends advanced_testcase {
    /** @var \stdClass[] Courses: N1 (starting node), N2, N3 (later nodes), H (host). */
    private array $courses = [];

    /** @var int The learning path. */
    private int $lpid = 0;

    /** @var \stdClass[] People: early (N1), later (N2), both (N2 and N3), host (H), none. */
    private array $people = [];

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        foreach (['N1', 'N2', 'N3', 'H'] as $key) {
            $this->courses[$key] = $gen->create_course(['shortname' => "I19-$key"]);
        }
        $node = fn(string $id, string $course, array $parents) => [
            'id' => $id, 'type' => 'custom', 'parentCourse' => $parents,
            'data' => ['course_node_id' => [(int) $this->courses[$course]->id]],
        ];
        $nodes = [
            $node('dndnode_1', 'N1', ['starting_node']),
            $node('dndnode_2', 'N2', ['dndnode_1']),
            $node('dndnode_3', 'N3', ['dndnode_2']),
        ];
        $this->lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'Issue 19', 'description' => '', 'timecreated' => time(), 'timemodified' => time(),
            'createdby' => 2, 'json' => json_encode(['tree' => ['nodes' => $nodes, 'edges' => []]]),
        ]);
        $memberships = ['early' => ['N1'], 'later' => ['N2'], 'both' => ['N2', 'N3'], 'host' => ['H'], 'none' => []];
        foreach ($memberships as $key => $courses) {
            $this->people[$key] = $gen->create_user(['username' => "i19$key"]);
            foreach ($courses as $course) {
                $gen->enrol_user($this->people[$key]->id, $this->courses[$course]->id, 'student');
            }
        }
        $this->runAdhocTasks();
    }

    /**
     * Save the activity in H with the given sources; run what the save queues.
     *
     * @param int[] $sources
     * @return void
     */
    private function save(array $sources): void {
        $this->getDataGenerator()->create_module('adele', [
            'course' => $this->courses['H']->id, 'learningpathid' => $this->lpid,
            'participantslist' => $sources, 'hostenrolmentmode' => 'visible',
        ]);
        $this->runAdhocTasks();
    }

    /**
     * Subscriptions per person, e.g. ['early' => 1, ...].
     *
     * @return int[]
     */
    private function subscriptions(): array {
        global $DB;
        $out = [];
        foreach ($this->people as $key => $person) {
            $out[$key] = $DB->count_records('local_adele_path_user', [
                'user_id' => $person->id, 'learning_path_id' => $this->lpid,
            ]);
        }
        return $out;
    }

    /**
     * ADELE host enrolments in H per person.
     *
     * @return int[]
     */
    private function host_enrolments(): array {
        global $DB;
        $out = [];
        foreach ($this->people as $key => $person) {
            $out[$key] = $DB->count_records_sql(
                "SELECT COUNT(1) FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE e.courseid = :c AND e.enrol = 'adele' AND e.customint2 = :kind
                        AND ue.userid = :u AND ue.status = :active",
                ['c' => $this->courses['H']->id, 'kind' => instance_manager::KIND_HOST,
                    'u' => $person->id, 'active' => ENROL_USER_ACTIVE]
            );
        }
        return $out;
    }

    /**
     * Source 3 on save: every node course counts, each person once; nobody
     * outside a node course; the host member (source 1 not chosen) neither.
     *
     * @return void
     */
    public function test_source_3_on_save(): void {
        $this->save([3]);
        $this->assertSame(['early' => 1, 'later' => 1, 'both' => 1, 'host' => 0, 'none' => 0], $this->subscriptions());
        $this->assertSame(['early' => 1, 'later' => 1, 'both' => 1, 'host' => 0, 'none' => 0], $this->host_enrolments());
    }

    /**
     * Saving again changes nothing: still one subscription, one enrolment.
     *
     * @return void
     */
    public function test_saving_twice_is_idempotent(): void {
        global $DB;
        $this->save([3]);
        $cm = get_coursemodule_from_instance(
            'adele',
            (int) $DB->get_field('adele', 'id', ['course' => $this->courses['H']->id]),
            $this->courses['H']->id
        );
        \core\event\course_module_updated::create_from_cm($cm)->trigger();
        $this->runAdhocTasks();
        $this->assertSame(['early' => 1, 'later' => 1, 'both' => 1, 'host' => 0, 'none' => 0], $this->subscriptions());
        $this->assertSame(['early' => 1, 'later' => 1, 'both' => 1, 'host' => 0, 'none' => 0], $this->host_enrolments());
    }

    /**
     * Source 2 unchanged: only the starting node.
     *
     * @return void
     */
    public function test_source_2_unchanged(): void {
        $this->save([2]);
        $this->assertSame(['early' => 1, 'later' => 0, 'both' => 0, 'host' => 0, 'none' => 0], $this->subscriptions());
        $this->assertSame(['early' => 1, 'later' => 0, 'both' => 0, 'host' => 0, 'none' => 0], $this->host_enrolments());
    }

    /**
     * Source 1 unchanged: only the host members, and no ADELE host
     * enrolment for them (they are members already).
     *
     * @return void
     */
    public function test_source_1_unchanged(): void {
        $this->save([1]);
        $this->assertSame(['early' => 0, 'later' => 0, 'both' => 0, 'host' => 1, 'none' => 0], $this->subscriptions());
        $this->assertSame(['early' => 0, 'later' => 0, 'both' => 0, 'host' => 0, 'none' => 0], $this->host_enrolments());
    }

    /**
     * All three together: everybody in a course of the path or the host
     * course, each once; the person in none of them stays out.
     *
     * @return void
     */
    public function test_all_sources_together(): void {
        $this->save([1, 2, 3]);
        $this->assertSame(['early' => 1, 'later' => 1, 'both' => 1, 'host' => 1, 'none' => 0], $this->subscriptions());
        $this->assertSame(['early' => 1, 'later' => 1, 'both' => 1, 'host' => 0, 'none' => 0], $this->host_enrolments());
    }
}
