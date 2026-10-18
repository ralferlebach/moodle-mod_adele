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
use enrol_adele\local\instance_manager;
use enrol_adele\local\teacher_grants;
use mod_adele\external\enrol_in_node_course;
use mod_adele\external\get_node_course_access;
use mod_adele\local\teacher_access_mode as mode;
use mod_adele\local\teacher_enrolment;

/**
 * Enrolment of teachers into node courses through enrol_adele, with the
 * three site policies (local_adele #600, TP4).
 *
 * The path: A, B. Two host courses embed it. People: the administrator; an
 * editing teacher of host 1 who is editing teacher in A only (no authority
 * for B); a teacher without editing rights of both hosts (the one who
 * enrols); a learner of host 1.
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_adele\local\teacher_enrolment
 * @covers     \mod_adele\local\teacher_access_mode
 * @covers     \mod_adele\external\enrol_in_node_course
 * @covers     \enrol_adele\local\teacher_grants
 * @covers     \enrol_adele\privacy\provider
 * @covers     \mod_adele\privacy\provider
 * @covers     ::adele_update_instance
 * @covers     ::adele_delete_instance
 * @group      adele_release
 */
final class teacher_enrolment_test extends advanced_testcase {
    /** @var \stdClass[] Courses: A, B, host1, host2, outside. */
    private array $courses = [];

    /** @var \stdClass[] People by key. */
    private array $people = [];

    /** @var \stdClass[] Activities: 1, 2. */
    private array $activities = [];

