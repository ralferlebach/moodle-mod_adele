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
use mod_adele\local\path_choice;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/adele/mod_form.php');

/**
 * The learning path of an activity, set by people without editing rights
 * on it (mod_adele #43).
 *
 * A teacher of the course who may edit the activity but not the embedded
 * path can save the activity (keeping the path); another path they may not
 * edit is refused, also as a forged form value; one they may edit is
 * allowed; a deleted path cannot be kept; a site administrator may choose
 * any path. Keeping the path grants no editing right on it.
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_adele\local\path_choice
 * @covers     \mod_adele_mod_form
 * @group      adele_release
 */
final class path_choice_test extends advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The teacher: edits the activity, not the path. */
    private \stdClass $teacher;

    /** @var int[] Paths: embedded (foreign), own (the teacher edits it), other (foreign). */
    private array $paths = [];

    /** @var \stdClass The activity. */
    private \stdClass $activity;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->teacher = $gen->create_user();
        $gen->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        foreach (['embedded', 'own', 'other'] as $key) {
            $this->paths[$key] = (int) $DB->insert_record('local_adele_learning_paths', (object) [
                'name' => "Path $key", 'description' => '', 'timecreated' => time(), 'timemodified' => time(),
                'createdby' => 2, 'json' => json_encode(['tree' => ['nodes' => [], 'edges' => []]]),
            ]);
        }
        $DB->insert_record('local_adele_lp_editors', (object) [
            'learningpathid' => $this->paths['own'], 'userid' => $this->teacher->id,
        ]);
        $this->setAdminUser();
        $this->activity = $gen->create_module('adele', [
            'course' => $this->course->id, 'name' => 'Embedded', 'learningpathid' => $this->paths['embedded'],
            'participantslist' => [1],
        ]);
    }

    /**
     * Submit the activity's own form with changes, as the current user.
     *
     * @param array $changes Field => value.
     * @return array [data or null, errors]
     */
    private function submit(array $changes): array {
        global $PAGE;
        // The form reads its course's sections through the page's course, as
        // on modedit.php ($PAGE sets $COURSE).
        if ((int) $PAGE->course->id !== (int) $this->course->id) {
            $PAGE->set_course(get_course($this->course->id));
        }
        $cm = get_coursemodule_from_id('adele', $this->activity->cmid, 0, false, MUST_EXIST);
        [$cm, , , $data, $cw] = get_moduleinfo_data($cm, $this->course);
        $values = array_merge((array) $data, ['participantslist' => [1], 'availabilityconditionsjson' => ''], $changes);
        \mod_adele_mod_form::mock_submit($values);
        $form = new \mod_adele_mod_form($data, $cw->section, $cm, $this->course);
        $result = $form->get_data();
        $errors = $result ? [] : $form->validation($values, []);
        return [$result, $errors];
    }

    /**
     * (a) Another setting changed, the path kept: saved, without editing
     * rights on the path - and still without them afterwards.
     *
     * @return void
     */
    public function test_a_teacher_keeps_the_path_and_saves(): void {
        global $DB;
        $this->setUser($this->teacher);
        $this->assertArrayNotHasKey($this->paths['embedded'], path_choice::editable());
        $this->assertArrayHasKey($this->paths['embedded'], path_choice::options($this->paths['embedded']), 'Offered to keep.');
        $this->assertSame(path_choice::OK, path_choice::check($this->paths['embedded'], $this->paths['embedded']));

        [$data, $errors] = $this->submit(['name' => 'Renamed', 'learningpathid' => $this->paths['embedded']]);
        $this->assertNotNull($data, 'The form validates: ' . json_encode($errors));
        $cm = get_coursemodule_from_id('adele', $this->activity->cmid, 0, false, MUST_EXIST);
        update_moduleinfo($cm, $data, $this->course);
        $this->assertSame('Renamed', $DB->get_field('adele', 'name', ['id' => $this->activity->id]));
        $this->assertSame($this->paths['embedded'], (int) $DB->get_field('adele', 'learningpathid', ['id' => $this->activity->id]));
        $this->assertFalse($DB->record_exists('local_adele_lp_editors', [
            'learningpathid' => $this->paths['embedded'], 'userid' => $this->teacher->id,
        ]), 'Keeping the path gives no editing right on it.');
    }

    /**
     * (b) Another path the teacher may not edit: refused, also when the
     * value is forged (not among the options shown).
     *
     * @return void
     */
    public function test_another_foreign_path_is_refused(): void {
        $this->setUser($this->teacher);
        $this->assertSame(path_choice::NOT_EDITABLE, path_choice::check($this->paths['other'], $this->paths['embedded']));
        $this->assertArrayNotHasKey($this->paths['other'], path_choice::options($this->paths['embedded']));
        [$data, $errors] = $this->submit(['learningpathid' => $this->paths['other']]);
        $this->assertNull($data);
        $this->assertArrayHasKey('learningpathid', $errors);
    }

    /**
     * (c) A path the teacher may edit: allowed.
     *
     * @return void
     */
    public function test_an_own_path_is_allowed(): void {
        $this->setUser($this->teacher);
        $this->assertSame(path_choice::OK, path_choice::check($this->paths['own'], $this->paths['embedded']));
        [$data, $errors] = $this->submit(['learningpathid' => $this->paths['own']]);
        $this->assertNotNull($data, json_encode($errors));
        $this->assertSame($this->paths['own'], (int) $data->learningpathid);
    }

    /**
     * (d) The embedded path was deleted: it cannot be kept, a new one must
     * be chosen; a made-up id is refused too.
     *
     * @return void
     */
    public function test_a_deleted_path_cannot_be_kept(): void {
        global $DB;
        $DB->delete_records('local_adele_learning_paths', ['id' => $this->paths['embedded']]);
        $this->setUser($this->teacher);
        $this->assertArrayNotHasKey($this->paths['embedded'], path_choice::options($this->paths['embedded']));
        $this->assertSame(path_choice::MISSING, path_choice::check($this->paths['embedded'], $this->paths['embedded']));
        $this->assertSame(path_choice::MISSING, path_choice::check(999999, $this->paths['embedded']));
        $this->assertSame(path_choice::REQUIRED, path_choice::check(0, $this->paths['embedded']));
        [$data] = $this->submit(['learningpathid' => $this->paths['embedded']]);
        $this->assertNull($data);
        [$data] = $this->submit(['learningpathid' => $this->paths['own']]);
        $this->assertNotNull($data, 'Repaired with a path of their own.');
    }

    /**
     * (e) A site administrator may choose any path.
     *
     * @return void
     */
    public function test_an_administrator_chooses_any_path(): void {
        $this->setAdminUser();
        $this->assertSame(path_choice::OK, path_choice::check($this->paths['other'], $this->paths['embedded']));
        [$data, $errors] = $this->submit(['learningpathid' => $this->paths['other']]);
        $this->assertNotNull($data, json_encode($errors));
    }
}
