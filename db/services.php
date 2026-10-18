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
];
