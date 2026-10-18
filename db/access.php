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
 * Capability definitions for mod_adele.
 *
 * @package    mod_adele
 * @category   access
 * @copyright  2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Ability to add learning path to the course.
    'mod/adele:addinstance' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
            ],
    ],
    // Ability to see the learning path you have been assigned.
    'mod/adele:readinstance' => [
      'riskbitmask' => RISK_XSS,
      'captype' => 'view',
      'contextlevel' => CONTEXT_COURSE,
      'archetypes' => [
          'user' => CAP_ALLOW,
      ],
    ],
    // Enrol oneself into a node course from the teacher view, where the
    // activity's mode offers it (local_adele #600, TP4).
    'mod/adele:enrolinnodecourses' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
    // Under the site policy "delegation": choose a teacher enrolment mode for
    // an activity without rights in all of its node courses (local_adele #600).
    'mod/adele:delegatecourseaccess' => [
        'riskbitmask' => RISK_CONFIG | RISK_MANAGETRUST,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
];
