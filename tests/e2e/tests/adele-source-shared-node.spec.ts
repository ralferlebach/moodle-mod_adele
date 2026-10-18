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
 * adele-source-shared-node - an enrolment ADELE made for one path is no source
 * for another path that shares the course: sources 2 and 3 (mod_adele #37,
 * acceptance criterion 3; master issue #43, P0.5).
 *
 * Fixture S37: "E2E Linear vier Schritte" is embedded into E2E37A for its
 * members; fx_37_learner is a member of E2E37A only, so enrol_adele has
 * enrolled them into T01, the starting node - an enrolment of ADELE's own.
 * The chain embeds "Linear A2" (starting node T01) into E2E37B with the
 * sources "starting node" and "any course of the path".
 *
 *   H4  live, after the nightly sweep: not on Linear A2, E2E37B closed.
 *       Control: an enrolment of one's own in T01 carries them in.
 *       Taken back: E2E37B closes again (the ADELE enrolment in T01 stays and
 *       still carries nothing).
 *
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { test, expect } from '@playwright/test';
import { env, loginAs } from '../support/env';
import {
  drainTaskQueue, enrolmentState, expectCourseClosed, expectCourseClosedAfterTasks, expectCourseOpenAfterTasks,
  fixture, fixturePassword, reseedFixtures,
} from '../support/fixtures';
import { embedWith, enrolAsStudent, nightlySweep, onPath, SOURCE_ANY, SOURCE_START, unenrolMethod } from '../support/host';

test.beforeAll(() => reseedFixtures());

test.describe('ADELE-E2E-H4 — an enrolment ADELE made is no source 2 or 3 for another path (#37)', () => {
  test('through the shared T01 the learner reaches neither Linear A2 nor its host course; an own enrolment does', { tag: ['@story:H4', '@withdrawal'] }, async ({ page }) => {
    const learner = fixture('ADELE_FIXTURE_S37_LEARNER');
    const name = fixture('ADELE_FIXTURE_S37_LEARNER_NAME');
    const hostb = fixture('ADELE_FIXTURE_S37_HOST_B');
    const t01 = fixture('ADELE_FIXTURE_S37_T01');
    const pathb = fixture('ADELE_FIXTURE_S37_PATH_B');

    await test.step('precondition: in T01 only through ADELE (path A), not on Linear A2', async () => {
      const state = enrolmentState(learner, t01);
      expect(state.methods.map((m) => `${m.enrol}:${m.userstatus}`)).toEqual(['adele:0']);
      expect(onPath(learner, pathb)).toBe(false);
    });

    await test.step('Linear A2 embedded into E2E37B for its starting node and any course of the path', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await embedWith(page, hostb, 'E2E 37 Pfad B', [SOURCE_START, SOURCE_ANY], undefined, 'Linear A2');
      drainTaskQueue();
    });

    await test.step('live: not on Linear A2, not in E2E37B', async () => {
      expect(onPath(learner, pathb), 'the ADELE enrolment in T01 is no source').toBe(false);
      expect(enrolmentState(learner, hostb).methods).toEqual([]);
      await loginAs(page, learner, fixturePassword());
      await expectCourseClosed(page, hostb, 'nothing of their own carries them in');
    });

    await test.step('after the nightly sweep: still nothing', async () => {
      nightlySweep();
      drainTaskQueue();
      expect(onPath(learner, pathb)).toBe(false);
      expect(enrolmentState(learner, hostb).methods).toEqual([]);
    });

    await test.step('control: an enrolment of one\'s own in T01 carries them onto Linear A2 and into E2E37B', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await enrolAsStudent(page, t01, name);
      drainTaskQueue();
      expect(onPath(learner, pathb)).toBe(true);
      await loginAs(page, learner, fixturePassword());
      await expectCourseOpenAfterTasks(page, hostb, 'their own enrolment in T01 carries them in');
    });

    await test.step('taken back: without the own enrolment E2E37B closes; the ADELE enrolment in T01 stays', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await unenrolMethod(page, t01, name, /^Manual/);
      drainTaskQueue();
      expect(enrolmentState(learner, t01).methods.map((m) => m.enrol)).toEqual(['adele']);
      await loginAs(page, learner, fixturePassword());
      await expectCourseClosedAfterTasks(page, hostb, 'only the ADELE enrolment in T01 is left - it carries nothing');
    });
  });
});
