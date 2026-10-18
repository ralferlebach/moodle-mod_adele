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
use enrol_adele\local\teacher_grants;
use moodle_exception;

/**
 * A teacher enrols into a node course from the teacher view (local_adele
 * #600, TP4) - and loses that enrolment again when its basis goes.
 *
 * The enrolment is a grant of enrol_adele with this activity as its source.
 * It is possible when, all checked on the server, for this person and this
 * course: the teacher view of the activity, mod/adele:enrolinnodecourses
 * there, the activity's effective mode is an enrolment mode, a teacher mode
 * still has its authority (teacher_access_mode::is_authorized()), the course
 * belongs to the embedded path, the person cannot open it yet, the site's
 * role for the mode exists and could open a hidden course if the course is
 * hidden, and enrol_adele is enabled. The role is the site's - never one the
 * browser names.
 *
 * A grant stays while the same conditions hold for it, judged with the
 * grant's own mode: its rank must not exceed the activity's effective mode,
 * its role must still be the site's role for its mode, and its holder must
 * still have the teacher view and the capability. reconcile_cm() and
 * reconcile_all() take away every grant that no longer holds - and only
 * those; what other sources give stays.
 *
 * @package    mod_adele
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teacher_enrolment {
    /**
     * Whether enrol_adele can hold teacher grants here.
     *
     * @return bool
     */
    public static function available(): bool {
        return class_exists(teacher_grants::class) && enrol_is_enabled('adele');
    }

    /**
     * Why an enrolment is not possible for a person - or null when it is.
     * The course's own access (open) is not judged here.
     *
     * @param \stdClass|\cm_info $cm The mod_adele course module.
     * @param int $courseid The node course.
     * @param int $userid The person.
     * @return string|null An error string identifier of mod_adele, or null.
     */
    public static function refusal($cm, int $courseid, int $userid): ?string {
        global $DB;
        $adele = $DB->get_record('adele', ['id' => $cm->instance]);
        if (!$adele) {
            return 'enrol_notallowed';
        }
        $mode = teacher_access_mode::effective((string) $adele->teachercourseaccessmode);
        if (!in_array($mode, teacher_access_mode::ENROL_MODES, true)) {
            return 'enrol_notallowed';
        }
        return self::refusal_for_mode($adele, $cm, $mode, $courseid, $userid);
    }

    /**
     * As refusal(), for a given mode (a grant's own).
     *
     * @param \stdClass $adele The activity record.
     * @param \stdClass|\cm_info $cm The course module.
     * @param string $mode The mode.
     * @param int $courseid The node course.
     * @param int $userid The person.
     * @return string|null
     */
    private static function refusal_for_mode(\stdClass $adele, $cm, string $mode, int $courseid, int $userid): ?string {
        global $DB;
        if (!self::available()) {
            return 'enrol_unavailable';
        }
        $context = context_module::instance($cm->id);
        if (!teacher_access_mode::is_authorized($adele, $mode, $context)) {
            return 'enrol_noauthority';
        }
        if (
            !has_capability('mod/adele:enrolinnodecourses', $context, $userid)
            || !(has_capability('local/adele:teacheredit', $context, $userid)
                || has_capability('local/adele:viewparticipants', $context, $userid))
        ) {
            return 'enrol_nopermission';
        }
        if (!array_key_exists($courseid, node_course_access::path_courses((int) $adele->learningpathid))) {
            return 'accessrequest_notinpath';
        }
        $roleid = teacher_access_mode::target_role($mode);
        if (!$roleid) {
            return 'enrol_norole';
        }
        $course = $DB->get_record('course', ['id' => $courseid], 'id, visible', MUST_EXIST);
        if (!$course->visible) {
            $context = context_course::instance($courseid);
            $seers = get_roles_with_capability('moodle/course:viewhiddencourses', CAP_ALLOW, $context);
            if (!array_key_exists($roleid, $seers)) {
                return 'enrol_hidden';
            }
        }
        return null;
    }

    /**
     * Enrol the current user into a node course of an activity.
     *
     * @param \stdClass|\cm_info $cm The mod_adele course module.
     * @param int $courseid The node course.
     * @return int The role given.
     * @throws moodle_exception When the enrolment is not possible.
     */
    public static function enrol($cm, int $courseid): int {
        global $DB, $USER;
        $ofpath = array_column(node_course_access::for_activity($cm), null, 'courseid');
        if (empty($ofpath[$courseid])) {
            throw new moodle_exception('accessrequest_notinpath', 'mod_adele');
        }
        if ($ofpath[$courseid]['status'] === node_course_access::STATUS_OPEN) {
            throw new moodle_exception('accessrequest_hasaccess', 'mod_adele');
        }
        if ($why = self::refusal($cm, $courseid, (int) $USER->id)) {
            throw new moodle_exception($why, 'mod_adele');
        }
        $adele = $DB->get_record('adele', ['id' => $cm->instance], '*', MUST_EXIST);
        $mode = teacher_access_mode::effective((string) $adele->teachercourseaccessmode);
        $roleid = teacher_access_mode::target_role($mode);
        $held = teacher_grants::add(
            (int) $cm->id,
            (int) $adele->learningpathid,
            $courseid,
            (int) $USER->id,
            $mode,
            $roleid
        );
        if (!$held) {
            throw new moodle_exception('enrol_failed', 'mod_adele');
        }
        \mod_adele\event\node_course_enrolled::create([
            'context' => context_module::instance($cm->id),
            'objectid' => $cm->instance,
            'relateduserid' => (int) $USER->id,
            'other' => ['courseid' => $courseid, 'roleid' => $roleid, 'mode' => $mode],
        ])->trigger();
        return $roleid;
    }

    /**
     * Whether a grant still holds.
     *
     * @param \stdClass $grant A row of enrol_adele's teacher grants.
     * @return bool
     */
    public static function holds(\stdClass $grant): bool {
        global $DB;
        $cm = get_coursemodule_from_id('adele', (int) $grant->cmid);
        if (!$cm) {
            return false;
        }
        $adele = $DB->get_record('adele', ['id' => $cm->instance]);
        if (!$adele || !$DB->record_exists('user', ['id' => $grant->userid, 'deleted' => 0])) {
            return false;
        }
        $effective = teacher_access_mode::effective((string) $adele->teachercourseaccessmode);
        if (
            !in_array($effective, teacher_access_mode::ENROL_MODES, true)
            || !teacher_access_mode::is_mode((string) $grant->mode)
            || teacher_access_mode::rank((string) $grant->mode) > teacher_access_mode::rank($effective)
        ) {
            return false;
        }
        if ((int) $grant->roleid !== teacher_access_mode::target_role((string) $grant->mode)) {
            return false;
        }
        return self::refusal_for_mode($adele, $cm, (string) $grant->mode, (int) $grant->courseid, (int) $grant->userid) === null;
    }

    /**
     * Take away the grants of one activity that no longer hold.
     *
     * @param int $cmid The course module.
     * @return int Grants taken away.
     */
    public static function reconcile_cm(int $cmid): int {
        if (!class_exists(teacher_grants::class)) {
            return 0;
        }
        $removed = 0;
        foreach (teacher_grants::get(['cmid' => $cmid]) as $grant) {
            if (!self::holds($grant)) {
                $removed += teacher_grants::remove(['id' => $grant->id]);
            }
        }
        return $removed;
    }

    /**
     * Take away every grant on the site that no longer holds - the nightly
     * safety net and the answer to a changed site setting.
     *
     * @param \progress_trace|null $trace
     * @return int Grants taken away.
     */
    public static function reconcile_all(?\progress_trace $trace = null): int {
        if (!class_exists(teacher_grants::class)) {
            return 0;
        }
        $removed = 0;
        foreach (teacher_grants::get() as $grant) {
            if (!self::holds($grant)) {
                $removed += teacher_grants::remove(['id' => $grant->id]);
            }
        }
        if ($trace) {
            $trace->output("Teacher grants taken away: {$removed}");
        }
        return $removed;
    }

    /**
     * Take away the grants of every activity that embeds a learning path,
     * that no longer hold (the path was saved or deleted).
     *
     * @param int $learningpathid
     * @return int
     */
    public static function reconcile_path(int $learningpathid): int {
        global $DB;
        $removed = 0;
        foreach ($DB->get_fieldset_select('adele', 'id', 'learningpathid = ?', [$learningpathid]) as $instanceid) {
            $cm = get_coursemodule_from_instance('adele', (int) $instanceid);
            if ($cm) {
                $removed += self::reconcile_cm((int) $cm->id);
            }
        }
        return $removed;
    }

    /**
     * A site setting of the teachers' course access changed (modes, policy,
     * roles): take away what no longer holds - queued, so the settings page
     * returns at once. The settings' updated callback (a class method, so it
     * is found without mod_adele's lib.php being loaded).
     *
     * @return void
     */
    public static function settings_changed(): void {
        \core\task\manager::queue_adhoc_task(new \mod_adele\task\reconcile_teacher_grants_adhoc(), true);
    }

    /**
     * Remove every grant of an activity (it is being deleted).
     *
     * @param int $cmid
     * @return int
     */
    public static function remove_cm(int $cmid): int {
        return class_exists(teacher_grants::class) ? teacher_grants::remove(['cmid' => $cmid]) : 0;
    }
}
