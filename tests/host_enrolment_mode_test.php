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
use enrol_adele\local\instance_manager;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/modlib.php');

/**
 * The host enrolment mode of sources 2/3, set and changed through the
 * activity's own save path (mod_adele #22).
 *
 * "visible" enrols with access, "hidden" keeps a traceable but suspended
 * enrolment, "none" creates no host instance at all - the subscription to the
 * path happens in every mode. Changing the mode keeps the record: suspended
 * while the mode withholds access, the same record active again on the way
 * back. An activity without a stored mode behaves as "visible".
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::adele_update_instance
 * @covers     \mod_adele_observer::sync_host_access_for_node_enrolment
 * @covers     \enrol_adele\local\reconciler::reconcile_host_user
 */
final class host_enrolment_mode_test extends advanced_testcase {
    /** @var \stdClass The starting node course. */
    private \stdClass $node;

    /** @var \stdClass The host course. */
    private \stdClass $host;

    /** @var int The learning path. */
    private int $lpid = 0;

    /** @var \stdClass The learner, in the starting node course. */
    private \stdClass $learner;

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
        $this->lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'Issue 22', 'description' => '', 'timecreated' => time(), 'timemodified' => time(),
            'createdby' => 2, 'json' => json_encode(['tree' => ['nodes' => $nodes, 'edges' => []]]),
        ]);
        $this->learner = $gen->create_user();
        $gen->enrol_user($this->learner->id, $this->node->id, 'student');
    }

    /**
     * Save a new activity in the host course with sources 2 and 3.
     *
     * @param string $mode visible, hidden or none.
     * @return \stdClass The activity.
     */
    private function save(string $mode): \stdClass {
        $activity = $this->getDataGenerator()->create_module('adele', [
            'course' => $this->host->id, 'learningpathid' => $this->lpid,
            'participantslist' => [2, 3], 'hostenrolmentmode' => $mode,
        ]);
        $this->runAdhocTasks();
        return $activity;
    }

    /**
     * Change the mode the way the activity form does: update_moduleinfo().
     *
     * @param \stdClass $activity
     * @param string $mode
     * @return void
     */
    private function change(\stdClass $activity, string $mode): void {
        $cm = get_coursemodule_from_id('adele', $activity->cmid, 0, false, MUST_EXIST);
        [$cm, , , $data] = get_moduleinfo_data($cm, $this->host);
        $data->hostenrolmentmode = $mode;
        $data->participantslist = [2, 3];
        update_moduleinfo($cm, $data, $this->host);
        $this->runAdhocTasks();
    }

    /**
     * The learner's ADELE host enrolment, or false.
     *
     * @return \stdClass|false
     */
    private function ue() {
        global $DB;
        return $DB->get_record_sql(
            "SELECT ue.id, ue.status FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :c AND e.enrol = 'adele' AND e.customint2 = :kind AND ue.userid = :u",
            ['c' => $this->host->id, 'kind' => instance_manager::KIND_HOST, 'u' => $this->learner->id]
        );
    }

    /**
     * Whether the learner is subscribed.
     *
     * @return bool
     */
    private function subscribed(): bool {
        global $DB;
        return $DB->record_exists('local_adele_path_user', [
            'user_id' => $this->learner->id, 'learning_path_id' => $this->lpid,
        ]);
    }

    /**
     * Whether the learner can enter the host course.
     *
     * @return bool
     */
    private function access(): bool {
        return is_enrolled(context_course::instance($this->host->id), $this->learner, '', true);
    }

    /**
     * Each mode on a new embedding.
     *
     * @return void
     */
    public function test_each_mode_on_a_new_embedding(): void {
        global $DB;
        $this->save('none');
        $this->assertTrue($this->subscribed(), 'none: the path subscription happens.');
        $this->assertFalse(
            $DB->record_exists('enrol', ['courseid' => $this->host->id, 'enrol' => 'adele']),
            'none: no host instance at all.'
        );
        $this->assertFalse($this->access());
        course_delete_module((int) $DB->get_field('course_modules', 'id', ['course' => $this->host->id]));

        $this->save('hidden');
        $ue = $this->ue();
        $this->assertNotFalse($ue, 'hidden: a traceable enrolment.');
        $this->assertSame(ENROL_USER_SUSPENDED, (int) $ue->status);
        $this->assertArrayHasKey(
            $this->learner->id,
            get_enrolled_users(context_course::instance($this->host->id)),
            'hidden: listed among the enrolled users (reports, certificates).'
        );
        $this->assertFalse($this->access(), 'hidden: no access.');
    }

    /**
     * visible -> hidden -> none -> visible through the form's save path: the
     * same record throughout, access only where the mode grants it.
     *
     * @return void
     */
    public function test_changing_the_mode_keeps_the_record(): void {
        $activity = $this->save('visible');
        $ue = $this->ue();
        $this->assertNotFalse($ue);
        $this->assertSame(ENROL_USER_ACTIVE, (int) $ue->status);
        $this->assertTrue($this->access());

        foreach (['hidden' => false, 'none' => false, 'visible' => true] as $mode => $access) {
            $this->change($activity, $mode);
            $now = $this->ue();
            $this->assertNotFalse($now, "$mode: the record is kept.");
            $this->assertSame((int) $ue->id, (int) $now->id, "$mode: the same record.");
            $this->assertSame($access ? ENROL_USER_ACTIVE : ENROL_USER_SUSPENDED, (int) $now->status, $mode);
            $this->assertSame($access, $this->access(), $mode);
            $this->assertTrue($this->subscribed(), "$mode: the subscription stays.");
        }
    }

    /**
     * An existing activity without a stored mode behaves as "visible"; a
     * record inserted without the field gets "visible" from the schema.
     *
     * @return void
     */
    public function test_without_a_mode_it_is_visible(): void {
        global $DB;
        $id = (int) $DB->insert_record('adele', (object) [
            'course' => $this->host->id, 'name' => 'Old', 'intro' => '', 'introformat' => 1,
            'learningpathid' => $this->lpid, 'participantslist' => '2', 'userlist' => 1, 'view' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $this->assertSame('visible', $DB->get_field('adele', 'hostenrolmentmode', ['id' => $id]), 'Schema default.');

        $DB->set_field('adele', 'hostenrolmentmode', '', ['id' => $id]);
        \enrol_adele\local\reconciler::reconcile_all();
        $this->runAdhocTasks();
        $ue = $this->ue();
        $this->assertNotFalse($ue, 'An empty mode enrols ...');
        $this->assertSame(ENROL_USER_ACTIVE, (int) $ue->status, '... with access, as "visible".');
    }
}
