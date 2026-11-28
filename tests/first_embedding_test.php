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

/**
 * The first and a further learning path in a course send no message
 * (mod_adele #11, "Message was not sent").
 *
 * The cause found in 2025 was Moodle's course welcome message of a manual
 * enrolment, sent by ADELE's former enrol_manual fallback and failing on a
 * site without a working message processor. That path is gone (#20); this
 * test keeps it gone: in a new course whose manual enrolment sends the
 * welcome message, creating the first and a second learning path activity -
 * which subscribe the course's members and enrol the members of the node
 * courses into the course - sends no message at all.
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_adele_observer::saved_module
 * @covers     ::adele_add_instance
 */
final class first_embedding_test extends advanced_testcase {
    /**
     * First and second embedding: no message, members carried.
     *
     * @return void
     */
    public function test_first_and_second_embedding_send_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $host = $gen->create_course();
        $node = $gen->create_course();
        foreach ([$host, $node] as $course) {
            $DB->set_field('enrol', 'customint1', ENROL_SEND_EMAIL_FROM_NOREPLY, ['courseid' => $course->id, 'enrol' => 'manual']);
        }
        $member = $gen->create_user();
        $nodelearner = $gen->create_user();
        $gen->enrol_user($member->id, $host->id, 'student', 'manual');
        $gen->enrol_user($nodelearner->id, $node->id, 'student', 'manual');
        $nodes = [['id' => 'dndnode_1', 'type' => 'custom', 'parentCourse' => ['starting_node'],
            'data' => ['course_node_id' => [(int) $node->id]]]];
        $paths = [];
        foreach (['First', 'Second'] as $name) {
            $paths[] = (int) $DB->insert_record('local_adele_learning_paths', (object) [
                'name' => $name, 'description' => '', 'timecreated' => time(), 'timemodified' => time(),
                'createdby' => 2, 'json' => json_encode(['tree' => ['nodes' => $nodes, 'edges' => []]]),
            ]);
        }

        $sink = $this->redirectMessages();
        foreach ($paths as $lpid) {
            $gen->create_module('adele', [
                'course' => $host->id, 'learningpathid' => $lpid, 'participantslist' => [1, 3], 'hostenrolmentmode' => 'visible',
            ]);
            $this->runAdhocTasks();
            $this->assertSame(0, $sink->count(), 'No message when embedding path ' . $lpid);
        }
        $sink->close();

        foreach ($paths as $lpid) {
            foreach ([$member, $nodelearner] as $person) {
                $this->assertTrue($DB->record_exists('local_adele_path_user', [
                    'user_id' => $person->id, 'learning_path_id' => $lpid,
                ]));
            }
        }
        $this->assertTrue(is_enrolled(\context_course::instance($host->id), $nodelearner, '', true));
        $this->assertSame(
            ['adele'],
            array_values(array_unique($DB->get_fieldset_sql(
                "SELECT e.enrol FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE e.courseid = ? AND ue.userid = ?",
                [$host->id, $nodelearner->id]
            ))),
            'Carried into the course by enrol_adele, not by a manual enrolment that would welcome them.'
        );
    }

    /**
     * Counter-check of the instrument: a manual enrolment in such a course
     * does send the welcome message - the sink would see one.
     *
     * @return void
     */
    public function test_the_sink_sees_a_manual_welcome(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $DB->set_field('enrol', 'customint1', ENROL_SEND_EMAIL_FROM_NOREPLY, ['courseid' => $course->id, 'enrol' => 'manual']);
        $user = $this->getDataGenerator()->create_user();
        $sink = $this->redirectMessages();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student', 'manual');
        $this->assertSame(1, $sink->count());
        $sink->close();
    }
}
