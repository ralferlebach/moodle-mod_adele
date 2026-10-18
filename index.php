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
 * Display information about all the mod_adele modules in the requested course.
 *
 * @package     mod_adele
 * @copyright   2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_course_login($course);

$coursecontext = context_course::instance($course->id);

$event = \mod_adele\event\course_module_instance_list_viewed::create(['context' => $coursecontext]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$PAGE->set_url('/mod/adele/index.php', ['id' => $id]);
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($coursecontext);
$PAGE->set_pagelayout('incourse');

$adeles = get_all_instances_in_course('adele', $course);
if (empty($adeles)) {
    redirect(
        new moodle_url('/course/view.php', ['id' => $course->id]),
        get_string('noadeleinstances', 'mod_adele'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_adele'));

$usesections = course_format_uses_sections($course->format);

$table = new html_table();
$table->id = 'mod-adele-index';
$table->attributes['class'] = 'generaltable mod_index';
if ($usesections) {
    $table->head = [get_string('section'), get_string('name')];
    $table->align = ['center', 'left'];
} else {
    $table->head = [get_string('name')];
    $table->align = ['left'];
}

foreach ($adeles as $adele) {
    $link = html_writer::link(
        new moodle_url('/mod/adele/view.php', ['id' => $adele->coursemodule]),
        format_string($adele->name, true, ['context' => context_module::instance($adele->coursemodule)]),
        $adele->visible ? [] : ['class' => 'dimmed']
    );
    if ($usesections) {
        $table->data[] = [get_section_name($course, $adele->section), $link];
    } else {
        $table->data[] = [$link];
    }
}

echo html_writer::table($table);
echo $OUTPUT->footer();