    /** @var int The path. */
    private int $lpid = 0;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        foreach (['A', 'B', 'host1', 'host2', 'outside'] as $key) {
            $this->courses[$key] = $gen->create_course(['shortname' => "TE-$key", 'fullname' => "Teacher enrol $key"]);
        }
        $nodes = [
            ['id' => 'dndnode_1', 'type' => 'custom', 'parentCourse' => [],
                'data' => ['course_node_id' => [(int) $this->courses['A']->id]]],
            ['id' => 'dndnode_2', 'type' => 'custom', 'parentCourse' => ['dndnode_1'],
                'data' => ['course_node_id' => [(int) $this->courses['B']->id]]],
        ];
        $this->lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'Teacher enrol path', 'description' => '', 'timecreated' => time(), 'timemodified' => time(),
            'createdby' => 2, 'json' => json_encode(['tree' => ['nodes' => $nodes, 'edges' => []]]),
        ]);
        foreach (['editor', 'tutor', 'learner'] as $key) {
            $this->people[$key] = $gen->create_user(['firstname' => 'TE', 'lastname' => ucfirst($key)]);
        }
        $gen->enrol_user($this->people['editor']->id, $this->courses['host1']->id, 'editingteacher');
        $gen->enrol_user($this->people['editor']->id, $this->courses['A']->id, 'editingteacher');
        $gen->enrol_user($this->people['tutor']->id, $this->courses['host1']->id, 'teacher');
        $gen->enrol_user($this->people['tutor']->id, $this->courses['host2']->id, 'teacher');
        $gen->enrol_user($this->people['learner']->id, $this->courses['host1']->id, 'student');

        set_config('teachercourseaccessmodes', implode(',', mode::ENROL_MODES), 'mod_adele');
        $this->setAdminUser();
        foreach ([1 => 'host1', 2 => 'host2'] as $n => $host) {
            $this->activities[$n] = $gen->create_module('adele', [
                'course' => $this->courses[$host]->id,
                'learningpathid' => $this->lpid,
                'participantslist' => [2],
                'teachercourseaccessmode' => mode::ENROL_STUDENT,
            ]);
        }
    }

    /**
     * The context id of an activity.
     *
     * @param int $n 1 or 2.
     * @return int
     */
    private function ctx(int $n): int {
        return (int) context_module::instance($this->activities[$n]->cmid)->id;
    }

    /**
     * The course list of activity n for the current user: short name => status.
     *
     * @param int $n
     * @return array
     */
    private function rows(int $n = 1): array {
        return array_column(get_node_course_access::execute($this->ctx($n))['courses'], 'status', 'shortname');
    }

    /**
     * The roles a person holds in a course through the teacher grants.
     *
     * @param string $course
     * @param string $person
     * @return string[] Role short names, sorted.
     */
    private function granted(string $course, string $person): array {
        global $DB;
        $instance = instance_manager::get_teacher_instance((int) $this->courses[$course]->id);
        if (!$instance) {
            return [];
        }
        $names = $DB->get_fieldset_sql(
            "SELECT r.shortname FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid
              WHERE ra.userid = ? AND ra.contextid = ? AND ra.component = 'enrol_adele' AND ra.itemid = ?",
            [$this->people[$person]->id, context_course::instance($this->courses[$course]->id)->id, $instance->id]
        );
        sort($names);
        return $names;
    }

    /**
     * Save the mode of activity n as the current user, the way the form does.
     *
     * @param int $n
     * @param string $mode
     */
    private function save_mode(int $n, string $mode): void {
        global $DB;
        $record = $DB->get_record('adele', ['id' => $this->activities[$n]->id]);
        $record->instance = $record->id;
        $record->coursemodule = $this->activities[$n]->cmid;
        $record->teachercourseaccessmode = $mode;
        adele_update_instance($record);
    }

    /**
     * The stored mode and grantor of activity n.
     *
     * @param int $n
     * @return array [mode, grantor]
     */
    private function stored(int $n): array {
        global $DB;
        $row = $DB->get_record('adele', ['id' => $this->activities[$n]->id]);
        return [(string) $row->teachercourseaccessmode, (int) $row->teachercourseaccessgrantor];
    }

    /**
     * Expect a call to be refused with an error code.
     *
     * @param string $code
     * @param callable $call
     */
    private function refused(string $code, callable $call): void {
        try {
            $call();
            $this->fail("Expected refusal '$code'.");
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    /**
     * Enrol as participant: offered for both courses, one enrolment through
     * enrol_adele with the site's role, logged; the course is open then.
     */
    public function test_enrol_as_participant(): void {
        $this->setUser($this->people['tutor']);
        $this->assertSame(['TE-A' => 'enrol', 'TE-B' => 'enrol'], $this->rows());
        $sink = $this->redirectEvents();
        $answer = enrol_in_node_course::execute($this->ctx(1), (int) $this->courses['B']->id);
        $this->assertTrue($answer['enrolled']);
        $this->assertSame(['student'], $this->granted('B', 'tutor'));
        $this->assertCount(1, teacher_grants::get(['userid' => $this->people['tutor']->id]));
        $logged = array_filter($sink->get_events(), fn($e) => $e instanceof event\node_course_enrolled);
        $this->assertCount(1, $logged);
        $this->assertSame(['TE-A' => 'enrol', 'TE-B' => 'open'], $this->rows());
    }

    /**
     * Forged and refused: course outside the path, a course already open,
     * a learner, a mode that does not enrol; nothing enrolled.
     */
    public function test_forged_enrolments_are_refused(): void {
        $this->setUser($this->people['tutor']);
        $b = (int) $this->courses['B']->id;
        $outside = (int) $this->courses['outside']->id;
        $this->refused('accessrequest_notinpath', fn() => enrol_in_node_course::execute($this->ctx(1), $outside));
        enrol_in_node_course::execute($this->ctx(1), $b);
        $this->refused('accessrequest_hasaccess', fn() => enrol_in_node_course::execute($this->ctx(1), $b));

        $this->setUser($this->people['learner']);
        $this->refused('nopermissions', fn() => enrol_in_node_course::execute($this->ctx(1), (int) $this->courses['A']->id));

        $this->setAdminUser();
        $this->save_mode(1, mode::EXISTING_ONLY);
        $this->setUser($this->people['tutor']);
        $this->refused('enrol_notallowed', fn() => enrol_in_node_course::execute($this->ctx(1), (int) $this->courses['A']->id));
        $this->assertSame([], $this->granted('A', 'tutor'));
    }

    /**
     * A learner recompute of enrol_adele leaves the teacher grants alone.
     */
    public function test_learner_reconcile_leaves_grants_alone(): void {
        $this->setUser($this->people['tutor']);
        enrol_in_node_course::execute($this->ctx(1), (int) $this->courses['B']->id);
        $this->setAdminUser();
        \enrol_adele\local\reconciler::reconcile_all();
        $this->assertSame(['student'], $this->granted('B', 'tutor'));
    }

    /**
     * Taken away by the site, lowered by the activity, the activity deleted,
     * the holder gone from the host: the grant goes - and only that grant.
     */
    public function test_grants_go_with_their_basis(): void {
        $this->getDataGenerator()->enrol_user($this->people['tutor']->id, $this->courses['A']->id, 'teacher');
        $this->setUser($this->people['tutor']);
        enrol_in_node_course::execute($this->ctx(1), (int) $this->courses['B']->id);

        set_config('teachercourseaccessmodes', mode::REQUEST, 'mod_adele');
        teacher_enrolment::reconcile_all();
        $this->assertSame([], $this->granted('B', 'tutor'), 'The site took the mode away.');
        $this->assertFalse(is_enrolled(context_course::instance($this->courses['B']->id), $this->people['tutor']));
        $this->assertTrue(
            is_enrolled(context_course::instance($this->courses['A']->id), $this->people['tutor']),
            'Own enrolment stays.'
        );

        set_config('teachercourseaccessmodes', implode(',', mode::ENROL_MODES), 'mod_adele');
        $this->setUser($this->people['tutor']);
        enrol_in_node_course::execute($this->ctx(1), (int) $this->courses['B']->id);
        $this->setAdminUser();
        $this->save_mode(1, mode::EXISTING_ONLY);
        $this->assertSame([], $this->granted('B', 'tutor'), 'The activity lowered its mode.');

        $this->save_mode(1, mode::ENROL_STUDENT);
        $this->setUser($this->people['tutor']);
        enrol_in_node_course::execute($this->ctx(1), (int) $this->courses['B']->id);
        $this->setAdminUser();
        $instance = enrol_get_instances($this->courses['host1']->id, true);
        foreach ($instance as $i) {
            if ($i->enrol === 'manual') {
                enrol_get_plugin('manual')->unenrol_user($i, $this->people['tutor']->id);
            }
        }
        teacher_enrolment::reconcile_all();
        $this->assertSame([], $this->granted('B', 'tutor'), 'The holder left the host course.');
    }

    /**
     * Two sources for the same course: the role is the union; taking one away
     * keeps what the other gives; deleting the activity removes its grants only.
     */
    public function test_several_sources(): void {
        global $DB;
        $b = (int) $this->courses['B']->id;
        $tutor = (int) $this->people['tutor']->id;
        $this->setAdminUser();
        $this->save_mode(2, mode::ENROL_TEACHER);
        $this->assertSame([mode::ENROL_TEACHER, (int) get_admin()->id], $this->stored(2), 'The admin may: grantor.');

        $this->setUser($this->people['tutor']);
        enrol_in_node_course::execute($this->ctx(1), $b);
        teacher_grants::add(
            (int) $this->activities[2]->cmid,
            $this->lpid,
            $b,
            $tutor,
            mode::ENROL_TEACHER,
            mode::target_role(mode::ENROL_TEACHER)
        );
        $this->assertSame(['student', 'teacher'], $this->granted('B', 'tutor'));

        $this->setAdminUser();
        $this->save_mode(2, mode::ENROL_STUDENT);
        $this->assertSame(['student'], $this->granted('B', 'tutor'), 'A teacher grant above the new mode goes.');

        course_delete_module($this->activities[1]->cmid);
        $this->assertSame([], teacher_grants::get(['cmid' => $this->activities[1]->cmid]));
        $this->assertSame([], $this->granted('B', 'tutor'), 'Neither source left.');
        $this->assertFalse($DB->record_exists('user_enrolments', [
            'userid' => $tutor,
            'enrolid' => instance_manager::get_teacher_instance($b)->id,
        ]));
    }

    /**
     * Lowered from "with editing rights" to "non-editing teacher" by somebody
     * with authority for both: the grant above the new mode goes, a new
     * enrolment gives the lower role (AK-G7).
     */
    public function test_lowered_between_teacher_modes(): void {
        $this->setAdminUser();
        $this->save_mode(1, mode::ENROL_EDITINGTEACHER);
        $this->setUser($this->people['tutor']);
        enrol_in_node_course::execute($this->ctx(1), (int) $this->courses['B']->id);
        $this->assertSame(['editingteacher'], $this->granted('B', 'tutor'));

        $this->setAdminUser();
        $this->save_mode(1, mode::ENROL_TEACHER);
        $this->assertSame([], $this->granted('B', 'tutor'), 'Above the new mode: gone.');
        $this->setUser($this->people['tutor']);
        enrol_in_node_course::execute($this->ctx(1), (int) $this->courses['B']->id);
        $this->assertSame(['teacher'], $this->granted('B', 'tutor'));
    }

    /**
     * Policy 2 (default): the editing teacher without authority for B is not
     * offered a teacher mode; a stored one (the admin's) may be kept or
     * lowered, not raised again; the grantor stays the admin.
     */
    public function test_policy_keep_and_lower(): void {
        $context = context_module::instance($this->activities[1]->cmid);
        $editor = (int) $this->people['editor']->id;
        $this->setUser($this->people['editor']);
        $options = mode::form_options(mode::ENROL_STUDENT, $this->lpid, $editor, $context);
        $this->assertSame([mode::EXISTING_ONLY, mode::ENROL_STUDENT], array_keys($options), 'Not offered.');
        $this->assertFalse(mode::may_save(mode::ENROL_TEACHER, mode::ENROL_STUDENT, $this->lpid, $editor, $context));
        $this->save_mode(1, mode::ENROL_TEACHER);
        $this->assertSame([mode::ENROL_STUDENT, 0], $this->stored(1), 'Forged: not stored.');

        $this->setAdminUser();
        $this->save_mode(1, mode::ENROL_EDITINGTEACHER);
        $this->setUser($this->people['editor']);
        $options = mode::form_options(mode::ENROL_EDITINGTEACHER, $this->lpid, $editor, $context);
        $this->assertSame(
            [mode::EXISTING_ONLY, mode::ENROL_STUDENT, mode::ENROL_TEACHER, mode::ENROL_EDITINGTEACHER],
            array_keys($options)
        );
        $this->save_mode(1, mode::ENROL_TEACHER);
        $this->assertSame([mode::ENROL_TEACHER, (int) get_admin()->id], $this->stored(1), 'Lowered, grantor kept.');
        $this->save_mode(1, mode::ENROL_EDITINGTEACHER);
        $this->assertSame(mode::ENROL_TEACHER, $this->stored(1)[0], 'No raising again.');
        $this->assertArrayNotHasKey(
            mode::ENROL_EDITINGTEACHER,
            mode::form_options(mode::ENROL_TEACHER, $this->lpid, $editor, $context)
        );
    }

    /**
     * Policy 1: shown locked; a stored teacher mode cannot be changed at all.
     */
    public function test_policy_locked(): void {
        set_config('teachercourseaccesspolicy', mode::POLICY_LOCKED, 'mod_adele');
        $context = context_module::instance($this->activities[1]->cmid);
        $editor = (int) $this->people['editor']->id;
        $options = mode::form_options(mode::ENROL_STUDENT, $this->lpid, $editor, $context);
        $this->assertTrue($options[mode::ENROL_TEACHER]['disabled']);
        $this->assertTrue($options[mode::ENROL_EDITINGTEACHER]['disabled']);
        $this->assertFalse(mode::is_locked(mode::ENROL_STUDENT, $this->lpid, $editor, $context));

        $this->setAdminUser();
        $this->save_mode(1, mode::ENROL_TEACHER);
        $this->setUser($this->people['editor']);
        $this->assertTrue(mode::is_locked(mode::ENROL_TEACHER, $this->lpid, $editor, $context));
        $this->save_mode(1, mode::EXISTING_ONLY);
        $this->assertSame(mode::ENROL_TEACHER, $this->stored(1)[0], 'Locked: not even lowered.');
    }

    /**
     * Policy 3: who holds mod/adele:delegatecourseaccess chooses a teacher
     * mode and becomes its grantor; the tutor enrols as teacher. Back to
     * policy 2: the value stays stored, the grant goes (no lasting blank cheque).
     */
    public function test_policy_delegation_and_its_end(): void {
        set_config('teachercourseaccesspolicy', mode::POLICY_DELEGATE, 'mod_adele');
        $this->setUser($this->people['editor']);
        $this->save_mode(1, mode::ENROL_TEACHER);
        $this->assertSame([mode::ENROL_TEACHER, (int) $this->people['editor']->id], $this->stored(1));

        $this->setUser($this->people['tutor']);
        enrol_in_node_course::execute($this->ctx(1), (int) $this->courses['B']->id);
        $this->assertSame(['teacher'], $this->granted('B', 'tutor'));

        set_config('teachercourseaccesspolicy', mode::POLICY_KEEP, 'mod_adele');
        teacher_enrolment::reconcile_all();
        $this->assertSame(mode::ENROL_TEACHER, $this->stored(1)[0], 'The value is kept ...');
        $this->assertSame([], $this->granted('B', 'tutor'), '... the enrolment is not.');
        $this->setUser($this->people['tutor']);
        $this->assertSame('unavailable', $this->rows()['TE-B']);
        $this->refused('enrol_noauthority', fn() => enrol_in_node_course::execute($this->ctx(1), (int) $this->courses['B']->id));
    }

    /**
     * A changed site role for a mode takes away the grants with the old role.
     */
    public function test_changed_role_takes_old_grants_away(): void {
        $this->setUser($this->people['tutor']);
        enrol_in_node_course::execute($this->ctx(1), (int) $this->courses['B']->id);
        $guestrole = get_archetype_roles('guest');
        set_config('teachercourseaccessrole_' . mode::ENROL_STUDENT, (int) reset($guestrole)->id, 'mod_adele');
        teacher_enrolment::reconcile_all();
        $this->assertSame([], $this->granted('B', 'tutor'));
    }

    /**
     * A hidden course: no enrolment whose role could not open it.
     */
    public function test_hidden_course(): void {
        update_course((object) ['id' => $this->courses['B']->id, 'visible' => 0]);
        $this->setUser($this->people['tutor']);
        $this->assertSame('unavailable', $this->rows()['TE-B']);
        $this->setAdminUser();
        $this->save_mode(1, mode::ENROL_TEACHER);
        $this->setUser($this->people['tutor']);
        $this->assertSame('enrol', $this->rows()['TE-B'], 'A teacher may see hidden courses.');
    }

    /**
     * Privacy: the grants are exported and deleted with their enrolment.
     */
    public function test_privacy(): void {
        $this->setUser($this->people['tutor']);
        enrol_in_node_course::execute($this->ctx(1), (int) $this->courses['B']->id);
        $context = context_course::instance($this->courses['B']->id);
        $contexts = \enrol_adele\privacy\provider::get_contexts_for_userid((int) $this->people['tutor']->id);
        $this->assertSame([(int) $context->id], array_map('intval', $contexts->get_contextids()));
        $this->setAdminUser();
        \enrol_adele\privacy\provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            $this->people['tutor'],
            'enrol_adele',
            [$context->id]
        ));
        $this->assertSame([], $this->granted('B', 'tutor'));
        $this->assertSame([], teacher_grants::get(['userid' => $this->people['tutor']->id]));
    }

    /**
     * Privacy of mod_adele: the person who chose the mode (policy 3) is
     * found, exported and anonymised; the mode then has no authority, the
     * teacher's enrolment it gave goes at once, and saving again with
     * authority re-delegates (mod_adele #33, decision 2026-10-17).
     */
    public function test_privacy_of_the_grantor(): void {
        global $DB;
        set_config('teachercourseaccesspolicy', mode::POLICY_DELEGATE, 'mod_adele');
        $this->setUser($this->people['editor']);
        $this->save_mode(1, mode::ENROL_TEACHER);
        $this->setUser($this->people['tutor']);
        enrol_in_node_course::execute($this->ctx(1), (int) $this->courses['B']->id);
        $this->assertSame(['teacher'], $this->granted('B', 'tutor'));
        $this->setAdminUser();

        $provider = \mod_adele\privacy\provider::class;
        $tables = array_map(
            fn($item) => $item->get_name(),
            $provider::get_metadata(new \core_privacy\local\metadata\collection('mod_adele'))->get_collection()
        );
        $this->assertSame(['adele'], $tables);
        $context = context_module::instance($this->activities[1]->cmid);
        $this->assertSame(
            [(int) $context->id],
            array_map('intval', $provider::get_contexts_for_userid((int) $this->people['editor']->id)->get_contextids())
        );
        $this->assertSame([], $provider::get_contexts_for_userid((int) $this->people['tutor']->id)->get_contextids());
        $list = new \core_privacy\local\request\userlist($context, 'mod_adele');
        $provider::get_users_in_context($list);
        $this->assertSame([(int) $this->people['editor']->id], array_map('intval', $list->get_userids()));

        \core_privacy\local\request\writer::reset();
        $provider::export_user_data(new \core_privacy\local\request\approved_contextlist(
            $this->people['editor'],
            'mod_adele',
            [$context->id]
        ));
        $data = \core_privacy\local\request\writer::with_context($context)->get_data(
            [get_string('privacy:metadata:adele', 'mod_adele')]
        );
        $this->assertSame(mode::ENROL_TEACHER, $data->teachercourseaccessmode);

        $provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            $this->people['editor'],
            'mod_adele',
            [$context->id]
        ));
        $this->assertSame([mode::ENROL_TEACHER, 0], $this->stored(1), 'The mode stays, the grantor is anonymised.');
        $this->assertSame([], $this->granted('B', 'tutor'), 'No authority left: the enrolment goes at once.');
        $this->assertTrue($DB->record_exists('adele', ['id' => $this->activities[1]->id]));

        $this->setUser($this->people['editor']);
        $this->save_mode(1, mode::ENROL_TEACHER);
        $this->setUser($this->people['tutor']);
        enrol_in_node_course::execute($this->ctx(1), (int) $this->courses['B']->id);
        $this->assertSame(['teacher'], $this->granted('B', 'tutor'), 'Saved again with authority: delegated again.');
    }

    /**
     * Privacy in bulk (re-audit TP-06, TP-08): the delegating person and the
     * teacher deleted together in one activity's context - that activity's
     * grant goes, the grant another activity gives independently stays;
     * deleting everybody in the second context takes the rest. Afterwards:
     * no ADELE role assignment without a grant behind it, no grant of an
     * activity without authority, the teacher's own enrolments untouched.
     */
    public function test_privacy_in_bulk_keeps_independent_access(): void {
        global $DB;
        set_config('teachercourseaccesspolicy', mode::POLICY_DELEGATE, 'mod_adele');
        $b = (int) $this->courses['B']->id;
        $tutor = (int) $this->people['tutor']->id;
        $this->setUser($this->people['editor']);
        $this->save_mode(1, mode::ENROL_TEACHER);
        $this->setAdminUser();
        $this->save_mode(2, mode::ENROL_TEACHER);
        $this->setUser($this->people['tutor']);
        enrol_in_node_course::execute($this->ctx(1), $b);
        // Activity 2 gives the same access on its own (the course is open
        // already, so the service refuses a second request): its grant.
        teacher_grants::add(
            (int) $this->activities[2]->cmid,
            $this->lpid,
            $b,
            $tutor,
            mode::ENROL_TEACHER,
            mode::target_role(mode::ENROL_TEACHER)
        );
        $this->setAdminUser();
        $this->assertSame(['teacher'], $this->granted('B', 'tutor'));
        $this->assertCount(2, teacher_grants::get(['userid' => $tutor]), 'One grant per activity.');

        \mod_adele\privacy\provider::delete_data_for_users(new \core_privacy\local\request\approved_userlist(
            context_module::instance($this->activities[1]->cmid),
            'mod_adele',
            [(int) $this->people['editor']->id, $tutor]
        ));
        $this->assertSame([mode::ENROL_TEACHER, 0], $this->stored(1));
        $this->assertSame([mode::ENROL_TEACHER, (int) get_admin()->id], $this->stored(2), 'The other activity untouched.');
        $this->assertSame([], teacher_grants::get(['cmid' => $this->activities[1]->cmid]), 'No authority: its grant goes.');
        $this->assertSame(['teacher'], $this->granted('B', 'tutor'), 'The independent grant of activity 2 stays.');

        \mod_adele\privacy\provider::delete_data_for_all_users_in_context(context_module::instance($this->activities[2]->cmid));
        $this->assertSame([mode::ENROL_TEACHER, 0], $this->stored(2));
        $this->assertSame([], $this->granted('B', 'tutor'));
        $this->assertSame([], teacher_grants::get(['userid' => $tutor]));

        // Invariants: no role assignment of enrol_adele's teacher instances
        // without a grant behind it; the tutor's own roles stay.
        $this->assertSame(0, $DB->count_records_sql(
            "SELECT COUNT(1) FROM {role_assignments} ra
               JOIN {enrol} e ON e.id = ra.itemid AND e.enrol = 'adele'
              WHERE ra.component = 'enrol_adele' AND ra.userid = :u",
            ['u' => $tutor]
        ), 'No ADELE role assignment left for the tutor.');
        foreach (['host1', 'host2'] as $host) {
            $this->assertTrue(
                is_enrolled(context_course::instance($this->courses[$host]->id), $tutor),
                "Own enrolment in $host stays."
            );
        }
        $this->assertTrue($DB->record_exists('adele', ['id' => $this->activities[1]->id]), 'The activities stay.');
    }

    /**
     * Opening the teacher view gives no right (re-audit TP-08): the view
     * mode, the course list of the view and a refused enrolment - under
     * policy 3 with a delegated teacher mode - change no role assignment and
     * no enrolment; only the explicit action enrols.
     */
    public function test_opening_the_teacher_view_grants_nothing(): void {
        global $DB;
        set_config('teachercourseaccesspolicy', mode::POLICY_DELEGATE, 'mod_adele');
        $this->setUser($this->people['editor']);
        $this->save_mode(1, mode::ENROL_TEACHER);
        $snapshot = fn() => [$DB->count_records('role_assignments'), $DB->count_records('user_enrolments')];
        $before = $snapshot();

        $this->setUser($this->people['tutor']);
        $context = context_module::instance($this->activities[1]->cmid);
        for ($i = 0; $i < 3; $i++) {
            \mod_adele\local\view_mode::for_context($context);
            $this->assertSame('enrol', $this->rows()['TE-B']);
        }
        $outside = (int) $this->courses['outside']->id;
        $this->refused('accessrequest_notinpath', fn() => enrol_in_node_course::execute($this->ctx(1), $outside));
        $this->assertSame($before, $snapshot(), 'Looking changed nothing.');
        $this->assertSame([], $this->granted('B', 'tutor'));

        enrol_in_node_course::execute($this->ctx(1), (int) $this->courses['B']->id);
        $this->assertSame(['teacher'], $this->granted('B', 'tutor'), 'Only the action enrols.');
    }
}
