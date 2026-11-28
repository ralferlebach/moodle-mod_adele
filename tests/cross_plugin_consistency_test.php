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
use mod_adele\local\host_policy;

/**
 * One definition of "a participant of one's own" in all three plugins
 * (re-audit 0735, TP-08; mod_adele #30, #37; local_adele #587, #593).
 *
 * The question "does this person take part in this course by their own
 * right?" is asked by mod_adele (participant sources, host_policy), by
 * enrol_adele (whether a subscription is still carried, observer) and by
 * local_adele (enrol_state). For every kind of enrolment the three answers
 * are the same, and the expected one: an active, current enrolment through
 * an enabled method of somebody holding a graded role counts; suspended
 * (unless the site lets it), expired, not yet started, through a disabled
 * method, made by ADELE itself, or with a role outside the graded roles does
 * not. Role assignment and active enrolment are separate criteria.
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_adele\enrol_state
 * @covers     \mod_adele\local\host_policy
 * @covers     \enrol_adele\observer
 * @group      adele_release
 */
final class cross_plugin_consistency_test extends advanced_testcase {
    /**
     * The three answers for one person in one course.
     *
     * @param int $userid
     * @param int $courseid
     * @return array ['mod' => bool, 'enrol' => bool, 'local' => bool]
     */
    private function answers(int $userid, int $courseid): array {
        $enrol = new \ReflectionMethod(\enrol_adele\observer::class, 'has_foreign_enrolment');
        $enrol->setAccessible(true);
        return [
            'mod' => host_policy::has_foreign_enrolment($userid, [$courseid]),
            'enrol' => (bool) $enrol->invoke(null, $userid, [$courseid]),
            'local' => \local_adele\enrol_state::is_effective_participant($courseid, $userid),
        ];
    }

    /**
     * The kinds of enrolment and the expected answer.
     *
     * @return array
     */
    public static function kinds(): array {
        return [
            'active student' => ['active', true],
            'suspended' => ['suspended', false],
            'suspended, the site lets it count' => ['suspended_counts', true],
            'expired' => ['expired', false],
            'not yet started' => ['future', false],
            'disabled method' => ['disabled', false],
            'only ADELE\'s own enrolment' => ['adele', false],
            'teacher (not a graded role)' => ['teacher', false],
            'teacher, teacher made a graded role' => ['teacher_graded', true],
            'enrolled without any role' => ['norole', false],
        ];
    }

    /**
     * Each kind: the same answer in mod, enrol and local - the expected one.
     *
     * @dataProvider kinds
     * @param string $kind
     * @param bool $expected
     * @return void
     */
    public function test_the_same_answer_everywhere(string $kind, bool $expected): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $user = $gen->create_user();
        $now = \core\di::get(\core\clock::class)->time();
        $student = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        $teacher = (int) $DB->get_field('role', 'id', ['shortname' => 'teacher']);
        $manual = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $plugin = enrol_get_plugin('manual');
        switch ($kind) {
            case 'active':
                $plugin->enrol_user($manual, $user->id, $student);
                break;
            case 'suspended':
            case 'suspended_counts':
                $plugin->enrol_user($manual, $user->id, $student, 0, 0, ENROL_USER_SUSPENDED);
                if ($kind === 'suspended_counts') {
                    set_config('sourcesincludesuspended', 1, 'mod_adele');
                }
                break;
            case 'expired':
                $plugin->enrol_user($manual, $user->id, $student, $now - 7200, $now - 3600);
                break;
            case 'future':
                $plugin->enrol_user($manual, $user->id, $student, $now + 3600);
                break;
            case 'disabled':
                $plugin->enrol_user($manual, $user->id, $student);
                $plugin->update_status($manual, ENROL_INSTANCE_DISABLED);
                break;
            case 'adele':
                $lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
                    'name' => 'P', 'description' => '', 'timecreated' => $now, 'timemodified' => $now, 'createdby' => 2,
                    'json' => json_encode(['tree' => ['nodes' => [], 'edges' => []]]),
                ]);
                $instance = instance_manager::ensure_instance($lpid, (int) $course->id, instance_manager::KIND_HOST);
                enrol_get_plugin('adele')->enrol_user($instance, $user->id, $student);
                $this->assertTrue(is_enrolled(\context_course::instance($course->id), $user, '', true), 'Precondition: enrolled.');
                break;
            case 'teacher':
            case 'teacher_graded':
                $plugin->enrol_user($manual, $user->id, $teacher);
                if ($kind === 'teacher_graded') {
                    $CFG->gradebookroles = $CFG->gradebookroles . ',' . $teacher;
                }
                break;
            case 'norole':
                $plugin->enrol_user($manual, $user->id, 0);
                break;
        }
        $this->assertSame(
            ['mod' => $expected, 'enrol' => $expected, 'local' => $expected],
            $this->answers((int) $user->id, (int) $course->id)
        );
    }

    /**
     * Role assignment and enrolment are separate: a graded role assigned in
     * the course without an enrolment, or one through enrol_adele beside an
     * enrolment of one's own with another role, counts nowhere.
     *
     * @return void
     */
    public function test_role_and_enrolment_are_separate_criteria(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $context = \context_course::instance($course->id);
        $student = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        $teacher = (int) $DB->get_field('role', 'id', ['shortname' => 'teacher']);

        $roleonly = $gen->create_user();
        role_assign($student, $roleonly->id, $context->id);
        $nowhere = ['mod' => false, 'enrol' => false, 'local' => false];
        $this->assertSame($nowhere, $this->answers((int) $roleonly->id, (int) $course->id));

        $mixed = $gen->create_user();
        $gen->enrol_user($mixed->id, $course->id, 'teacher');
        $lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'P', 'description' => '', 'timecreated' => time(), 'timemodified' => time(), 'createdby' => 2,
            'json' => json_encode(['tree' => ['nodes' => [], 'edges' => []]]),
        ]);
        $instance = instance_manager::ensure_instance($lpid, (int) $course->id, instance_manager::KIND_HOST);
        enrol_get_plugin('adele')->enrol_user($instance, $mixed->id, $student);
        $this->assertTrue(user_has_role_assignment($mixed->id, $student, $context->id), 'Holds the student role - through ADELE.');
        $this->assertSame($nowhere, $this->answers((int) $mixed->id, (int) $course->id));
        $this->assertNotContains($teacher, \local_adele\enrol_state::participant_roleids());
    }
}
