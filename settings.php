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
 * Site settings of mod_adele: how teachers may reach the node courses
 * (issue local_adele #600, TP3).
 *
 * @package    mod_adele
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_adele\local\teacher_access_mode;

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'mod_adele/teachercourseaccess',
        get_string('teachercourseaccess_heading', 'mod_adele'),
        get_string('teachercourseaccess_heading_desc', 'mod_adele')
    ));

    $modes = [];
    foreach (teacher_access_mode::ALL as $mode) {
        $modes[$mode] = get_string('teachercourseaccessmode_' . $mode, 'mod_adele');
    }

    // Existing access only is always allowed (teacher_access_mode::allowed()),
    // whether ticked here or not; out of the box it is the only mode.
    $setting = new admin_setting_configmulticheckbox(
        'mod_adele/teachercourseaccessmodes',
        get_string('teachercourseaccessmodes', 'mod_adele'),
        get_string('teachercourseaccessmodes_desc', 'mod_adele'),
        [teacher_access_mode::EXISTING_ONLY => 1],
        $modes
    );
    $setting->set_updatedcallback('mod_adele\\local\\teacher_enrolment::settings_changed');
    $settings->add($setting);

    $settings->add(new admin_setting_configselect(
        'mod_adele/teachercourseaccessdefault',
        get_string('teachercourseaccessdefault', 'mod_adele'),
        get_string('teachercourseaccessdefault_desc', 'mod_adele'),
        teacher_access_mode::EXISTING_ONLY,
        $modes
    ));

    // The teacher enrolment modes (TP4): who may choose them, and which role
    // each enrolment mode gives. A change takes away what no longer holds.
    $setting = new admin_setting_configselect(
        'mod_adele/teachercourseaccesspolicy',
        get_string('teachercourseaccesspolicy', 'mod_adele'),
        get_string('teachercourseaccesspolicy_desc', 'mod_adele'),
        teacher_access_mode::POLICY_KEEP,
        [
            teacher_access_mode::POLICY_LOCKED => get_string('teachercourseaccesspolicy_1', 'mod_adele'),
            teacher_access_mode::POLICY_KEEP => get_string('teachercourseaccesspolicy_2', 'mod_adele'),
            teacher_access_mode::POLICY_DELEGATE => get_string('teachercourseaccesspolicy_3', 'mod_adele'),
        ]
    );
    $setting->set_updatedcallback('mod_adele\\local\\teacher_enrolment::settings_changed');
    $settings->add($setting);

    $roles = role_fix_names(get_all_roles(), context_system::instance(), ROLENAME_ORIGINAL, true);
    foreach (teacher_access_mode::ROLE_ARCHETYPES as $mode => $archetype) {
        $default = get_archetype_roles($archetype);
        $default = reset($default);
        $setting = new admin_setting_configselect(
            'mod_adele/teachercourseaccessrole_' . $mode,
            get_string('teachercourseaccessrole', 'mod_adele', $modes[$mode]),
            get_string('teachercourseaccessrole_desc', 'mod_adele'),
            $default ? (int) $default->id : 0,
            $roles
        );
        $setting->set_updatedcallback('mod_adele\\local\\teacher_enrolment::settings_changed');
        $settings->add($setting);
    }

    $setting = new admin_setting_configcheckbox(
        'mod_adele/sourcesincludesuspended',
        get_string('sourcesincludesuspended', 'mod_adele'),
        get_string('sourcesincludesuspended_desc', 'mod_adele'),
        0
    );
    $setting->set_updatedcallback('mod_adele\\local\\host_policy::sources_setting_changed');
    $settings->add($setting);

    // For "Request access" (TP2): whom a teacher may ask, and what to tell them
    // when the course has nobody to ask.
    $settings->add(new admin_setting_configselect(
        'mod_adele/teachercourseaccessrequestrecipients',
        get_string('teachercourseaccessrequestrecipients', 'mod_adele'),
        get_string('teachercourseaccessrequestrecipients_desc', 'mod_adele'),
        \mod_adele\local\access_request::RECIPIENTS_ENROLERS,
        [
            \mod_adele\local\access_request::RECIPIENTS_ENROLERS =>
                get_string('teachercourseaccessrequestrecipients_enrolers', 'mod_adele'),
            \mod_adele\local\access_request::RECIPIENTS_ENROLREVIEW =>
                get_string('teachercourseaccessrequestrecipients_enrolreview', 'mod_adele'),
        ]
    ));

    $settings->add(new admin_setting_configtextarea(
        'mod_adele/teachercourseaccessrequestfallback',
        get_string('teachercourseaccessrequestfallback', 'mod_adele'),
        get_string('teachercourseaccessrequestfallback_desc', 'mod_adele'),
        '',
        PARAM_TEXT
    ));
}
