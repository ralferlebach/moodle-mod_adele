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
 * Web services of mod_adele.
 *
 * @package    mod_adele
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_adele_get_node_course_access' => [
        'classname' => 'mod_adele\external\get_node_course_access',
        'description' => 'The node courses of an embedding and whether the current teacher can open each of them.',
        'type' => 'read',
        'ajax' => true,
        // Decided in the function: the teacher view of this activity
        // (view_mode), with or without local/adele:teacheredit.
        'capabilities' => '',
    ],
    'mod_adele_get_access_request' => [
        'classname' => 'mod_adele\external\get_access_request',
        'description' => 'Recipients and prefilled message for a request for access to a node course.',
        'type' => 'read',
        'ajax' => true,
        // Decided in the function: teacher view, mode "request", course of the path, no access yet.
        'capabilities' => '',
    ],
    'mod_adele_send_access_request' => [
        'classname' => 'mod_adele\external\send_access_request',
        'description' => 'Send a teacher\'s request for access to a node course to one of its responsible people.',
        'type' => 'write',
        'ajax' => true,
        // Decided in the function, as for mod_adele_get_access_request, plus the recipient.
        'capabilities' => '',
    ],
    'mod_adele_enrol_in_node_course' => [
        'classname' => 'mod_adele\external\enrol_in_node_course',
        'description' => 'Enrol oneself into a node course from the teacher view, as the activity\'s mode allows.',
        'type' => 'write',
        'ajax' => true,
        // Decided in the function: teacher view, mod/adele:enrolinnodecourses, mode, authority, course, role.
        'capabilities' => 'mod/adele:enrolinnodecourses',
    ],
];
