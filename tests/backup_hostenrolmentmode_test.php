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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Backup, import and duplicate keep the host enrolment mode (mod_adele #41).
 *
 * The backup listed the fields of the table adele one by one and left out
 * hostenrolmentmode: a copy of an activity set to "no enrolment" or "hidden"
 * came back as "automatic" and enrolled the people the teacher had kept out.
 * An older backup, made before the field travelled, restores as "automatic" -
 * the column's default.
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_adele_activity_structure_step
 * @covers     \restore_adele_activity_structure_step
 * @group      adele_release
 */
final class backup_hostenrolmentmode_test extends advanced_testcase {
    /**
     * An activity with the given host enrolment mode in a new course.
     *
     * @param string $mode visible, hidden or none.
     * @return \stdClass The activity (with cmid and course).
     */
    private function activity(string $mode): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'Path', 'json' => '{"tree":{"nodes":[]}}', 'visibility' => 1, 'createdby' => 2,
        ]);
        return $this->getDataGenerator()->create_module('adele', [
            'course' => $course->id,
            'learningpathid' => $lpid,
            'participantslist' => [2],
            'hostenrolmentmode' => $mode,
        ]);
    }

    /**
     * The stored host enrolment mode of an instance.
     *
     * @param int $instanceid
     * @return string
     */
    private function stored(int $instanceid): string {
        global $DB;
        return (string) $DB->get_field('adele', 'hostenrolmentmode', ['id' => $instanceid]);
    }

    /**
     * A duplicate keeps "no enrolment" and "hidden".
     *
     * @return void
     */
    public function test_a_duplicate_keeps_the_mode(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        foreach (['none', 'hidden', 'visible'] as $mode) {
            $activity = $this->activity($mode);
            $cm = get_coursemodule_from_id('adele', $activity->cmid, 0, false, MUST_EXIST);
            $copy = duplicate_module(get_course($activity->course), $cm);
            $this->assertSame($mode, $this->stored((int) $copy->instance), $mode);
        }
    }

    /**
     * An import into another course keeps the mode; the same backup without
     * the field (as an older one was made) restores as "automatic".
     *
     * @return void
     */
    public function test_an_import_keeps_the_mode_an_older_backup_gives_the_default(): void {
        global $CFG, $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $activity = $this->activity('none');

        foreach ([false, true] as $older) {
            $bc = new \backup_controller(
                \backup::TYPE_1ACTIVITY,
                $activity->cmid,
                \backup::FORMAT_MOODLE,
                \backup::INTERACTIVE_NO,
                \backup::MODE_IMPORT,
                $USER->id
            );
            $backupid = $bc->get_backupid();
            $bc->execute_plan();
            $bc->destroy();

            if ($older) {
                $xml = $CFG->tempdir . '/backup/' . $backupid . '/activities/adele_' . $activity->cmid . '/adele.xml';
                $this->assertFileExists($xml);
                $content = file_get_contents($xml);
                $this->assertStringContainsString('<hostenrolmentmode>none</hostenrolmentmode>', $content);
                file_put_contents($xml, str_replace('<hostenrolmentmode>none</hostenrolmentmode>', '', $content));
            }

            $target = $this->getDataGenerator()->create_course();
            $rc = new \restore_controller(
                $backupid,
                $target->id,
                \backup::INTERACTIVE_NO,
                \backup::MODE_IMPORT,
                $USER->id,
                \backup::TARGET_EXISTING_ADDING
            );
            $rc->execute_precheck();
            $rc->execute_plan();
            $rc->destroy();

            $restored = $DB->get_record('adele', ['course' => $target->id], 'id', MUST_EXIST);
            $this->assertSame($older ? 'visible' : 'none', $this->stored((int) $restored->id), $older ? 'older backup' : 'import');
        }
    }

    /**
     * Restored with "none" into another course, beside an existing embedding
     * of the same path there with "none" too: a learner whose node-course
     * membership qualifies gets no host enrolment - no ADELE method appears
     * in the target course.
     *
     * @return void
     */
    public function test_none_restored_beside_an_existing_embedding_enrols_nobody(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $node = $this->getDataGenerator()->create_course();
        $nodes = [['id' => 'dndnode_1', 'type' => 'custom', 'parentCourse' => ['starting_node'],
            'data' => ['course_node_id' => [(int) $node->id]]]];
        $lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'P',
            'json' => json_encode(['tree' => ['nodes' => $nodes, 'edges' => []]]),
            'visibility' => 1,
            'createdby' => 2,
        ]);
        $source = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('adele', [
            'course' => $source->id, 'learningpathid' => $lpid, 'participantslist' => [2], 'hostenrolmentmode' => 'none',
        ]);
        $target = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('adele', [
            'course' => $target->id, 'learningpathid' => $lpid, 'participantslist' => [2], 'hostenrolmentmode' => 'none',
        ]);
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $node->id, 'student');
        $this->runAdhocTasks();

        $bc = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            $activity->cmid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();
        ob_start();
        $rc = new \restore_controller(
            $backupid,
            $target->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id,
            \backup::TARGET_EXISTING_ADDING
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();
        $this->runAdhocTasks();
        ob_end_clean();

        $this->assertSame(2, $DB->count_records('adele', ['course' => $target->id]), 'Two embeddings now.');
        $this->assertFalse($DB->record_exists('enrol', ['courseid' => $target->id, 'enrol' => 'adele']), 'No ADELE method.');
        $this->assertFalse(is_enrolled(\context_course::instance($target->id), $learner), 'Nobody enrolled.');
        $this->assertTrue(
            $DB->record_exists('local_adele_path_user', ['user_id' => $learner->id, 'learning_path_id' => $lpid]),
            'The subscription is the path\'s, not the host course\'s.'
        );
    }
}
