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
 * What one person may see through one ADELE activity (local_adele #12, #16, #17).
 *
 * The contract local_adele works with. Everything in it is decided here, on
 * the server, from the concrete activity (cmid) and Moodle's own group API -
 * never from what a browser sends: the effective group mode (forced course
 * mode included), the grouping, the groups the person may choose, the group
 * shown, the view (teacher, read-only teacher, learner) and the live-marker
 * settings. A group asked for that the person may not see is refused.
 *
 * @package     mod_adele
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class context_contract {
    /** @var int Group asked for: none, the default applies. */
    public const DEFAULT_GROUP = -1;

    /**
     * The contract of an activity for a person.
     *
     * The caller has checked that the person may open the activity
     * (require_login / validate_context on the module context).
     *
     * @param int $cmid The mod_adele activity.
     * @param int $userid The person.
     * @param int $requestedgroup The group asked for; DEFAULT_GROUP for the default, 0 for all participants.
     * @return array cmid, courseid, contextid, learningpathid, userlist, view, readonly,
     *     groupmode, groupingid, accessallgroups, groups (list of ['id', 'name']),
     *     owngroups (ids), groupid (shown; 0 all, -1 none), live (['enabled', 'window'])
     * @throws \invalid_parameter_exception A group the person may not see.
     */
    public static function for_cm(int $cmid, int $userid, int $requestedgroup = self::DEFAULT_GROUP): array {
        global $DB;
        $cm = get_coursemodule_from_id('adele', $cmid, 0, false, MUST_EXIST);
        $instance = $DB->get_record('adele', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $mode = self::view_for($context, $userid);

        $groupmode = (int) groups_get_activity_groupmode($cm);
        $groupingid = (int) $cm->groupingid;
        $accessall = has_capability('moodle/site:accessallgroups', $context, $userid);
        $owngroups = array_map('intval', array_keys(groups_get_all_groups($cm->course, $userid, $groupingid, 'g.id')));
        sort($owngroups);

        // The groups the person may choose: all of the grouping with
        // accessallgroups or in visible groups, otherwise their own.
        $choosable = [];
        if ($groupmode !== NOGROUPS) {
            $all = groups_get_all_groups($cm->course, 0, $groupingid, 'g.id, g.name');
            foreach ($all as $group) {
                $own = in_array((int) $group->id, $owngroups, true);
                $mayselect = $mode['view'] === 'teacher'
                    ? ($accessall || $groupmode === VISIBLEGROUPS || $own)
                    : $own;
                if ($mayselect) {
                    $choosable[] = ['id' => (int) $group->id, 'name' => format_string($group->name, true, ['context' => $context])];
                }
            }
            usort($choosable, static fn($a, $b) => [$a['name'], $a['id']] <=> [$b['name'], $b['id']]);
        }
        $choosableids = array_column($choosable, 'id');
        $mayall = $groupmode === NOGROUPS || $accessall || $groupmode === VISIBLEGROUPS;

        if ($requestedgroup === self::DEFAULT_GROUP) {
            if ($groupmode === NOGROUPS || ($mode['view'] === 'teacher' && $mayall)) {
                $groupid = 0;
            } else {
                $groupid = $choosableids ? (int) $choosableids[0] : -1;
            }
        } else if ($requestedgroup === 0 && $mode['view'] === 'teacher' && $mayall) {
            $groupid = 0;
        } else if ($groupmode !== NOGROUPS && in_array($requestedgroup, $choosableids, true)) {
            $groupid = $requestedgroup;
        } else {
            throw new \invalid_parameter_exception('This group is not one the person may see in this activity.');
        }

        return [
            'cmid' => (int) $cm->id,
            'courseid' => (int) $cm->course,
            'contextid' => (int) $context->id,
            'learningpathid' => (int) $instance->learningpathid,
            'userlist' => (int) $instance->userlist,
            'view' => $mode['view'],
            'readonly' => $mode['readonly'],
            'groupmode' => $groupmode,
            'groupingid' => $groupingid,
            'accessallgroups' => $accessall,
            'groups' => $choosable,
            'owngroups' => $owngroups,
            'groupid' => $groupid,
            'live' => live_settings::for_instance($instance),
        ];
    }

    /**
     * The view of a person in an activity (view_mode for any person).
     *
     * @param \context_module $context
     * @param int $userid
     * @return array ['view' => 'teacher'|'student'|null, 'readonly' => bool]
     */
    private static function view_for(\context_module $context, int $userid): array {
        if (has_capability('local/adele:teacheredit', $context, $userid)) {
            return ['view' => 'teacher', 'readonly' => false];
        }
        if (has_capability('local/adele:viewparticipants', $context, $userid)) {
            return ['view' => 'teacher', 'readonly' => true];
        }
        if (has_capability('mod/adele:readinstance', $context, $userid)) {
            return ['view' => 'student', 'readonly' => false];
        }
        return ['view' => null, 'readonly' => false];
    }
}
