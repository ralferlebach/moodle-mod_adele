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
use mod_adele\local\host_policy;

/**
 * Competing source-2/3 embeddings of one path in one host course
 * (mod_adele #23): one decision per (path, host course), the most generous
 * one, whatever order the embeddings come in.
 *
 * Entitled if any embedding entitles; the mode is the most generous among
 * the entitling embeddings (visible > hidden > none). Embeddings in
 * different host courses are decided separately; a single embedding
 * behaves as before.
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_adele\local\host_policy::get_affected_pairs
 * @covers     \mod_adele_observer
 */
final class host_priority_order_test extends advanced_testcase {
    /** @var \stdClass[] Courses: A (starting node), B (later node), H1, H2 (hosts). */
    private array $courses = [];

    /** @var int The learning path. */
    private int $lpid = 0;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        foreach (['A', 'B', 'H1', 'H2'] as $key) {
            $this->courses[$key] = $this->getDataGenerator()->create_course(['shortname' => "I23-$key"]);
        }
        $nodes = [
            ['id' => 'dndnode_1', 'type' => 'custom', 'parentCourse' => ['starting_node'],
                'data' => ['course_node_id' => [(int) $this->courses['A']->id]]],
            ['id' => 'dndnode_2', 'type' => 'custom', 'parentCourse' => ['dndnode_1'],
                'data' => ['course_node_id' => [(int) $this->courses['B']->id]]],
        ];
        $this->lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'Issue 23', 'description' => '', 'timecreated' => time(), 'timemodified' => time(),
            'createdby' => 2, 'json' => json_encode(['tree' => ['nodes' => $nodes, 'edges' => []]]),
        ]);
    }

    /**
     * Insert embeddings in the given order.
     *
     * @param array $embeddings [host key, sources, mode] each.
     * @return void
     */
    private function embed(array $embeddings): void {
        global $DB;
        foreach ($embeddings as [$host, $sources, $mode]) {
            $DB->insert_record('adele', (object) [
                'course' => $this->courses[$host]->id, 'name' => "$sources $mode", 'intro' => '', 'introformat' => 1,
                'learningpathid' => $this->lpid, 'participantslist' => $sources, 'hostenrolmentmode' => $mode,
                'userlist' => 1, 'view' => 1, 'timecreated' => time(), 'timemodified' => time(),
            ]);
        }
    }

    /**
     * The decisions for a user who just joined course B, by host key.
     *
     * @param int $userid
     * @return array host key => [entitled, mode]
     */
    private function decisions(int $userid): array {
        $keys = array_flip(array_map(fn($c) => (int) $c->id, $this->courses));
        $out = [];
        foreach (host_policy::get_affected_pairs((int) $this->courses['B']->id, $userid) as $pair) {
            $this->assertSame($this->lpid, (int) $pair['learningpathid']);
            $host = $keys[(int) $pair['hostcourseid']];
            $this->assertArrayNotHasKey($host, $out, 'One decision per (path, host course).');
            $out[$host] = [(bool) $pair['entitled'], $pair['mode']];
        }
        ksort($out);
        return $out;
    }

    /**
     * Cases: embeddings (in insertion order) and the expected decision for a
     * user who is only in the later node course B.
     *
     * @return array
     */
    public static function cases(): array {
        $narrow = ['H1', '2', 'visible'];
        $generous = ['H1', '3', 'hidden'];
        $both = ['H1', '3', 'visible'];
        return [
            'source 2 (no) then 3 (hidden)' => [[$narrow, $generous], ['H1' => [true, 'hidden']]],
            'reversed' => [[$generous, $narrow], ['H1' => [true, 'hidden']]],
            'hidden and visible, both entitling' => [[$generous, $both], ['H1' => [true, 'visible']]],
            'reversed: visible and hidden' => [[$both, $generous], ['H1' => [true, 'visible']]],
            'none and visible' => [[['H1', '3', 'none'], $both], ['H1' => [true, 'visible']]],
            'reversed: visible and none' => [[$both, ['H1', '3', 'none']], ['H1' => [true, 'visible']]],
            'single embedding, entitled' => [[$both], ['H1' => [true, 'visible']]],
            'single embedding, not entitled' => [[$narrow], ['H1' => [false, 'visible']]],
            'two host courses, decided apart' => [
                [$narrow, ['H2', '3', 'hidden']],
                ['H1' => [false, 'visible'], 'H2' => [true, 'hidden']],
            ],
        ];
    }

    /**
     * The decision does not depend on the order; one per pair.
     *
     * @dataProvider cases
     * @param array $embeddings
     * @param array $expected
     * @return void
     */
    public function test_decision(array $embeddings, array $expected): void {
        $this->embed($embeddings);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->courses['B']->id, 'student');
        $this->assertSame($expected, $this->decisions((int) $user->id));
    }

    /**
     * The live path applies it: joining B with "2 (visible)" and "3
     * (hidden)" in H1, in both orders, ends hidden; a repeated join and
     * leave cycle in B gives the same result each time.
     *
     * @return void
     */
    public function test_live_join_and_cycle(): void {
        global $DB;
        foreach ([[['H1', '2', 'visible'], ['H1', '3', 'hidden']], [['H1', '3', 'hidden'], ['H1', '2', 'visible']]] as $order) {
            $DB->delete_records('adele');
            $this->embed($order);
            $user = $this->getDataGenerator()->create_user();
            for ($round = 1; $round <= 2; $round++) {
                $this->getDataGenerator()->enrol_user($user->id, $this->courses['B']->id, 'student');
                $status = $DB->get_field_sql(
                    "SELECT ue.status FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
                      WHERE e.courseid = :c AND e.enrol = 'adele' AND ue.userid = :u",
                    ['c' => $this->courses['H1']->id, 'u' => $user->id]
                );
                $this->assertSame(ENROL_USER_SUSPENDED, (int) $status, "round $round: hidden wins over not entitled");
                $instance = $DB->get_record('enrol', ['courseid' => $this->courses['B']->id, 'enrol' => 'manual'], '*', MUST_EXIST);
                enrol_get_plugin('manual')->unenrol_user($instance, $user->id);
            }
        }
    }
}
