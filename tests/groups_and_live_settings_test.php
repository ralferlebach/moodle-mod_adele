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

use mod_adele\local\context_contract;
use mod_adele\local\live_settings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Groups of the activity (local_adele #16) and its live-marker settings (local_adele #17).
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_adele\local\context_contract
 * @covers     \mod_adele\local\live_settings
 * @covers     ::adele_supports
 * @covers     ::adele_normalise_live_settings
 * @covers     \backup_adele_activity_structure_step
 * @covers     \restore_adele_activity_structure_step
 */
final class groups_and_live_settings_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;
    /** @var int */
    private $lpid;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'Path', 'json' => '{"tree":{"nodes":[]}}', 'visibility' => 1, 'createdby' => 2,
        ]);
    }

    /**
     * An activity.
     *
     * @param array $fields
     * @return \stdClass
     */
    private function activity(array $fields = []): \stdClass {
        return $this->getDataGenerator()->create_module('adele', $fields + [
            'course' => $this->course->id,
            'learningpathid' => $this->lpid,
            'participantslist' => '0',
        ]);
    }

    /**
     * The activity supports group mode and groupings (AK0-M1).
     */
    public function test_supports_groups(): void {
        $this->assertTrue(adele_supports(FEATURE_GROUPS));
        $this->assertTrue(adele_supports(FEATURE_GROUPINGS));
        $this->assertTrue(plugin_supports('mod', 'adele', FEATURE_GROUPS, false));
    }

    /**
     * The contract per mode and role; the course's forced group mode wins (AK0-M2, AK0-M5).
     */
    public function test_contract_matrix(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $ga = $gen->create_group(['courseid' => $this->course->id, 'name' => 'A']);
        $gb = $gen->create_group(['courseid' => $this->course->id, 'name' => 'B']);
        $learner = $gen->create_and_enrol($this->course, 'student');
        $gen->create_group_member(['groupid' => $ga->id, 'userid' => $learner->id]);
        $tutor = $gen->create_and_enrol($this->course, 'teacher');
        $gen->create_group_member(['groupid' => $gb->id, 'userid' => $tutor->id]);
        $editor = $gen->create_and_enrol($this->course, 'editingteacher');
        $nogroup = $gen->create_and_enrol($this->course, 'student');

        $none = $this->activity(['groupmode' => NOGROUPS]);
        $separate = $this->activity(['groupmode' => SEPARATEGROUPS]);
        $visible = $this->activity(['groupmode' => VISIBLEGROUPS]);

        $c = context_contract::for_cm((int) $none->cmid, (int) $learner->id);
        $this->assertSame([NOGROUPS, 0, 'student', []], [$c['groupmode'], $c['groupid'], $c['view'], $c['groups']]);

        $c = context_contract::for_cm((int) $separate->cmid, (int) $learner->id);
        $this->assertSame([(int) $ga->id], $c['owngroups']);
        $this->assertSame([(int) $ga->id], array_column($c['groups'], 'id'));
        $this->assertSame((int) $ga->id, $c['groupid']);

        $c = context_contract::for_cm((int) $separate->cmid, (int) $tutor->id);
        $this->assertSame(['teacher', true], [$c['view'], $c['readonly']]);
        $this->assertFalse($c['accessallgroups']);
        $this->assertSame([(int) $gb->id], array_column($c['groups'], 'id'));
        $this->assertSame((int) $gb->id, $c['groupid']);

        $c = context_contract::for_cm((int) $visible->cmid, (int) $tutor->id);
        $this->assertSame(['A', 'B'], array_column($c['groups'], 'name'), 'visible groups: every group may be chosen');
        $this->assertSame(0, $c['groupid']);

        $c = context_contract::for_cm((int) $separate->cmid, (int) $editor->id, 0);
        $this->assertTrue($c['accessallgroups']);
        $this->assertSame(0, $c['groupid']);

        $c = context_contract::for_cm((int) $separate->cmid, (int) $nogroup->id);
        $this->assertSame(-1, $c['groupid'], 'a learner in no group sees no group');

        // The course forces separate groups on an activity set to none.
        $DB->update_record('course', (object) ['id' => $this->course->id, 'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        rebuild_course_cache($this->course->id, true);
        $this->assertSame(SEPARATEGROUPS, context_contract::for_cm((int) $none->cmid, (int) $learner->id)['groupmode']);
    }

    /**
     * A group the person may not see is refused - also a group of another course (AK0-M2, AK0-M4).
     */
    public function test_foreign_groups_are_refused(): void {
        $gen = $this->getDataGenerator();
        $ga = $gen->create_group(['courseid' => $this->course->id]);
        $gb = $gen->create_group(['courseid' => $this->course->id]);
        $other = $gen->create_group(['courseid' => $gen->create_course()->id]);
        $tutor = $gen->create_and_enrol($this->course, 'teacher');
        $gen->create_group_member(['groupid' => $ga->id, 'userid' => $tutor->id]);
        $learner = $gen->create_and_enrol($this->course, 'student');
        $gen->create_group_member(['groupid' => $ga->id, 'userid' => $learner->id]);
        $separate = $this->activity(['groupmode' => SEPARATEGROUPS]);
        $none = $this->activity(['groupmode' => NOGROUPS]);
        $cases = [
            [$separate, $tutor, (int) $gb->id], [$separate, $tutor, 0], [$separate, $tutor, (int) $other->id],
            [$separate, $learner, (int) $gb->id], [$separate, $learner, 0], [$none, $learner, (int) $ga->id],
        ];
        foreach ($cases as [$activity, $user, $group]) {
            try {
                context_contract::for_cm((int) $activity->cmid, (int) $user->id, $group);
                $this->fail("group {$group} must be refused");
            } catch (\invalid_parameter_exception $e) {
                $this->assertStringContainsString('group', $e->getMessage());
            }
        }
    }

    /**
     * A grouping restricts the groups.
     */
    public function test_grouping(): void {
        $gen = $this->getDataGenerator();
        $ga = $gen->create_group(['courseid' => $this->course->id, 'name' => 'A']);
        $gb = $gen->create_group(['courseid' => $this->course->id, 'name' => 'B']);
        $grouping = $gen->create_grouping(['courseid' => $this->course->id]);
        $gen->create_grouping_group(['groupingid' => $grouping->id, 'groupid' => $gb->id]);
        $editor = $gen->create_and_enrol($this->course, 'editingteacher');
        $activity = $this->activity(['groupmode' => VISIBLEGROUPS, 'groupingid' => $grouping->id]);
        $c = context_contract::for_cm((int) $activity->cmid, (int) $editor->id);
        $this->assertSame((int) $grouping->id, $c['groupingid']);
        $this->assertSame(['B'], array_column($c['groups'], 'name'));
        $this->expectException(\invalid_parameter_exception::class);
        context_contract::for_cm((int) $activity->cmid, (int) $editor->id, (int) $ga->id);
    }

    /**
     * Defaults off / 5 minutes; the window 1-60, whole minutes; stored normalised (AK2-M1, DoD2-M1).
     */
    public function test_live_settings_defaults_and_bounds(): void {
        global $DB;
        $default = $this->activity();
        $record = $DB->get_record('adele', ['id' => $default->id]);
        $this->assertSame([0, 5], [(int) $record->livemarkers, (int) $record->livewindow]);
        $this->assertSame(['enabled' => false, 'window' => 300], live_settings::for_instance($record));

        foreach (
            ['1' => true, '60' => true, '5' => true, '0' => false, '61' => false, '-3' => false,
                '2.5' => false, 'abc' => false, '' => false] as $value => $valid
        ) {
            $this->assertSame($valid, live_settings::is_valid_window((string) $value), "window '{$value}'");
        }
        $on = $this->activity(['livemarkers' => 1, 'livewindow' => 99]);
        $record = $DB->get_record('adele', ['id' => $on->id]);
        $this->assertSame([1, 60], [(int) $record->livemarkers, (int) $record->livewindow], 'brought into range');

        $contract = context_contract::for_cm((int) $on->cmid, (int) get_admin()->id);
        $this->assertSame(['enabled' => true, 'window' => 3600], $contract['live']);
    }

    /**
     * The form refuses a window outside 1-60 (AK2-M1).
     */
    public function test_form_validation(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/adele/mod_form.php');
        $activity = $this->activity();
        [$cm, $context, $module, $data, $cw] = get_moduleinfo_data(
            get_coursemodule_from_id('adele', $activity->cmid),
            $this->course
        );
        global $PAGE;
        $PAGE->set_course($this->course);
        $form = new \mod_adele_mod_form($data, $cw->section, $cm, $this->course);
        $base = ['availabilityconditionsjson' => '', 'participantslist' => ['0'], 'learningpathid' => $this->lpid] + (array) $data;
        $errors = $form->validation(['livemarkers' => 1, 'livewindow' => '0'] + $base, []);
        $this->assertArrayHasKey('livewindow', $errors);
        $errors = $form->validation(['livemarkers' => 1, 'livewindow' => '61'] + $base, []);
        $this->assertArrayHasKey('livewindow', $errors);
        $errors = $form->validation(['livemarkers' => 1, 'livewindow' => '7'] + $base, []);
        $this->assertArrayNotHasKey('livewindow', $errors);
    }

    /**
     * The form of an activity of the test course.
     *
     * @param \stdClass $activity
     * @return \MoodleQuickForm
     */
    private function form_of(\stdClass $activity): \MoodleQuickForm {
        global $CFG, $DB, $PAGE;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/adele/mod_form.php');
        // From the database: get_course() would hand back the global $COURSE of
        // the previous form, with the group mode settings of before.
        $course = $DB->get_record('course', ['id' => $this->course->id], '*', MUST_EXIST);
        [$cm, $context, $module, $data, $cw] = get_moduleinfo_data(get_coursemodule_from_id('adele', $activity->cmid), $course);
        // A fresh page per form: the course (and with it $COURSE) can be set once only.
        $PAGE = new \moodle_page();
        $PAGE->set_course($course);
        $form = new \mod_adele_mod_form($data, $cw->section, $cm, $course);
        $form->set_data($data);
        return (new \ReflectionProperty(\moodleform::class, '_form'))->getValue($form);
    }

    /**
     * "Participant results" (userlist) applies without groups only (decision
     * 2026-11-22; #16 AK0-M6, re-audit 2026-11-22): the field hides with a
     * group mode, it is gone when the course forces one, it is there when the
     * course forces "no groups"; the stored value survives every change of
     * the group mode and takes effect again without groups.
     */
    public function test_participant_results_field_follows_the_group_mode(): void {
        global $COURSE, $DB;
        $activity = $this->activity(['userlist' => 2]);
        $learner = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        // Group mode chosen per activity: the field is there, hidden while a group mode is chosen.
        $mform = $this->form_of($activity);
        $this->assertSame('select', $mform->getElement('userlist')->getType());
        $hideifs = (new \ReflectionProperty(\MoodleQuickForm::class, '_hideifs'))->getValue($mform);
        $this->assertContains('userlist', $hideifs['groupmode']['neq'][NOGROUPS] ?? [], 'hidden unless "No groups"');
        $this->assertEquals([2], $mform->getElement('userlist')->getValue());

        // The course forces separate groups: no field, the stored value is kept as it is.
        $DB->update_record('course', (object) ['id' => $this->course->id, 'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        rebuild_course_cache($this->course->id, true);
        $mform = $this->form_of($activity);
        $this->assertSame('hidden', $mform->getElement('userlist')->getType());
        $this->assertEquals(2, $mform->getElement('userlist')->getValue());

        // The course forces "no groups": the field is there and not hidden.
        $DB->update_record('course', (object) ['id' => $this->course->id, 'groupmode' => NOGROUPS, 'groupmodeforce' => 1]);
        rebuild_course_cache($this->course->id, true);
        $mform = $this->form_of($activity);
        $this->assertSame('select', $mform->getElement('userlist')->getType());
        $hideifs = (new \ReflectionProperty(\MoodleQuickForm::class, '_hideifs'))->getValue($mform);
        $this->assertArrayNotHasKey('groupmode', $hideifs);

        // Separate groups and back to none: the stored value is untouched and in effect again.
        $DB->update_record('course', (object) ['id' => $this->course->id, 'groupmode' => NOGROUPS, 'groupmodeforce' => 0]);
        // The forms above left the course of "forced" in $COURSE, which core's
        // group functions reuse; a request starts with the course as stored.
        $COURSE = $DB->get_record('course', ['id' => $this->course->id]);
        set_coursemodule_groupmode($activity->cmid, SEPARATEGROUPS);
        rebuild_course_cache($this->course->id, true);
        $contract = \mod_adele\local\context_contract::for_cm((int) $activity->cmid, (int) $learner->id);
        $this->assertSame(2, (int) $contract['userlist'], 'stored value kept');
        $this->assertFalse(\local_adele\cohort::own_results_only($contract), 'with groups: not in effect');
        set_coursemodule_groupmode($activity->cmid, NOGROUPS);
        rebuild_course_cache($this->course->id, true);
        $contract = \mod_adele\local\context_contract::for_cm((int) $activity->cmid, (int) $learner->id);
        $this->assertTrue(\local_adele\cohort::own_results_only($contract), 'without groups: in effect again');
    }

    /**
     * Duplicate and import keep the settings and the group mode; an older backup gets the defaults (AK2-M3, AK0-M6).
     */
    public function test_backup_restore_duplicate(): void {
        global $CFG, $DB, $USER;
        $gen = $this->getDataGenerator();
        $grouping = $gen->create_grouping(['courseid' => $this->course->id]);
        $activity = $this->activity(['livemarkers' => 1, 'livewindow' => 12, 'groupmode' => VISIBLEGROUPS,
            'groupingid' => $grouping->id, 'userlist' => 2]);

        $copy = duplicate_module($this->course, get_coursemodule_from_id('adele', $activity->cmid));
        $record = $DB->get_record('adele', ['id' => $copy->instance]);
        $this->assertSame([1, 12], [(int) $record->livemarkers, (int) $record->livewindow]);
        $this->assertSame(VISIBLEGROUPS, (int) get_coursemodule_from_id('adele', $copy->id)->groupmode);
        $this->assertSame(2, (int) $record->userlist, 'the stored "participant results" travels along (AK0-M6)');

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
                $content = file_get_contents($xml);
                $this->assertStringContainsString('<livewindow>12</livewindow>', $content);
                file_put_contents($xml, str_replace(['<livemarkers>1</livemarkers>', '<livewindow>12</livewindow>'], '', $content));
            }
            $target = $gen->create_course();
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
            $restored = $DB->get_record('adele', ['course' => $target->id], '*', MUST_EXIST);
            $this->assertSame(2, (int) $restored->userlist, 'the stored "participant results" travels along (AK0-M6)');
            $restoredcm = get_coursemodule_from_instance('adele', $restored->id, $target->id);
            $this->assertSame(VISIBLEGROUPS, (int) $restoredcm->groupmode, 'and the group mode');
            $this->assertSame(
                $older ? [0, 5] : [1, 12],
                [(int) $restored->livemarkers, (int) $restored->livewindow],
                $older ? 'older backup: defaults' : 'import keeps the values'
            );
        }
    }

    /**
     * The upgrade adds the fields with their defaults (AK2-M2).
     */
    public function test_schema_defaults(): void {
        global $DB;
        $columns = $DB->get_columns('adele');
        $this->assertSame('0', (string) $columns['livemarkers']->default_value);
        $this->assertSame('5', (string) $columns['livewindow']->default_value);
        $this->assertTrue((bool) $columns['livewindow']->not_null);
    }

    /**
     * An installation from before the live settings (#17 AK2-M2, DoD2-M1
     * "Altinstallationen"): the upgrade adds both fields to the existing
     * activity with the documented defaults, leaves its other settings alone,
     * and a second run keeps a value already there.
     */
    public function test_upgrade_of_an_old_installation(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/adele/db/upgrade.php');
        $activity = $this->activity(['userlist' => 1, 'livemarkers' => 1, 'livewindow' => 30]);
        $before = $DB->get_record('adele', ['id' => $activity->id]);
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('adele');
        $fields = [
            new \xmldb_field('livemarkers', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'teachercourseaccessgrantor'),
            new \xmldb_field('livewindow', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '5', 'livemarkers'),
        ];
        // The schema of 2026101800: without the two fields.
        foreach (array_reverse($fields) as $field) {
            $dbman->drop_field($table, $field);
        }
        // ... and the version that went with it.
        set_config('version', 2026101800, 'mod_adele');
        try {
            $this->assertFalse($dbman->field_exists($table, $fields[0]));
            $this->assertTrue(xmldb_adele_upgrade(2026101800));
            $this->assertSame('2026111900', get_config('mod_adele', 'version'), 'the savepoint of the step was reached');
        } finally {
            // Whatever happened, the schema is complete again for the tests after this one.
            foreach ($fields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }
        $after = $DB->get_record('adele', ['id' => $activity->id]);
        $this->assertSame(['0', '5'], [(string) $after->livemarkers, (string) $after->livewindow], 'the documented defaults');
        foreach (['name', 'learningpathid', 'userlist', 'participantslist', 'view'] as $kept) {
            $this->assertEquals($before->{$kept}, $after->{$kept}, "{$kept} is left alone");
        }
        // A second run (an interrupted upgrade run again) keeps what is there.
        $DB->set_field('adele', 'livewindow', 12, ['id' => $activity->id]);
        set_config('version', 2026101800, 'mod_adele');
        $this->assertTrue(xmldb_adele_upgrade(2026101800));
        $this->assertSame('12', (string) $DB->get_field('adele', 'livewindow', ['id' => $activity->id]));
    }
}
