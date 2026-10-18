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

namespace mod_adele;

use advanced_testcase;
use context_course;

/**
 * No enrol_manual fallback (decision 2026-10-17, mod_adele #20): enrol_adele
 * is a required companion, and every enrolment ADELE causes - into a host
 * course (sources 2/3) or a node course - goes through it. Without an active
 * enrol_adele nothing is enrolled at all, by no other method, and a warning
 * says so.
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_adele_observer
 * @covers     \local_adele\enrol_state::warn_enrol_adele_missing
 */
final class no_manual_fallback_test extends advanced_testcase {
    /**
     * Production code of local_adele and mod_adele never enrols through
     * enrol_manual.
     *
     * @return void
     */
    public function test_no_production_code_enrols_through_enrol_manual(): void {
        global $CFG;
        $found = [];
        foreach (['local/adele', 'mod/adele'] as $plugin) {
            $root = $CFG->dirroot . '/' . $plugin;
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = substr($file->getPathname(), strlen($root) + 1);
                if ($file->getExtension() !== 'php' || preg_match('~^(tests|vue3|node_modules)/~', $path)) {
                    continue;
                }
                if (preg_match('~enrol_get_plugin\(\s*[\'"]manual[\'"]\s*\)~', file_get_contents($file->getPathname()))) {
                    $found[] = "$plugin/$path";
                }
            }
        }
        $this->assertSame([], $found, 'No enrol_manual path left.');
    }

    /**
     * enrol_adele not active: a learner of a starting node course is
     * subscribed (the path is local_adele's), but enrolled into the host
     * course by no method at all; a warning names the cause.
     *
     * @return void
     */
    public function test_without_enrol_adele_nobody_is_enrolled_by_another_method(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $node = $gen->create_course();
        $host = $gen->create_course();
        $nodes = [['id' => 'dndnode_1', 'type' => 'custom', 'parentCourse' => ['starting_node'],
            'data' => ['course_node_id' => [(int) $node->id]]]];
        $lpid = (int) $DB->insert_record('local_adele_learning_paths', (object) [
            'name' => 'No fallback', 'description' => '', 'timecreated' => time(), 'timemodified' => time(),
            'createdby' => 2, 'json' => json_encode(['tree' => ['nodes' => $nodes, 'edges' => []]]),
        ]);
        $enabled = array_diff(explode(',', (string) get_config('core', 'enrol_plugins_enabled')), ['adele']);
        set_config('enrol_plugins_enabled', implode(',', $enabled));

        $gen->create_module('adele', [
            'course' => $host->id, 'learningpathid' => $lpid, 'participantslist' => [2], 'hostenrolmentmode' => 'visible',
        ]);
        $learner = $gen->create_user();
        $gen->enrol_user($learner->id, $node->id, 'student');
        $this->runAdhocTasks();
        $this->resetDebugging();

        $this->assertFalse($DB->record_exists_sql(
            "SELECT 1 FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid WHERE ue.userid = ? AND e.courseid = ?",
            [$learner->id, $host->id]
        ), 'No enrolment into the host course, by no method.');
        $this->assertFalse(is_enrolled(context_course::instance($host->id), $learner));

        \local_adele\enrol_state::warn_enrol_adele_missing();
        $this->assertDebuggingCalled();
    }
}
