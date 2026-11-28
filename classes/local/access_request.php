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

namespace mod_adele\local;

use context_course;
use context_module;
use core_user;
use moodle_exception;
use moodle_url;

/**
 * "Request access" for a node course (local_adele #600, TP2).
 *
 * A teacher in the teacher view of an embedding who cannot open one of its
 * node courses asks the people responsible for THAT course - never the
 * teachers of the host course, never anybody chosen by the browser. Who
 * counts as responsible is the site's choice (setting
 * teachercourseaccessrequestrecipients): active members of the target course
 * who may enrol people there (enrol/manual:enrol) or who may review its
 * enrolments (moodle/course:enrolreview). The message is the teacher's
 * own, prefilled and editable; it goes out only when the teacher sends it,
 * as a Moodle notification. Nobody is enrolled here.
 *
 * Every check is made again on sending: the activity's teacher view, the
 * effective mode "request", the course belonging to the embedded path, no
 * access to it yet, the recipient among the responsible people.
 *
 * @package    mod_adele
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access_request {
    /** @var string Recipients: who may enrol people into the course. */
    public const RECIPIENTS_ENROLERS = 'enrolers';

    /** @var string Recipients: who may review the course's enrolments. */
    public const RECIPIENTS_ENROLREVIEW = 'enrolreview';

    /** @var int The longest message accepted. */
    public const MAX_MESSAGE = 4000;

    /**
     * The capability that makes a member of the target course a recipient.
     *
     * @return string
     */
    public static function recipient_capability(): string {
        return get_config('mod_adele', 'teachercourseaccessrequestrecipients') === self::RECIPIENTS_ENROLREVIEW
            ? 'moodle/course:enrolreview'
            : 'enrol/manual:enrol';
    }

    /**
     * The people responsible for a course, the asking person left out.
     *
     * @param int $courseid The target course.
     * @param int $userid The person asking.
     * @return \stdClass[] Users by id, sorted by name.
     */
    public static function recipients(int $courseid, int $userid): array {
        $context = context_course::instance($courseid);
        $users = get_enrolled_users(
            $context,
            self::recipient_capability(),
            0,
            'u.*',
            'u.lastname ASC, u.firstname ASC, u.id ASC',
            0,
            0,
            true
        );
        unset($users[$userid]);
        foreach ($users as $id => $user) {
            if (!empty($user->suspended) || !empty($user->deleted)) {
                unset($users[$id]);
            }
        }
        return $users;
    }

    /**
     * Check everything for a request about one course of an embedding.
     *
     * @param \stdClass|\cm_info $cm The mod_adele course module.
     * @param int $courseid The target course.
     * @return \stdClass The target course.
     * @throws moodle_exception When a request is not possible.
     */
    public static function require_possible($cm, int $courseid): \stdClass {
        global $DB;
        if (teacher_access_mode::for_instance((int) $cm->instance) !== teacher_access_mode::REQUEST) {
            throw new moodle_exception('accessrequest_notallowed', 'mod_adele');
        }
        $ofpath = array_column(node_course_access::for_activity($cm), null, 'courseid');
        if (empty($ofpath[$courseid])) {
            throw new moodle_exception('accessrequest_notinpath', 'mod_adele');
        }
        if ($ofpath[$courseid]['status'] === node_course_access::STATUS_OPEN) {
            throw new moodle_exception('accessrequest_hasaccess', 'mod_adele');
        }
        return $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    }

    /**
     * The prefilled message, in the asking person's language.
     *
     * @param \stdClass|\cm_info $cm The mod_adele course module.
     * @param \stdClass $target The target course.
     * @return string
     */
    public static function draft($cm, \stdClass $target): string {
        global $DB;
        $host = get_course($cm->course);
        $pathid = (int) $DB->get_field('adele', 'learningpathid', ['id' => $cm->instance]);
        $path = (string) $DB->get_field('local_adele_learning_paths', 'name', ['id' => $pathid]);
        return get_string('accessrequest_draft', 'mod_adele', (object) [
            'host' => format_string($host->fullname, true, ['context' => context_course::instance($host->id)]),
            'path' => format_string($path, true, ['context' => context_module::instance($cm->id)]),
            'target' => format_string($target->fullname, true, ['context' => context_course::instance($target->id)]),
        ]);
    }

    /**
     * Send the request: a notification from the asking person to the chosen
     * recipient, with the context (host course, path, target course) below
     * the teacher's own text. Logged as course_access_requested.
     *
     * @param \stdClass|\cm_info $cm The mod_adele course module.
     * @param \stdClass $target The target course (checked by require_possible()).
     * @param int $recipientid The chosen recipient.
     * @param string $text The teacher's message.
     * @return int The message id.
     * @throws moodle_exception When the recipient or the text is not acceptable.
     */
    public static function send($cm, \stdClass $target, int $recipientid, string $text): int {
        global $DB, $USER;
        $recipients = self::recipients((int) $target->id, (int) $USER->id);
        if (empty($recipients[$recipientid])) {
            throw new moodle_exception('accessrequest_norecipient', 'mod_adele');
        }
        $text = trim($text);
        if ($text === '' || \core_text::strlen($text) > self::MAX_MESSAGE) {
            throw new moodle_exception('accessrequest_badtext', 'mod_adele', '', self::MAX_MESSAGE);
        }

        $host = get_course($cm->course);
        $pathid = (int) $DB->get_field('adele', 'learningpathid', ['id' => $cm->instance]);
        $path = (string) $DB->get_field('local_adele_learning_paths', 'name', ['id' => $pathid]);
        $recipient = $recipients[$recipientid];
        $participants = new moodle_url('/user/index.php', ['id' => $target->id]);
        $a = (object) [
            'target' => format_string($target->fullname, true, ['context' => context_course::instance($target->id)]),
            'host' => format_string($host->fullname, true, ['context' => context_course::instance($host->id)]),
            'path' => format_string($path, true, ['context' => context_module::instance($cm->id)]),
            'from' => fullname($USER),
            'participants' => $participants->out(false),
        ];

        $force = force_current_language($recipient->lang ?? '');
        $subject = get_string('accessrequest_subject', 'mod_adele', $a);
        $context = get_string('accessrequest_context', 'mod_adele', $a);
        force_current_language($force);

        $message = new \core\message\message();
        $message->component = 'mod_adele';
        $message->name = 'courseaccessrequest';
        $message->userfrom = core_user::get_user($USER->id);
        $message->userto = $recipient;
        $message->subject = $subject;
        $message->fullmessage = $text . "\n\n---\n" . $context;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = format_text(s($text), FORMAT_PLAIN) . '<hr>' . format_text(s($context), FORMAT_PLAIN);
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = $participants->out(false);
        $message->contexturlname = $a->target;
        $message->courseid = $target->id;
        $id = message_send($message);
        if (!$id) {
            throw new moodle_exception('accessrequest_notsent', 'mod_adele');
        }

        \mod_adele\event\course_access_requested::create([
            'context' => context_module::instance($cm->id),
            'objectid' => $cm->instance,
            'relateduserid' => $recipientid,
            'other' => ['courseid' => (int) $target->id],
        ])->trigger();
        return (int) $id;
    }
}
