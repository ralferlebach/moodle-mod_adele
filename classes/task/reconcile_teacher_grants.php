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

namespace mod_adele\task;

/**
 * Nightly: take away the teachers' node course enrolments whose basis is gone
 * (local_adele #600, TP4) - a changed role or capability, a course left, a
 * path changed. Idempotent.
 *
 * @package    mod_adele
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile_teacher_grants extends \core\task\scheduled_task {
    /**
     * Name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_reconcile_teacher_grants', 'mod_adele');
    }

    /**
     * Run.
     *
     * @return void
     */
    public function execute(): void {
        \mod_adele\local\teacher_enrolment::reconcile_all(new \text_progress_trace());
    }
}
