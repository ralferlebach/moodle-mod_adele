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
 * host-course-participants - chain H1 of the E2E plan (section 16).
 *
 *     a teacher embeds a learning path into a course
 *     -> the course's participants are taken into the entry course
 *     -> a participant can actually open that course
 *     -> somebody outside the host course is not
 *
 * The activity is created THROUGH THE INTERFACE here, not by a seed. Creating
 * it in PHP would skip the very authoring step this chain is about and would
 * still produce enrolments, so the test would pass while the form was broken.
 *
 * The host course (E2EHOST) comes from the fixtures with two members and one
 * deliberate outsider, because an effect that reaches everybody proves
 * nothing (plan section 2 G).
 *
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { test, expect, Page } from '@playwright/test';
import { env, loginAs } from '../support/env';
import {
  drainTaskQueue,
  expectCourseClosed,
  expectCourseOpenAfterTasks,
  fixture,
  fixtureCourse,
  fixturePassword,
  referencePath,
} from '../support/fixtures';

/**
 * Pick one entry in a Moodle autocomplete field.
 *
 * @param page The page showing the form.
 * @param label The field's label.
 * @param entry The exact text of the entry to pick.
 */
async function chooseInAutocomplete(page: Page, label: string, entry: string): Promise<void> {
  const input = page.getByRole('combobox', { name: label });
  await input.click();
  await input.fill(entry);
  await page.getByRole('option', { name: entry, exact: true }).first().click();
}

test.describe('ADELE-E2E-H1 — embedding a path into a course carries its participants', () => {
  test('the participants of the host course reach the entry course, outsiders do not', async ({ page }) => {
    const hostcourse = fixture('ADELE_FIXTURE_HOST_COURSE');
    const member = fixture('ADELE_FIXTURE_HOST_MEMBER_1');
    const outsider = fixture('ADELE_FIXTURE_HOST_OUTSIDER');
    const entrycourse = fixtureCourse('T01');
    const activityname = 'E2E Lernpfad ' + Date.now();

    await test.step('precondition: neither member nor outsider is in the entry course', async () => {
      await loginAs(page, member, fixturePassword());
      await expectCourseClosed(page, entrycourse, 'the member has not been carried anywhere yet');
      await loginAs(page, outsider, fixturePassword());
      await expectCourseClosed(page, entrycourse, 'the outsider must never be carried');
    });

    await test.step('a teacher embeds the reference path into the host course', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);

      // Straight to the activity form rather than through the chooser: the
      // chooser is core's UI and is covered by core's own tests, while the
      // form below is mod_adele's.
      await page.goto(`/course/modedit.php?add=adele&course=${hostcourse}&section=0`);

      // #id_name, not the label: mod_adele calls both the activity name and
      // the path selector "Learning path", so the label is ambiguous. A
      // Moodle form id is the documented stable handle and the last step the
      // plan's selector contract allows (section 21).
      await page.locator('#id_name').fill(activityname);

      // Both selectors are Moodle autocompletes: the <select> behind them is
      // hidden, so they are driven the way a person drives them - type, then
      // pick the entry by its name, never by position.
      await chooseInAutocomplete(page, 'Chosen Learning Path', referencePath.name);
      await chooseInAutocomplete(page, 'Learning path enrolment', 'for people enrolled in this course');

      await page.getByRole('button', { name: /Save and return to course|Speichern und zum Kurs/i })
        .click();

      await expect(page.getByRole('link', { name: activityname })).toBeVisible();
    });

    await test.step('the host member is carried into the entry course', async () => {
      await loginAs(page, member, fixturePassword());
      await expectCourseOpenAfterTasks(
        page,
        entrycourse,
        'the member of the host course must be carried into the entry course of the embedded path'
      );
    });

    await test.step('the outsider is still refused', async () => {
      // The queue has been run to completion above, so this is the final
      // state and not a race the outsider happens to win.
      drainTaskQueue();
      await loginAs(page, outsider, fixturePassword());
      await expectCourseClosed(
        page,
        entrycourse,
        'embedding must not carry anybody who is not in the host course'
      );
    });

    await test.step('the member sees the embedded path in the activity', async () => {
      await loginAs(page, member, fixturePassword());
      await page.goto(`/course/view.php?id=${hostcourse}`);
      await page.getByRole('link', { name: activityname }).click();
      // The learning path itself renders, with its nodes carrying the
      // machine-readable state from #574.
      await expect(page.locator('[data-testid^="learningpath-node-"]').first())
        .toBeVisible({ timeout: 60_000 });
    });
  });
});
