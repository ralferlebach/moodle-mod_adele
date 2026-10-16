<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin version and other meta-data are defined here.
 *
 * @package     mod_adele
 * @copyright   2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_adele';
$plugin->supported = [405, 405];
$plugin->release = '0.5.0';
$plugin->version = 2026100727;
$plugin->requires = 2024100700;
$plugin->maturity = MATURITY_ALPHA;
// The floors must demand the siblings that actually speak host_policy: an
// older local_adele still reads the abandoned host-course mirror table -
// installs cleanly, never fatals, silently reconciles from a stale index.
$plugin->dependencies = [
    // 2026100707: local/adele:viewparticipants, which decides about the teacher's view (#39).
    // 2026100708: enrol_state::is_participant(), who takes part in a course (local_adele #587).
    // 2026100713: ... decided by the site's graded roles (local_adele #593).
    'local_adele' => 2026100713,
    'enrol_adele' => 2026082903,
];
