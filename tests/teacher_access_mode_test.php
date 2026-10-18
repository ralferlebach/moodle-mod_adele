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
use context_module;
use mod_adele\external\get_node_course_access;
use mod_adele\local\teacher_access_mode as mode;

/**
 * The setting teachercourseaccessmode (issue local_adele #600, TP3).
 *
 * The site allows modes and names a default; an activity stores its own
 * mode; what acts is the stored mode while the site allows it, otherwise
 * existing_only. A mode the site does not allow cannot be saved - neither by
 * the form nor by any other path through adele_update_instance() - but a
 * stored one is not changed silently either. The mode travels with a
 * duplicate (backup and restore).
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_adele\local\teacher_access_mode
 * @covers     ::adele_add_instance
 * @covers     ::adele_update_instance
 * @covers     \restore_adele_activity_structure_step
 */
final class teacher_access_mode_test extends advanced_testcase {
    /**
     * The site's choice of modes.
     *
     * @param string[] $modes Ticked modes.
     * @param string $default The default for new activities.
     */
    private function site(array $modes, string $default = mode::EXISTING_ONLY): void {
        set_config('teachercourseaccessmodes', implode(',', $modes), 'mod_adele');
        set_config('teachercourseaccessdefault', $default, 'mod_adele');
    }

    /**
     * A new activity in a new course.
     *
     * @param array $extra Further fields, e.g. teachercourseaccessmode.
     * @return \stdClass The activity (with cmid).
     */
    private function activity(array $extra = []): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'Path', 'json' => '{"tree":{"nodes":[]}}', 'visibility' => 1, 'createdby' => 2,
        ]);
        return $this->getDataGenerator()->create_module('adele', [
            'course' => $course->id,
            'learningpathid' => $lpid,
            'participantslist' => [2],
            'view' => 1,
            'userlist' => 1,
        ] + $extra);
    }

    /**
     * The stored mode of an activity.
     *
     * @param \stdClass $activity
     * @return string
     */
    private function stored(\stdClass $activity): string {
        global $DB;
        return (string) $DB->get_field('adele', 'teachercourseaccessmode', ['id' => $activity->id]);
    }

    /**
     * Save the activity's settings the way the form does.
     *
     * @param \stdClass $activity
     * @param string $mode The submitted mode.
     */
    private function save(\stdClass $activity, string $mode): void {
        global $DB;
        $record = $DB->get_record('adele', ['id' => $activity->id]);
        $record->instance = $activity->id;
        $record->coursemodule = $activity->cmid;
        $record->teachercourseaccessmode = $mode;
        adele_update_instance($record);
    }

    /**
     * Out of the box only existing_only; existing_only always, whatever is
     * ticked; the order is the rank, not the order of the ticks.
     */
    public function test_allowed_modes(): void {
        $this->resetAfterTest();
        $this->assertSame([mode::EXISTING_ONLY], mode::allowed(), 'Out of the box.');

        $this->site([mode::ENROL_TEACHER, mode::REQUEST]);
        $this->assertSame([mode::EXISTING_ONLY, mode::REQUEST, mode::ENROL_TEACHER], mode::allowed());

        set_config('teachercourseaccessmodes', 'nonsense,enrol_student', 'mod_adele');
        $this->assertSame([mode::EXISTING_ONLY, mode::ENROL_STUDENT], mode::allowed(), 'Unknown values are no modes.');
    }

    /**
     * A new activity starts with the site's default - if the site allows it.
     */
    public function test_new_activity_takes_the_allowed_default(): void {
        $this->resetAfterTest();
        $this->site([mode::REQUEST], mode::REQUEST);
        $this->assertSame(mode::REQUEST, $this->stored($this->activity()));

        $this->site([mode::REQUEST], mode::ENROL_EDITINGTEACHER);
        $this->assertSame(mode::EXISTING_ONLY, $this->stored($this->activity()), 'A default the site does not allow.');

        $this->site([mode::REQUEST, mode::ENROL_STUDENT], mode::REQUEST);
        $this->assertSame(mode::ENROL_STUDENT, $this->stored($this->activity(['teachercourseaccessmode' => mode::ENROL_STUDENT])));
        $this->assertSame(
            mode::REQUEST,
            $this->stored($this->activity(['teachercourseaccessmode' => mode::ENROL_EDITINGTEACHER])),
            'A submitted mode the site does not allow is not stored.'
        );
    }

    /**
     * The site default changes - existing activities keep theirs.
     */
    public function test_existing_activities_keep_their_mode(): void {
        $this->resetAfterTest();
        $this->site([mode::REQUEST], mode::EXISTING_ONLY);
        $activity = $this->activity();
        $this->site([mode::REQUEST], mode::REQUEST);
        $this->assertSame(mode::EXISTING_ONLY, $this->stored($activity));
    }

    /**
     * Saving: an allowed mode yes, a not allowed one no - not even through
     * adele_update_instance() directly.
     */
    public function test_only_allowed_modes_are_saved(): void {
        $this->resetAfterTest();
        $this->site([mode::REQUEST, mode::ENROL_STUDENT]);
        $activity = $this->activity();

        $this->save($activity, mode::ENROL_STUDENT);
        $this->assertSame(mode::ENROL_STUDENT, $this->stored($activity));

        $this->save($activity, mode::ENROL_EDITINGTEACHER);
        $this->assertSame(mode::ENROL_STUDENT, $this->stored($activity), 'Not allowed: the stored mode stays.');

        $this->save($activity, 'nonsense');
        $this->assertSame(mode::ENROL_STUDENT, $this->stored($activity), 'No mode at all: the stored mode stays.');
    }

    /**
     * The site takes a mode away: the activity keeps it stored, it acts as
     * existing_only, the form still offers it (marked) so that saving does
     * not change it; given back, it acts again.
     */
    public function test_a_mode_taken_away_acts_as_existing_only_and_comes_back(): void {
        $this->resetAfterTest();
        $this->site([mode::REQUEST]);
        $activity = $this->activity(['teachercourseaccessmode' => mode::REQUEST]);
        $this->assertSame(mode::REQUEST, mode::for_instance((int) $activity->id));

        $this->site([]);
        $this->assertSame(mode::REQUEST, $this->stored($activity), 'Stored as it was - no silent change.');
        $this->assertSame(mode::EXISTING_ONLY, mode::for_instance((int) $activity->id), 'But it does not act.');

        $context = context_module::instance($activity->cmid);
        $admin = (int) get_admin()->id;
        $options = mode::form_options(mode::REQUEST, 0, $admin, $context);
        $this->assertSame([mode::EXISTING_ONLY, mode::REQUEST], array_keys($options));
        $this->assertStringContainsString('no longer allowed', $options[mode::REQUEST]['label']);
        $this->assertSame(
            [mode::EXISTING_ONLY],
            array_keys(mode::form_options(null, 0, $admin, $context)),
            'A new activity: allowed modes only.'
        );

        $this->save($activity, mode::REQUEST);
        $this->assertSame(mode::REQUEST, $this->stored($activity), 'Saved unchanged: kept.');
        $this->assertTrue(mode::may_save(mode::EXISTING_ONLY, mode::REQUEST), 'Going down is always possible.');
        $this->assertFalse(mode::may_save(mode::ENROL_STUDENT, mode::REQUEST), 'Another mode the site does not allow: no.');

        $this->site([mode::REQUEST]);
        $this->assertSame(mode::REQUEST, mode::for_instance((int) $activity->id), 'Given back: it acts again.');
    }

    /**
     * The web service of the teacher view names the effective mode.
     */
    public function test_the_service_answers_with_the_effective_mode(): void {
        $this->resetAfterTest();
        $this->site([mode::REQUEST]);
        $activity = $this->activity(['teachercourseaccessmode' => mode::REQUEST]);
        $contextid = context_module::instance($activity->cmid)->id;
        $teacher = $this->getDataGenerator()->create_and_enrol(get_course($activity->course), 'teacher');
        $this->setUser($teacher);

        $this->assertSame(mode::REQUEST, get_node_course_access::execute($contextid)['mode']);
        $this->site([]);
        $this->assertSame(mode::EXISTING_ONLY, get_node_course_access::execute($contextid)['mode']);
    }

    /**
     * A duplicate (backup and restore) keeps the mode.
     */
    public function test_a_duplicate_keeps_the_mode(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->site([mode::REQUEST, mode::ENROL_TEACHER]);
        $activity = $this->activity(['teachercourseaccessmode' => mode::ENROL_TEACHER]);

        $course = get_course($activity->course);
        $cm = get_coursemodule_from_id('adele', $activity->cmid, 0, false, MUST_EXIST);
        $copy = duplicate_module($course, $cm);
        $this->assertSame(
            mode::ENROL_TEACHER,
            (string) $DB->get_field('adele', 'teachercourseaccessmode', ['id' => $copy->instance])
        );
    }
}
