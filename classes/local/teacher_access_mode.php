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
 * teachercourseaccessmode (issue local_adele #600, TP3: settings).
 *
 * The site administration decides which modes an activity may use (allowed
 * modes, existing_only always among them) and which one a new activity
 * starts with. The activity stores its own mode. What counts is the
 * effective mode: the stored one while the site allows it, otherwise
 * existing_only - a mode taken away by the site never acts, and nothing is
 * migrated to a more privileged mode.
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
     * The mode a new activity starts with: the site's default if the site
     * allows it, otherwise existing_only.
     *
     * @return string
     */
    public static function site_default(): string {
        $default = (string) get_config('mod_adele', 'teachercourseaccessdefault');
        return self::is_mode($default) && self::is_allowed($default) ? $default : self::EXISTING_ONLY;
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
     * The choices of the activity form: the allowed modes and, if the
     * activity stores a mode the site no longer allows, that one too - so
     * saving the form for another reason does not change it silently.
     *
     * @param string|null $stored The activity's stored mode (null: new activity).
     * @return string[] Mode => label.
     */
    public static function form_options(?string $stored): array {
        $options = [];
        foreach (self::ALL as $mode) {
            if (self::is_allowed($mode)) {
                $options[$mode] = get_string('teachercourseaccessmode_' . $mode, 'mod_adele');
            } else if ($mode === $stored) {
                $options[$mode] = get_string(
                    'teachercourseaccessmode_notallowed',
                    'mod_adele',
                    get_string('teachercourseaccessmode_' . $mode, 'mod_adele')
                );
            }
        }
        return $options;
    }

    /**
     * Whether a submitted value may be saved: an allowed mode, or the
     * activity's own stored mode unchanged.
     *
     * @param mixed $submitted
     * @param string|null $stored
     * @return bool
     */
    public static function may_save($submitted, ?string $stored): bool {
        if (!self::is_mode($submitted)) {
            return false;
        }
        return self::is_allowed($submitted) || $submitted === $stored;
    }
}
