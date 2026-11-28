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

/**
 * GDPR Provider
 *
 *
 * @package    mod_adele
 * @copyright  2024 Wunderbyte GmbH
 * @author     Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/

namespace mod_adele\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider of mod_adele.
 *
 * The activity keeps one personal datum: who chose its teacher access mode
 * (adele.teachercourseaccessgrantor), the person whose authority the mode
 * rests on. Deleting it anonymises the field; the mode then has no authority
 * left - the same as when that account is deleted or suspended - and the
 * teachers' node course enrolments it gave go until somebody with authority
 * saves the activity again (decision 2026-10-17, mod_adele #33).
 *
 * @package    mod_adele
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * The data stored.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('adele', [
            'teachercourseaccessgrantor' => 'privacy:metadata:adele:teachercourseaccessgrantor',
            'teachercourseaccessmode' => 'privacy:metadata:adele:teachercourseaccessmode',
        ], 'privacy:metadata:adele');
        return $collection;
    }

    /**
     * The activities whose teacher access mode the person chose.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $list = new contextlist();
        $list->add_from_sql(
            "SELECT ctx.id
               FROM {adele} a
               JOIN {modules} m ON m.name = 'adele'
               JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = m.id
               JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :modulelevel
              WHERE a.teachercourseaccessgrantor = :userid",
            ['modulelevel' => CONTEXT_MODULE, 'userid' => $userid]
        );
        return $list;
    }

    /**
     * The person who chose the mode of an activity.
     *
     * @param userlist $userlist
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        $userlist->add_from_sql(
            'userid',
            "SELECT a.teachercourseaccessgrantor AS userid
               FROM {adele} a
               JOIN {modules} m ON m.name = 'adele'
               JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = m.id
              WHERE cm.id = :cmid AND a.teachercourseaccessgrantor > 0",
            ['cmid' => $context->instanceid]
        );
    }

    /**
     * Export: which mode the person chose for which activity.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $adele = self::activity($context);
            if ($adele && (int) $adele->teachercourseaccessgrantor === $userid) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:metadata:adele', 'mod_adele')],
                    (object) ['name' => format_string($adele->name), 'teachercourseaccessmode' => $adele->teachercourseaccessmode]
                );
            }
        }
    }

    /**
     * Anonymise the grantor of an activity.
     *
     * @param \context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        $adele = self::activity($context);
        if ($adele && (int) $adele->teachercourseaccessgrantor) {
            self::anonymise($adele, (int) $context->instanceid);
        }
    }

    /**
     * Anonymise the person as grantor in the given activities.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $adele = self::activity($context);
            if ($adele && (int) $adele->teachercourseaccessgrantor === $userid) {
                self::anonymise($adele, (int) $context->instanceid);
            }
        }
    }

    /**
     * Anonymise the given people as grantor of one activity.
     *
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        $adele = self::activity($context);
        if ($adele && in_array((int) $adele->teachercourseaccessgrantor, array_map('intval', $userlist->get_userids()), true)) {
            self::anonymise($adele, (int) $context->instanceid);
        }
    }

    /**
     * The activity of a module context, or null.
     *
     * @param \context $context
     * @return \stdClass|null
     */
    private static function activity(\context $context): ?\stdClass {
        global $DB;
        if ($context->contextlevel != CONTEXT_MODULE) {
            return null;
        }
        $cm = get_coursemodule_from_id('adele', $context->instanceid);
        if (!$cm) {
            return null;
        }
        return $DB->get_record('adele', ['id' => $cm->instance]) ?: null;
    }

    /**
     * Set the grantor to nobody; the teachers' enrolments the mode gave
     * follow at once.
     *
     * @param \stdClass $adele
     * @param int $cmid
     * @return void
     */
    private static function anonymise(\stdClass $adele, int $cmid): void {
        global $DB;
        $DB->set_field('adele', 'teachercourseaccessgrantor', 0, ['id' => $adele->id]);
        \mod_adele\local\teacher_enrolment::reconcile_cm($cmid);
    }
}
