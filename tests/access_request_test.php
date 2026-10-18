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
use mod_adele\external\get_access_request;
use mod_adele\external\get_node_course_access;
use mod_adele\external\send_access_request;
use mod_adele\local\teacher_access_mode as mode;

/**
 * "Request access" in the teacher view (local_adele #600, TP2).
 *
 * The path: A (the teacher is in it), B (not in it; an editing teacher of B,
 * a teacher of B without editing rights, a suspended editing teacher of B),
 * C (not in it, nobody responsible). Embedded in a host course whose teacher
 * asks. The request goes only to the people responsible for the course asked
 * for, only on sending, only with the effective mode "request", only for a
 * course of the path the teacher cannot open yet - every condition checked
 * by the server on the call itself.
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_adele\local\access_request
 * @covers     \mod_adele\local\node_course_access
 * @covers     \mod_adele\external\get_access_request
 * @covers     \mod_adele\external\send_access_request
 * @covers     \mod_adele\event\course_access_requested
 */
final class access_request_test extends advanced_testcase {
    /** @var \stdClass[] Courses by key: A, B, C, host, outside. */
    private array $courses = [];

    /** @var \stdClass[] People by key. */
    private array $people = [];

    /** @var int Context of the activity. */
    private int $contextid = 0;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        foreach (['A', 'B', 'C', 'outside'] as $key) {
            $this->courses[$key] = $gen->create_course(['shortname' => "AR-$key", 'fullname' => "Request course $key"]);
        }
        $this->courses['host'] = $gen->create_course(['shortname' => 'AR-HOST', 'fullname' => 'Request host']);
        $nodes = [
            ['id' => 'dndnode_1', 'type' => 'custom', 'parentCourse' => [],
                'data' => ['course_node_id' => [(int) $this->courses['A']->id]]],
            ['id' => 'dndnode_2', 'type' => 'orcourses', 'parentCourse' => ['dndnode_1'],
                'data' => ['course_node_id' => [(int) $this->courses['B']->id, (int) $this->courses['C']->id]]],
        ];
        $lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'Request path', 'description' => '', 'timecreated' => time(), 'timemodified' => time(),
            'createdby' => 2, 'json' => json_encode(['tree' => ['nodes' => $nodes, 'edges' => []]]),
        ]);
        set_config('teachercourseaccessmodes', mode::REQUEST, 'mod_adele');
        $activity = $gen->create_module('adele', [
            'course' => $this->courses['host']->id,
            'learningpathid' => $lpid,
            'participantslist' => [2],
            'teachercourseaccessmode' => mode::REQUEST,
        ]);
        $this->contextid = (int) context_module::instance($activity->cmid)->id;

        foreach (['asker', 'owner', 'tutorb', 'suspended', 'learner'] as $key) {
            $this->people[$key] = $gen->create_user(['firstname' => 'AR', 'lastname' => ucfirst($key)]);
        }
        $gen->enrol_user($this->people['asker']->id, $this->courses['host']->id, 'teacher');
        $gen->enrol_user($this->people['asker']->id, $this->courses['A']->id, 'teacher');
        $gen->enrol_user($this->people['learner']->id, $this->courses['host']->id, 'student');
        $gen->enrol_user($this->people['owner']->id, $this->courses['B']->id, 'editingteacher');
        $gen->enrol_user($this->people['tutorb']->id, $this->courses['B']->id, 'teacher');
        $gen->enrol_user(
            $this->people['suspended']->id,
            $this->courses['B']->id,
            'editingteacher',
            'manual',
            0,
            0,
            ENROL_USER_SUSPENDED
        );
        $gen->enrol_user($this->people['owner']->id, $this->courses['outside']->id, 'editingteacher');
    }

    /**
     * The course list as the asking teacher gets it: short name => status.
     *
     * @return array
     */
    private function statuses(): array {
        $this->setUser($this->people['asker']);
        return array_column(get_node_course_access::execute($this->contextid)['courses'], 'status', 'shortname');
    }

    /**
     * Expect a call to be refused with an error code.
     *
     * @param string $code The expected error code.
     * @param callable $call The call.
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
     * With "request": open where open, a request where somebody can be asked,
     * a text to copy where nobody can. Without it: no request at all.
     */
    public function test_rows_offer_a_request_only_in_request_mode(): void {
        $this->assertSame(['AR-A' => 'open', 'AR-B' => 'request', 'AR-C' => 'request_copy'], $this->statuses());

        set_config('teachercourseaccessmodes', '', 'mod_adele');
        $this->assertSame(['AR-A' => 'open', 'AR-B' => 'none', 'AR-C' => 'none'], $this->statuses(), 'Mode taken away.');
    }

    /**
     * The dialog: only the active people of B who may enrol; the asker never;
     * the draft names host course, path and course asked for.
     */
    public function test_the_dialog_names_the_responsible_people_and_prefills_the_message(): void {
        $this->setUser($this->people['asker']);
        $answer = get_access_request::execute($this->contextid, (int) $this->courses['B']->id);
        $this->assertSame([fullname($this->people['owner'])], array_column($answer['recipients'], 'fullname'));
        foreach (['Request host', 'Request path', 'Request course B'] as $name) {
            $this->assertStringContainsString($name, $answer['message']);
        }

        $answer = get_access_request::execute($this->contextid, (int) $this->courses['C']->id);
        $this->assertSame([], $answer['recipients'], 'C has nobody to ask.');
        $this->assertSame('', $answer['fallback']);
        set_config('teachercourseaccessrequestfallback', 'Write to support@example.invalid', 'mod_adele');
        $answer = get_access_request::execute($this->contextid, (int) $this->courses['C']->id);
        $this->assertStringContainsString('support@example.invalid', $answer['fallback']);
    }

    /**
     * The site chooses who counts as responsible.
     */
    public function test_the_site_chooses_the_recipients(): void {
        $teacherrole = (int) \core\di::get(\moodle_database::class)->get_field('role', 'id', ['shortname' => 'teacher']);
        assign_capability('moodle/course:enrolreview', CAP_ALLOW, $teacherrole, context_course::instance($this->courses['B']->id));
        assign_capability('enrol/manual:enrol', CAP_PROHIBIT, $teacherrole, context_course::instance($this->courses['B']->id));

        $this->setUser($this->people['asker']);
        $names = fn() => array_column(
            get_access_request::execute($this->contextid, (int) $this->courses['B']->id)['recipients'],
            'fullname'
        );
        $this->assertSame([fullname($this->people['owner'])], $names(), 'Who may enrol.');
        set_config('teachercourseaccessrequestrecipients', 'enrolreview', 'mod_adele');
        $this->assertEqualsCanonicalizing(
            [fullname($this->people['owner']), fullname($this->people['tutorb'])],
            $names(),
            'Who may review enrolments.'
        );
    }

    /**
     * Sending: one notification from the asker to the chosen person with the
     * teacher's own text and the context; logged; nobody enrolled.
     */
    public function test_sending_notifies_the_chosen_person_and_enrols_nobody(): void {
        $this->setUser($this->people['asker']);
        $sink = $this->redirectMessages();
        $events = $this->redirectEvents();
        $result = send_access_request::execute(
            $this->contextid,
            (int) $this->courses['B']->id,
            (int) $this->people['owner']->id,
            'My own words, please.'
        );
        $this->assertTrue($result['sent']);
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $message = reset($messages);
        $this->assertSame((int) $this->people['owner']->id, (int) $message->useridto);
        $this->assertSame((int) $this->people['asker']->id, (int) $message->useridfrom);
        $this->assertSame('mod_adele', $message->component);
        $this->assertSame('courseaccessrequest', $message->eventtype);
        $this->assertStringContainsString('Request course B', $message->subject);
        $this->assertStringContainsString('My own words, please.', $message->fullmessage);
        $this->assertStringContainsString('Request host', $message->fullmessage);

        $logged = array_values(array_filter(
            $events->get_events(),
            fn($e) => $e instanceof event\course_access_requested
        ));
        $this->assertCount(1, $logged);
        $this->assertSame((int) $this->people['owner']->id, (int) $logged[0]->relateduserid);
        $this->assertSame((int) $this->courses['B']->id, (int) $logged[0]->other['courseid']);
        $this->assertFalse(
            is_enrolled(context_course::instance($this->courses['B']->id), $this->people['asker']),
            'Nobody enrolled.'
        );
    }

    /**
     * Every forged call is refused - and sends nothing.
     */
    public function test_forged_requests_are_refused(): void {
        $sink = $this->redirectMessages();
        $this->setUser($this->people['asker']);
        $b = (int) $this->courses['B']->id;
        $owner = (int) $this->people['owner']->id;
        $send = fn(int $courseid, int $to, string $text = 'Hello') =>
            send_access_request::execute($this->contextid, $courseid, $to, $text);

        $this->refused('accessrequest_norecipient', fn() => $send($b, (int) $this->people['tutorb']->id));
        $this->refused('accessrequest_norecipient', fn() => $send($b, (int) $this->people['suspended']->id));
        $this->refused('accessrequest_norecipient', fn() => $send($b, (int) $this->people['learner']->id));
        $this->refused('accessrequest_norecipient', fn() => $send($b, (int) $this->people['asker']->id));
        $this->refused('accessrequest_norecipient', fn() => $send((int) $this->courses['C']->id, $owner));
        $this->refused('accessrequest_notinpath', fn() => $send((int) $this->courses['outside']->id, $owner));
        $this->refused('accessrequest_hasaccess', fn() => $send((int) $this->courses['A']->id, $owner));
        $this->refused('accessrequest_badtext', fn() => $send($b, $owner, '   '));

        set_config('teachercourseaccessmodes', '', 'mod_adele');
        $this->refused('accessrequest_notallowed', fn() => $send($b, $owner));
        set_config('teachercourseaccessmodes', mode::REQUEST, 'mod_adele');

        $this->setUser($this->people['learner']);
        $this->refused('nopermissions', fn() => $send($b, $owner));
        $this->refused('nopermissions', fn() => get_access_request::execute($this->contextid, $b));

        $this->assertSame([], $sink->get_messages(), 'Nothing was sent.');
    }
}
