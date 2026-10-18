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
use context_module;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Backup and restore of mod_adele, complete and safe (mod_adele #29, #41;
 * master issue #43, P0.2/P0.3).
 *
 * Every field of the activity and its intro files come back. The learning
 * path is a site-wide record of local_adele: restored on the same site while
 * it exists, the activity keeps it; restored from another site, or after the
 * path was deleted, the activity comes back without a path (never pointing at
 * an unrelated path by its number), and the restore log says so. ADELE's
 * enrolments are never copied from a backup - with user data or without -
 * and the learner path records are not in it: what exists afterwards is
 * derived from the target site.
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_adele_activity_structure_step
 * @covers     \restore_adele_activity_structure_step
 * @covers     \enrol_adele_plugin::restore_instance
 * @group      adele_release
 */
final class restore_policy_test extends advanced_testcase {
    /** @var \stdClass The source course. */
    private \stdClass $course;

    /** @var \stdClass The node course of the path. */
    private \stdClass $node;

    /** @var int The learning path. */
    private int $lpid = 0;

    /** @var \stdClass The activity. */
    private \stdClass $activity;

    /** @var \stdClass A learner with host access through the activity. */
    private \stdClass $learner;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->node = $gen->create_course();
        $nodes = [['id' => 'dndnode_1', 'type' => 'custom', 'parentCourse' => ['starting_node'],
            'data' => ['course_node_id' => [(int) $this->node->id]]]];
        $this->lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'Restore path', 'description' => '', 'timecreated' => time(), 'timemodified' => time(),
            'createdby' => 2, 'json' => json_encode(['tree' => ['nodes' => $nodes, 'edges' => []]]),
        ]);
        set_config('teachercourseaccessmodes', 'request', 'mod_adele');
        $this->activity = $gen->create_module('adele', [
            'course' => $this->course->id,
            'name' => 'Restore me',
            'intro' => '<p>Intro</p>',
            'learningpathid' => $this->lpid,
            'participantslist' => [2],
            'hostenrolmentmode' => 'hidden',
            'view' => 2,
            'userlist' => 2,
            'completionlearningpathfinished' => 1,
            'teachercourseaccessmode' => 'request',
        ]);
        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => context_module::instance($this->activity->cmid)->id, 'component' => 'mod_adele',
            'filearea' => 'intro', 'itemid' => 0, 'filepath' => '/', 'filename' => 'intro.txt',
        ], 'intro file');
        $this->learner = $gen->create_user();
        $gen->enrol_user($this->learner->id, $this->node->id, 'student');
        $this->runAdhocTasks();
    }

    /**
     * Back up the source course (with or without users) and restore it into
     * a new course; optionally pretend the backup comes from another site.
     *
     * @param bool $users With user data.
     * @param bool $othersite Change the site identifier of the backup.
     * @param bool $samecourse Restore into the source course itself (adding).
     * @return \stdClass The restored adele record.
     */
    private function roundtrip(bool $users, bool $othersite = false, bool $samecourse = false): \stdClass {
        global $CFG, $DB, $USER;
        // The restore reports on the console (calendar refresh); not test output.
        ob_start();
        $mode = $users ? \backup::MODE_GENERAL : \backup::MODE_IMPORT;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $this->course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            $mode,
            $USER->id
        );
        if ($users) {
            $bc->get_plan()->get_setting('users')->set_value(true);
        }
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        if ($users) {
            // A general backup is a file: unpack it the way a restore does.
            $file = $bc->get_results()['backup_destination'];
            $backupid = 'adele_' . $backupid;
            $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $CFG->tempdir . '/backup/' . $backupid);
        }
        $bc->destroy();
        if ($othersite) {
            $xml = $CFG->tempdir . '/backup/' . $backupid . '/moodle_backup.xml';
            $content = preg_replace(
                '~<original_site_identifier_hash>[^<]*</original_site_identifier_hash>~',
                '<original_site_identifier_hash>' . md5('another site') . '</original_site_identifier_hash>',
                file_get_contents($xml)
            );
            file_put_contents($xml, $content);
        }
        $target = $samecourse ? $this->course : $this->getDataGenerator()->create_course();
        $rc = new \restore_controller(
            $backupid,
            $target->id,
            \backup::INTERACTIVE_NO,
            $mode,
            $USER->id,
            \backup::TARGET_EXISTING_ADDING
        );
        if ($users) {
            $rc->get_plan()->get_setting('users')->set_value(true);
        }
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();
        $this->runAdhocTasks();
        ob_end_clean();
        return $DB->get_record_sql(
            "SELECT * FROM {adele} WHERE course = :c ORDER BY id DESC",
            ['c' => $target->id],
            IGNORE_MULTIPLE
        );
    }

    /**
     * Every field and the intro file come back; the path on this site stays.
     */
    public function test_every_field_and_the_intro_files_come_back(): void {
        global $DB;
        $before = $DB->get_record('adele', ['id' => $this->activity->id], '*', MUST_EXIST);
        $after = $this->roundtrip(false);
        foreach (array_keys((array) $before) as $field) {
            if (in_array($field, ['id', 'course', 'timemodified'], true)) {
                continue;
            }
            $this->assertEquals($before->$field, $after->$field, $field);
        }
        $cm = get_coursemodule_from_instance('adele', $after->id, $after->course, false, MUST_EXIST);
        $files = get_file_storage()->get_area_files(context_module::instance($cm->id)->id, 'mod_adele', 'intro', 0, '', false);
        $this->assertSame(['intro.txt'], array_values(array_map(fn($f) => $f->get_filename(), $files)), 'The intro file.');
    }

    /**
     * From another site: no path, a warning in the log, nobody subscribed or
     * enrolled through it; the page says a path is to be chosen.
     */
    public function test_from_another_site_the_activity_has_no_path(): void {
        global $DB;
        $before = $DB->count_records('local_adele_path_user', ['learning_path_id' => $this->lpid]);
        $after = $this->roundtrip(false, true);
        $this->assertSame(0, (int) $after->learningpathid, 'No numeric link to an unrelated path.');
        $this->assertSame($before, $DB->count_records('local_adele_path_user', ['learning_path_id' => $this->lpid]));
        $this->assertFalse($DB->record_exists('enrol', ['courseid' => $after->course, 'enrol' => 'adele']));
    }

    /**
     * On this site, but the path was deleted in the meantime: no path either.
     */
    public function test_a_deleted_path_is_not_restored(): void {
        global $DB;
        $DB->delete_records('local_adele_learning_paths', ['id' => $this->lpid]);
        $this->assertSame(0, (int) $this->roundtrip(false)->learningpathid);
    }

    /**
     * With user data: ADELE's enrolments of the source course are not copied,
     * and no learner path record comes from the backup. What the restored
     * activity has is derived from this site: the learner's own node-course
     * membership carries them into the new course - hidden, as the restored
     * mode says - and nobody else.
     */
    public function test_enrolments_are_derived_not_copied(): void {
        global $DB;
        $outsider = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($outsider->id, $this->course->id, 'student');
        $paths = $DB->count_records('local_adele_path_user');

        $after = $this->roundtrip(true);
        $target = context_course::instance($after->course);
        $this->assertSame('hidden', $after->hostenrolmentmode);
        $adele = $DB->get_records_sql(
            "SELECT ue.userid, ue.status FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :c AND e.enrol = 'adele'",
            ['c' => $after->course]
        );
        $this->assertSame([(int) $this->learner->id], array_map('intval', array_keys($adele)), 'Only who qualifies here.');
        $this->assertSame(ENROL_USER_SUSPENDED, (int) reset($adele)->status, 'Hidden, as the restored mode says.');
        $this->assertFalse(is_enrolled($target, $this->learner, '', true), 'No access in "hidden".');
        $this->assertSame($paths, $DB->count_records('local_adele_path_user'), 'No learner path record copied or added.');
    }

    /**
     * Into the same course (adding): a second activity with the same path;
     * the host index knows the course once, the learner keeps exactly one
     * host enrolment, their subscription is not duplicated.
     */
    public function test_into_the_same_course(): void {
        global $DB;
        $after = $this->roundtrip(false, false, true);
        $this->assertNotEquals((int) $this->activity->id, (int) $after->id, 'A second activity.');
        $this->assertSame($this->lpid, (int) $after->learningpathid);
        $this->assertSame(2, $DB->count_records('adele', ['course' => $this->course->id]));
        $embedded = \local_adele\enrol_state::get_learningpaths_embedded_in_course((int) $this->course->id);
        $this->assertSame([$this->lpid], array_map('intval', array_values($embedded)));
        $this->assertSame(1, $DB->count_records_sql(
            "SELECT COUNT(1) FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :c AND e.enrol = 'adele' AND ue.userid = :u",
            ['c' => $this->course->id, 'u' => $this->learner->id]
        ));
        $this->assertSame(1, $DB->count_records('local_adele_path_user', [
            'user_id' => $this->learner->id, 'learning_path_id' => $this->lpid,
        ]));
    }

    /**
     * Into another course: the host index names the new course for the path.
     */
    public function test_the_host_index_knows_the_new_course(): void {
        $after = $this->roundtrip(false);
        $this->assertContains(
            $this->lpid,
            array_map('intval', \local_adele\enrol_state::get_learningpaths_embedded_in_course((int) $after->course))
        );
    }

    /**
     * Restore with user data for each host enrolment mode (re-audit TP-06,
     * #22, #29): the learner qualifying on this site is enrolled in the new
     * course as the restored mode says - active (visible), suspended
     * (hidden), not at all (none).
     */
    public function test_each_mode_restored_with_users_gives_its_enrolments(): void {
        global $DB;
        foreach (['visible' => ENROL_USER_ACTIVE, 'hidden' => ENROL_USER_SUSPENDED, 'none' => null] as $mode => $status) {
            $DB->set_field('adele', 'hostenrolmentmode', $mode, ['id' => $this->activity->id]);
            $after = $this->roundtrip(true);
            $this->assertSame($mode, $after->hostenrolmentmode);
            $adele = $DB->get_records_sql(
                "SELECT ue.userid, ue.status FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE e.courseid = :c AND e.enrol = 'adele'",
                ['c' => $after->course]
            );
            if ($status === null) {
                $this->assertSame([], $adele, 'none: nobody enrolled.');
            } else {
                $this->assertSame([(int) $this->learner->id], array_map('intval', array_keys($adele)), $mode);
                $this->assertSame($status, (int) reset($adele)->status, $mode);
            }
        }
    }

    /**
     * The grantor of a teacher mode is only kept where the restore maps the
     * person (re-audit TP-06): from another site the stored user id would be
     * a stranger - nobody, so the mode gives no enrolment (not authorised).
     */
    public function test_a_grantor_from_another_site_has_no_authority(): void {
        global $DB;
        $stranger = $this->getDataGenerator()->create_user();
        $DB->set_field('adele', 'teachercourseaccessgrantor', $stranger->id, ['id' => $this->activity->id]);
        $after = $this->roundtrip(false, true);
        $this->assertSame(0, (int) $after->teachercourseaccessgrantor, 'No user id carried over without a mapping.');
        $this->assertSame(0, (int) $after->learningpathid);
    }
}
