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
 * node-enrolment-after-save - chain H5 (mod_adele #20).
 *
 *     a teacher embeds a path with the source "any course" (or "starting
 *     node") into an empty course
 *     -> only then does somebody join a node course
 *     -> they are on the path and in the host course, through enrol_adele
 *     -> a second qualifying membership keeps them in when one ends
 *     -> the last one ends: the host enrolment is suspended at once (the
 *        course closes, the record stays)
 *     -> the grace period passes: the enrolment and the subscription go
 *
 * The people join through Moodle's participants page, not a seed: the event
 * a real enrolment fires is what the chain is about.
 *
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { test, expect, Page } from '@playwright/test';
import { env, loginAs } from '../support/env';
import {
  drainTaskQueue, enrolmentState, expectCourseClosed, expectCourseClosedAfterTasks, expectCourseOpenAfterTasks,
  fixture, fixturePassword, hasServerClock, reseedFixtures, resetServerTime, setServerTime,
} from '../support/fixtures';
import {
  embedWith, enrolAsStudent, onPath, participantRow, SOURCE_ANY, SOURCE_START, unenrolMethod,
} from '../support/host';

/** The path of the chain, as the form lists it. */
const PATH = 'E2E Linear vier Schritte';

/** enrol_adele's grace period before a departure counts (remove_user_path_adhoc::DELAY_SECONDS). */
const GRACE = 300;

/**
 * The person's enrolments in a course, as "method:status" (0 active, 1 suspended).
 *
 * @param person The username.
 * @param courseid The course.
 */
function methods(person: string, courseid: string): string[] {
  return enrolmentState(person, courseid).methods.map((m) => `${m.enrol}:${m.userstatus}`).sort();
}

/**
 * Let the grace period pass on the server's test clock and run the queue;
 * then the clock goes back.
 */
function afterGracePeriod(): void {
  try {
    setServerTime(Date.now() / 1000 + GRACE + 60);
    drainTaskQueue();
  } finally {
    resetServerTime();
  }
}

/**
 * The enrolment methods a participants-page row shows for a person.
 *
 * @param page The page, logged in as administrator.
 * @param courseid The course.
 * @param name The person's full name.
 */
async function shownMethods(page: Page, courseid: string, name: string): Promise<string[]> {
  const row = await participantRow(page, courseid, name);
  await expect(row).toHaveCount(1, { timeout: 30_000 });
  return row.locator('[data-enrolinstancename]').evaluateAll(
    (els) => els.map((el) => el.getAttribute('data-enrolinstancename') || '')
  );
}

test.beforeAll(() => reseedFixtures());

test.describe('ADELE-E2E-H5 — node-course enrolments after the activity was saved (#20)', () => {
  test('"any course": joined after saving -> carried through enrol_adele; one of two memberships ends -> stays; the last ends -> suspended, after the grace period removed',
    { tag: ['@story:H5', '@withdrawal'] }, async ({ page }) => {
      test.skip(!hasServerClock(), 'the grace period needs the server test clock');
      const host = fixture('ADELE_FIXTURE_S20_HOST_ANY');
      const path = fixture('ADELE_FIXTURE_S20_PATH');
      const start = fixture('ADELE_FIXTURE_S20_START_COURSE');
      const later = fixture('ADELE_FIXTURE_S20_LATER_COURSE');
      const person = fixture('ADELE_FIXTURE_S20_ANY');
      const name = fixture('ADELE_FIXTURE_S20_ANY_NAME');
      const outsider = fixture('ADELE_FIXTURE_HOST_OUTSIDER');

      await test.step('precondition: in no course of the path, not in E2E20, not on the path', async () => {
        expect(methods(person, start)).toEqual([]);
        expect(methods(person, later)).toEqual([]);
        expect(methods(person, host)).toEqual([]);
        expect(onPath(person, path)).toBe(false);
      });

      await test.step('the path is embedded into E2E20 with the source "any course"; nobody joins yet', async () => {
        await loginAs(page, env.adminUser, env.adminPassword);
        await embedWith(page, host, 'E2E 20 jeder Kurs', [SOURCE_ANY], undefined, PATH);
        drainTaskQueue();
        expect(methods(person, host)).toEqual([]);
      });

      await test.step('after saving, they join a LATER node course: on the path, in E2E20 through enrol_adele', async () => {
        await enrolAsStudent(page, later, name);
        drainTaskQueue();
        expect(onPath(person, path), 'the enrolment after saving subscribes').toBe(true);
        expect(methods(person, host), 'only enrol_adele, no manual enrolment').toEqual(['adele:0']);
        const shown = await shownMethods(page, host, name);
        expect(shown, 'the participants page names one method').toHaveLength(1);
        expect(shown[0], 'and it is not the manual one').not.toMatch(/^Manual/);
        await loginAs(page, person, fixturePassword());
        await expectCourseOpenAfterTasks(page, host, 'a node course joined after saving carries them in');
      });

      await test.step('a second membership (the starting node); the later one ends: still in', async () => {
        await loginAs(page, env.adminUser, env.adminPassword);
        await enrolAsStudent(page, start, name);
        drainTaskQueue();
        await unenrolMethod(page, later, name, /^Manual/);
        drainTaskQueue();
        expect(methods(person, host), 'the starting node still qualifies').toEqual(['adele:0']);
        await loginAs(page, person, fixturePassword());
        await expectCourseOpenAfterTasks(page, host, 'one qualifying membership is left');
      });

      await test.step('the last membership ends: suspended at once, the course closes, the record stays', async () => {
        await loginAs(page, env.adminUser, env.adminPassword);
        await unenrolMethod(page, start, name, /^Manual/);
        drainTaskQueue();
        expect(methods(person, host), 'suspended, not deleted').toEqual(['adele:1']);
        expect(onPath(person, path), 'the subscription waits for the grace period').toBe(true);
        await loginAs(page, person, fixturePassword());
        await expectCourseClosed(page, host, 'no qualifying membership is left');
      });

      await test.step('the grace period passes: enrolment and subscription go', async () => {
        afterGracePeriod();
        expect(methods(person, host), 'removed once the departure is confirmed').toEqual([]);
        expect(onPath(person, path)).toBe(false);
      });

      await test.step('the outsider, in no course of the path, was never carried', async () => {
        expect(methods(outsider, host)).toEqual([]);
        await loginAs(page, outsider, fixturePassword());
        await expectCourseClosed(page, host, 'in no course of the path');
      });
    });

  test('"starting node": a later node alone carries nothing; the starting node joined after saving does; left and back within the grace period -> active again, kept',
    { tag: ['@story:H5', '@withdrawal'] }, async ({ page }) => {
      const host = fixture('ADELE_FIXTURE_S20_HOST_START');
      const path = fixture('ADELE_FIXTURE_S20_PATH');
      const start = fixture('ADELE_FIXTURE_S20_START_COURSE');
      const later = fixture('ADELE_FIXTURE_S20_LATER_COURSE');
      const person = fixture('ADELE_FIXTURE_S20_START');
      const name = fixture('ADELE_FIXTURE_S20_START_NAME');

      await test.step('the path is embedded into E2E20S with the source "starting node"', async () => {
        await loginAs(page, env.adminUser, env.adminPassword);
        await embedWith(page, host, 'E2E 20 Startknoten', [SOURCE_START], undefined, PATH);
        drainTaskQueue();
        expect(methods(person, host)).toEqual([]);
      });

      await test.step('a later node course alone: not in E2E20S', async () => {
        // Not asked whether they are on the path: the first chain embedded
        // the same path into E2E20 for "any course", and that embedding
        // rightly subscribes them. What this embedding decides is E2E20S.
        await enrolAsStudent(page, later, name);
        drainTaskQueue();
        expect(methods(person, host), 'a later node is no starting node').toEqual([]);
        await loginAs(page, person, fixturePassword());
        await expectCourseClosed(page, host, 'a later node is no starting node');
        await loginAs(page, env.adminUser, env.adminPassword);
      });

      await test.step('the starting node joined after saving: on the path, in E2E20S through enrol_adele', async () => {
        await enrolAsStudent(page, start, name);
        drainTaskQueue();
        expect(onPath(person, path)).toBe(true);
        expect(methods(person, host)).toEqual(['adele:0']);
        await loginAs(page, person, fixturePassword());
        await expectCourseOpenAfterTasks(page, host, 'the starting node carries them in');
      });

      await test.step('left: suspended at once; back within the grace period: active again, nothing removed later', async () => {
        await loginAs(page, env.adminUser, env.adminPassword);
        await unenrolMethod(page, start, name, /^Manual/);
        drainTaskQueue();
        expect(methods(person, host)).toEqual(['adele:1']);
        await loginAs(page, person, fixturePassword());
        await expectCourseClosedAfterTasks(page, host, 'the starting node membership ended');

        await loginAs(page, env.adminUser, env.adminPassword);
        await enrolAsStudent(page, start, name);
        drainTaskQueue();
        expect(methods(person, host), 'active again, one enrolment').toEqual(['adele:0']);
        if (hasServerClock()) {
          afterGracePeriod();
          expect(methods(person, host), 'the deferred removal finds them carried and keeps it').toEqual(['adele:0']);
          expect(onPath(person, path)).toBe(true);
        }
        await loginAs(page, person, fixturePassword());
        await expectCourseOpenAfterTasks(page, host, 'back in the starting node');
      });
    });
});
