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

/**
 * How teachers reach the node courses they cannot open yet - the setting
 * teachercourseaccessmode (issue local_adele #600, TP3 and TP4).
 *
 * The site administration decides which modes an activity may use (allowed
 * modes, existing_only always among them) and which one a new activity
 * starts with. The activity stores its own mode. What counts is the
 * effective mode: the stored one while the site allows it, otherwise
 * existing_only - a mode taken away by the site never acts, and nothing is
 * migrated to a more privileged mode.
 *
 * The two teacher enrolment modes (enrol_teacher, enrol_editingteacher)
 * need authority, under the site's policy (setting teachercourseaccesspolicy):
 *
 *  - who may assign the mode's role in EVERY node course of the path
 *    (moodle/role:assign and the role among the assignable ones there) may
 *    always choose it - under every policy;
 *  - policy 1 (locked): anybody else sees the option but cannot choose it,
 *    and cannot change a stored teacher mode at all;
 *  - policy 2 (keep and lower, the default): anybody else may keep a stored
 *    teacher mode or lower it, never raise it, and is not offered it when
 *    none is stored;
 *  - policy 3 (delegation): who holds mod/adele:delegatecourseaccess may
 *    choose it too; for anybody else as policy 2.
 *
 * Who chose a teacher mode with authority is stored (grantor). The mode gives
 * enrolments only while that person still has the authority: keeping the
 * stored value protects the value, never the right to enrol (the issue's
 * proposed semantics, section 4.4).
 *
 * The order is the issue's rank (section 4.4): existing_only < request <
 * enrol_student < enrol_teacher < enrol_editingteacher.
 *
 * @package    mod_adele
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teacher_access_mode {
    /** @var string Only courses the person can already open get a link. */
    public const EXISTING_ONLY = 'existing_only';

    /** @var string The person may ask for access. */
    public const REQUEST = 'request';

    /** @var string The person may enrol as a participant. */
    public const ENROL_STUDENT = 'enrol_student';

    /** @var string The person may enrol as a teacher without editing rights. */
    public const ENROL_TEACHER = 'enrol_teacher';

    /** @var string The person may enrol as a teacher with editing rights. */
    public const ENROL_EDITINGTEACHER = 'enrol_editingteacher';

    /** @var string[] All modes, least privileged first. */
    public const ALL = [
        self::EXISTING_ONLY,
        self::REQUEST,
        self::ENROL_STUDENT,
        self::ENROL_TEACHER,
        self::ENROL_EDITINGTEACHER,
    ];

    /** @var string[] The modes that enrol. */
    public const ENROL_MODES = [self::ENROL_STUDENT, self::ENROL_TEACHER, self::ENROL_EDITINGTEACHER];

    /** @var string[] The modes that need authority (section 4.4). */
    public const TEACHER_MODES = [self::ENROL_TEACHER, self::ENROL_EDITINGTEACHER];

    /** @var int Policy 1: shown, but locked for anybody without authority. */
    public const POLICY_LOCKED = 1;

    /** @var int Policy 2: a stored teacher mode may be kept or lowered (default). */
    public const POLICY_KEEP = 2;

    /** @var int Policy 3: the site delegates (mod/adele:delegatecourseaccess). */
    public const POLICY_DELEGATE = 3;

    /** @var string[] The archetype each enrolment mode's role defaults to. */
    public const ROLE_ARCHETYPES = [
        self::ENROL_STUDENT => 'student',
        self::ENROL_TEACHER => 'teacher',
        self::ENROL_EDITINGTEACHER => 'editingteacher',
    ];

    /**
     * Whether a value is a mode at all.
     *
     * @param mixed $mode
     * @return bool
     */
    public static function is_mode($mode): bool {
        return is_string($mode) && in_array($mode, self::ALL, true);
    }

    /**
     * The rank of a mode (0 = existing_only).
     *
     * @param string $mode
     * @return int
     */
    public static function rank(string $mode): int {
        $rank = array_search($mode, self::ALL, true);
        return $rank === false ? 0 : (int) $rank;
    }

    /**
     * Whether a mode needs authority.
     *
     * @param string|null $mode
     * @return bool
     */
    public static function is_teacher_mode(?string $mode): bool {
        return $mode !== null && in_array($mode, self::TEACHER_MODES, true);
    }

    /**
     * The modes the site allows, least privileged first; existing_only always.
     *
     * @return string[]
     */
    public static function allowed(): array {
        $configured = (string) get_config('mod_adele', 'teachercourseaccessmodes');
        $chosen = array_filter(array_map('trim', explode(',', $configured)));
        $allowed = [self::EXISTING_ONLY];
        foreach (self::ALL as $mode) {
            if ($mode !== self::EXISTING_ONLY && in_array($mode, $chosen, true)) {
                $allowed[] = $mode;
            }
        }
        return $allowed;
    }

    /**
     * Whether the site allows a mode.
     *
     * @param string $mode
     * @return bool
     */
    public static function is_allowed(string $mode): bool {
        return in_array($mode, self::allowed(), true);
    }

    /**
     * The site's policy for the teacher enrolment modes.
     *
     * @return int
     */
    public static function policy(): int {
        $policy = (int) get_config('mod_adele', 'teachercourseaccesspolicy');
        return in_array($policy, [self::POLICY_LOCKED, self::POLICY_KEEP, self::POLICY_DELEGATE], true)
            ? $policy
            : self::POLICY_KEEP;
    }

    /**
     * The role an enrolment mode gives, as the site configured it - or 0.
     *
     * @param string $mode
     * @return int
     */
    public static function target_role(string $mode): int {
        global $DB;
        if (!isset(self::ROLE_ARCHETYPES[$mode])) {
            return 0;
        }
        $roleid = (int) get_config('mod_adele', 'teachercourseaccessrole_' . $mode);
        if ($roleid && $DB->record_exists('role', ['id' => $roleid])) {
            return $roleid;
        }
        $roles = get_archetype_roles(self::ROLE_ARCHETYPES[$mode]);
        $role = reset($roles);
        return $role ? (int) $role->id : 0;
    }

    /**
     * The mode a new activity starts with: the site's default if the site
     * allows it, otherwise existing_only. A teacher mode needs authority of
     * the person creating the activity, which a default cannot have.
     *
     * @return string
     */
    public static function site_default(): string {
        $default = (string) get_config('mod_adele', 'teachercourseaccessdefault');
        return self::is_mode($default) && self::is_allowed($default) && !self::is_teacher_mode($default)
            ? $default
            : self::EXISTING_ONLY;
    }

    /**
     * The mode that acts: the stored one while the site allows it.
     *
     * @param string|null $stored The activity's stored mode.
     * @return string
     */
    public static function effective(?string $stored): string {
        return $stored !== null && self::is_mode($stored) && self::is_allowed($stored) ? $stored : self::EXISTING_ONLY;
    }

    /**
     * The effective mode of an activity.
     *
     * @param int $instanceid The mod_adele instance.
     * @return string
     */
    public static function for_instance(int $instanceid): string {
        global $DB;
        $stored = $DB->get_field('adele', 'teachercourseaccessmode', ['id' => $instanceid]);
        return self::effective($stored === false ? null : (string) $stored);
    }

    /**
     * Whether a person may assign the role of a teacher mode in every node
     * course of a path - the authority that counts under every policy.
     *
     * @param string $mode A teacher mode.
     * @param int $learningpathid The path.
     * @param int $userid The person.
     * @return bool
     */
    public static function has_rights_in_all_courses(string $mode, int $learningpathid, int $userid): bool {
        $roleid = self::target_role($mode);
        if (!$roleid) {
            return false;
        }
        foreach (array_keys(node_course_access::path_courses($learningpathid)) as $courseid) {
            $context = \context_course::instance($courseid);
            if (!has_capability('moodle/role:assign', $context, $userid)) {
                return false;
            }
            if (!array_key_exists($roleid, get_assignable_roles($context, ROLENAME_ORIGINAL, false, $userid))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a person has authority for a mode in an activity (or, for a new
     * activity, in its course).
     *
     * @param string $mode
     * @param int $learningpathid The path the activity embeds (or is to embed).
     * @param int $userid The person.
     * @param \context $context The module context, or the course context for a new activity.
     * @return bool
     */
    public static function may_configure(string $mode, int $learningpathid, int $userid, \context $context): bool {
        if (!self::is_teacher_mode($mode)) {
            return true;
        }
        if (self::policy() === self::POLICY_DELEGATE && has_capability('mod/adele:delegatecourseaccess', $context, $userid)) {
            return true;
        }
        return self::has_rights_in_all_courses($mode, $learningpathid, $userid);
    }

    /**
     * The choices of the activity form, each with whether it can be chosen.
     *
     * Offered: the allowed modes and, if the activity stores a mode the site
     * no longer allows, that one too - so that saving the form for another
     * reason does not change it silently. A teacher mode without authority:
     * policy 1 shows it locked; policies 2 and 3 offer it only as the stored
     * mode or below it.
     *
     * @param string|null $stored The activity's stored mode (null: new activity).
     * @param int $learningpathid The path.
     * @param int $userid The person editing.
     * @param \context $context Module context, or course context for a new activity.
     * @return array Mode => ['label' => string, 'disabled' => bool].
     */
    public static function form_options(?string $stored, int $learningpathid, int $userid, \context $context): array {
        $options = [];
        $locked = self::policy() === self::POLICY_LOCKED;
        foreach (self::ALL as $mode) {
            $label = get_string('teachercourseaccessmode_' . $mode, 'mod_adele');
            if (!self::is_allowed($mode)) {
                if ($mode === $stored) {
                    $options[$mode] = [
                        'label' => get_string('teachercourseaccessmode_notallowed', 'mod_adele', $label),
                        'disabled' => false,
                    ];
                }
                continue;
            }
            if (!self::is_teacher_mode($mode) || self::may_configure($mode, $learningpathid, $userid, $context)) {
                $options[$mode] = ['label' => $label, 'disabled' => false];
            } else if ($mode === $stored) {
                $options[$mode] = ['label' => $label, 'disabled' => false];
            } else if ($locked) {
                $options[$mode] = [
                    'label' => get_string('teachercourseaccessmode_locked', 'mod_adele', $label),
                    'disabled' => true,
                ];
            } else if (self::is_teacher_mode($stored) && self::rank($mode) < self::rank($stored)) {
                $options[$mode] = ['label' => $label, 'disabled' => false];
            }
        }
        return $options;
    }

    /**
     * Whether the whole choice is locked for a person: policy 1, a stored
     * teacher mode, and no authority for it.
     *
     * @param string|null $stored
     * @param int $learningpathid
     * @param int $userid
     * @param \context $context
     * @return bool
     */
    public static function is_locked(?string $stored, int $learningpathid, int $userid, \context $context): bool {
        return self::policy() === self::POLICY_LOCKED
            && self::is_teacher_mode($stored)
            && !self::may_configure($stored, $learningpathid, $userid, $context);
    }

    /**
     * Whether a submitted value may be saved.
     *
     * Without authority context (null person): an allowed non-teacher mode, or
     * the stored mode unchanged. With it: also the teacher modes as the policy
     * says - chosen with authority, kept, or (policies 2 and 3) lowered.
     *
     * @param mixed $submitted
     * @param string|null $stored
     * @param int $learningpathid The path the activity is to embed.
     * @param int|null $userid The person saving; null for a save without a person (e.g. a generator).
     * @param \context|null $context Module context, or course context for a new activity.
     * @return bool
     */
    public static function may_save(
        $submitted,
        ?string $stored,
        int $learningpathid = 0,
        ?int $userid = null,
        ?\context $context = null
    ): bool {
        if (!self::is_mode($submitted)) {
            return false;
        }
        if ($submitted === $stored) {
            return true;
        }
        if (!self::is_allowed($submitted)) {
            return false;
        }
        if ($userid === null || $context === null) {
            return !self::is_teacher_mode($submitted) && !self::is_teacher_mode($stored);
        }
        if (self::is_locked($stored, $learningpathid, $userid, $context)) {
            return false;
        }
        if (!self::is_teacher_mode($submitted)) {
            return true;
        }
        if (self::may_configure($submitted, $learningpathid, $userid, $context)) {
            return true;
        }
        return self::policy() !== self::POLICY_LOCKED
            && self::is_teacher_mode($stored)
            && self::rank($submitted) < self::rank($stored);
    }

    /**
     * Whether an activity's teacher mode still has its authority: the person
     * who chose it may still configure it.
     *
     * @param \stdClass $adele The activity record (teachercourseaccessgrantor, learningpathid).
     * @param string $mode The mode in question.
     * @param \context $context The module context.
     * @return bool
     */
    public static function is_authorized(\stdClass $adele, string $mode, \context $context): bool {
        global $DB;
        if (!self::is_teacher_mode($mode)) {
            return true;
        }
        $grantor = (int) ($adele->teachercourseaccessgrantor ?? 0);
        if (!$grantor || !$DB->record_exists('user', ['id' => $grantor, 'deleted' => 0, 'suspended' => 0])) {
            return false;
        }
        return self::may_configure($mode, (int) $adele->learningpathid, $grantor, $context);
    }
}
