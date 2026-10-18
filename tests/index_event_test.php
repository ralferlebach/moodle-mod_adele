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
 * The event of the activity list (index.php, mod_adele #31): it exists, is
 * logged in the course's context and names index.php of that course.
 *
 * @package    mod_adele
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_adele\event\course_module_instance_list_viewed
 */
final class index_event_test extends advanced_testcase {
    /**
     * Triggered as index.php does: in the course context, with the course.
     *
     * @return void
     */
    public function test_the_list_event_is_logged_for_the_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $sink = $this->redirectEvents();
        $event = event\course_module_instance_list_viewed::create(['context' => context_course::instance($course->id)]);
        $event->add_record_snapshot('course', $course);
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(event\course_module_instance_list_viewed::class, $events[0]);
        $this->assertSame((int) $course->id, (int) $events[0]->courseid);
        $this->assertSame(CONTEXT_COURSE, (int) $events[0]->get_context()->contextlevel);
        $this->assertSame('/mod/adele/index.php?id=' . $course->id, $events[0]->get_url()->out_as_local_url(false));
        $this->assertDebuggingNotCalled();
    }
}
