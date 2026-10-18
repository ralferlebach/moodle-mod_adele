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
 * first-embedding - story S11 (mod_adele #11).
 *
 *     a brand-new course that never had a learning path, with a member and
 *     Moodle's "send course welcome message" switched on for manual
 *     enrolments (the conditions of the original report)
 *     -> the first learning path activity is created through the form, for
 *        the course's members
 *     -> a second one, another path, in the same course
 *
 * Both paths are copies of "Linear A1" of their own (seed, S11), and the
 * course is deleted at the end: an embedding of a shared path would change
 * who else is carried (sources 2/3), and deleting it later withdraws
 * subscriptions no embedding carries. Sources 1 and 3 together are covered
 * by mod_adele's first_embedding_test.
 *     -> neither save shows an error ("Message was not sent" or any other),
 *        a PHP notice, a failed request or a browser console error, and no
 *        message is sent at all; the member is on both paths
 *
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { execFileSync } from 'node:child_process';
import { test, expect, Page } from '@playwright/test';
import { env, loginAs } from '../support/env';
import { drainTaskQueue, fixture, reseedFixtures } from '../support/fixtures';
import { embedWith, onPath, SOURCE_THIS } from '../support/host';

/**
 * A new course with one member, manual enrolment sending its welcome
 * message; the number of messages sent so far.
 *
 * @returns course id, member username, messages so far
 */
function freshCourse(): { course: string; member: string; messages: number } {
  const out = execFileSync('php', ['-r', `
    define('CLI_SCRIPT', true);
    require('config.php');
    require_once($CFG->dirroot . '/course/lib.php');
    require_once($CFG->dirroot . '/user/lib.php');
    $stamp = time() . random_int(100, 999);
    $course = create_course((object) ['shortname' => 'E2E11-' . $stamp, 'fullname' => 'E2E first embedding ' . $stamp,
        'category' => (int) $DB->get_field_sql('SELECT MIN(id) FROM {course_categories}')]);
    $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
    $DB->set_field('enrol', 'customint1', ENROL_SEND_EMAIL_FROM_NOREPLY, ['id' => $instance->id]);
    $user = (object) ['username' => 'fx_11_' . $stamp, 'firstname' => 'Fixture', 'lastname' => 'First', 'auth' => 'manual',
        'email' => 'fx11' . $stamp . '@example.invalid', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id];
    $user->id = user_create_user($user, false, false);
    enrol_get_plugin('manual')->enrol_user($instance, $user->id, (int) $DB->get_field('role', 'id', ['shortname' => 'student']));
    echo json_encode(['course' => (string) $course->id, 'member' => $user->username,
        'messages' => $DB->count_records('messages') + $DB->count_records('notifications')]);`],
  { cwd: fixture('ADELE_MOODLE_ROOT'), encoding: 'utf8' });
  return JSON.parse(out.trim().split('\n').pop() || '{}');
}

/**
 * Messages and notifications on the site.
 */
function messagesSent(): number {
  const out = execFileSync('php', ['-r', `
    define('CLI_SCRIPT', true);
    require('config.php');
    echo $DB->count_records('messages') + $DB->count_records('notifications');`],
  { cwd: fixture('ADELE_MOODLE_ROOT'), encoding: 'utf8' });
  return Number(out.trim().split('\n').pop());
}

/**
 * Watch the page for anything that went wrong: console errors, failed
 * requests, AJAX answers with an exception.
 *
 * @param page The page.
 * @returns The problems seen so far (live list).
 */
function watch(page: Page): string[] {
  const problems: string[] = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      problems.push(`console: ${msg.text()}`);
    }
  });
  page.on('pageerror', (err) => problems.push(`pageerror: ${err.message}`));
  page.on('response', async (response) => {
    if (response.status() >= 500) {
      problems.push(`HTTP ${response.status()} ${response.url()}`);
    }
    if (response.url().includes('/lib/ajax/service.php')) {
      const body = await response.text().catch(() => '');
      if (/"error":true|"exception"/.test(body)) {
        problems.push(`AJAX ${response.url()}: ${body.slice(0, 300)}`);
      }
    }
  });
  return problems;
}

/**
 * The course page shows no error and no PHP output.
 *
 * @param page The page after saving.
 */
async function expectCleanPage(page: Page): Promise<void> {
  const body = await page.locator('body').innerText();
  expect(body).not.toContain('Message was not sent');
  expect(body).not.toMatch(/(Notice|Warning|Deprecated|Fatal error):|Debug info|Stack trace/);
  await expect(page.locator('.alert-danger, .alert-error, [role="alert"].alert-warning')).toHaveCount(0);
}

/**
 * The id of a path by its name.
 *
 * @param name The path's name.
 */
function pathId(name: string): string {
  return execFileSync('php', ['-r', `
    define('CLI_SCRIPT', true);
    require('config.php');
    echo $DB->get_field('local_adele_learning_paths', 'id', ['name' => $argv[1]], MUST_EXIST);`, '--', name],
  { cwd: fixture('ADELE_MOODLE_ROOT'), encoding: 'utf8' }).trim().split('\n').pop() || '';
}

/**
 * Delete the course the chain created.
 *
 * @param course The course id.
 */
function deleteCourse(course: string): void {
  execFileSync('php', ['-r', `
    define('CLI_SCRIPT', true);
    require('config.php');
    delete_course((int) $argv[1], false);`, '--', course], { cwd: fixture('ADELE_MOODLE_ROOT'), encoding: 'utf8' });
}

let created = '';
test.beforeAll(() => reseedFixtures());
test.afterAll(() => {
  if (created) {
    deleteCourse(created);
  }
});

test.describe('ADELE-E2E-S11 — the first learning path in a course (#11)', () => {
  test('first and second embedding in a brand-new course: no error, no notice, no message; the member is carried',
    { tag: ['@story:S11'] }, async ({ page }) => {
      const fresh = freshCourse();
      created = fresh.course;
      const problems = watch(page);
      await loginAs(page, env.adminUser, env.adminPassword);

      await test.step('the first activity of the course', async () => {
        await embedWith(page, fresh.course, 'E2E erster Lernpfad', [SOURCE_THIS], undefined, 'E2E11 Erster Pfad');
        await expectCleanPage(page);
        drainTaskQueue();
      });

      await test.step('a second one in the same course', async () => {
        await embedWith(page, fresh.course, 'E2E zweiter Lernpfad', [SOURCE_THIS], undefined, 'E2E11 Zweiter Pfad');
        await expectCleanPage(page);
        drainTaskQueue();
      });

      await test.step('nothing went wrong on the way, nothing was sent', async () => {
        expect(problems, 'console errors, failed requests, AJAX exceptions').toEqual([]);
        expect(messagesSent(), 'no message and no notification').toBe(fresh.messages);
        expect(onPath(fresh.member, pathId('E2E11 Erster Pfad')), 'on the first path').toBe(true);
        expect(onPath(fresh.member, pathId('E2E11 Zweiter Pfad')), 'on the second path').toBe(true);
      });
    });
});
