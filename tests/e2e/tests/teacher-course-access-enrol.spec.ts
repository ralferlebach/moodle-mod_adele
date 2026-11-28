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
 * teacher-course-access-enrol - teachers enrol into node courses through
 * enrol_adele, under the three site policies (local_adele #600, TP4).
 *
 * Fixture KZ: the stack T01-T03; the teacher without editing rights
 * ("tutor") is in T01 and T03, not in T02; the editing teacher of E2EKZ is
 * a teacher without editing rights in T01 and T03 - no authority to assign
 * any teacher role in T02. The administrator has authority everywhere. The
 * seed puts site settings and activity back (only "Existing access only").
 *
 *   K13  enrol as participant: confirmation (role, rights, source), Escape
 *        enrols nothing, "Enrol" does - through enrol_adele, with the site's
 *        role; a new session opens the course (AK-B3, AK-E5, AK-F1, AK-F6)
 *   K14  the site takes the mode away: the enrolment goes, the teacher's own
 *        T01 stays; forged calls are refused (AK-E7, AK-C3, AK-D2)
 *   K15  policy 2: the editing teacher without authority keeps or lowers the
 *        administrator's "with editing rights" - lowered, the higher grant
 *        goes; raising again is not offered (AK-G4, AK-G7)
 *   K16  policy 1: locked for the editing teacher; saving keeps the value (AK-G3)
 *   K17  policy 3: the editing teacher delegates "non-editing teacher"; back
 *        to policy 2, the value stays and the enrolment goes (AK-G5, AK-G6)
 *   K18  the activity deleted: its enrolments go with it (AK-F3)
 *
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { test, expect, Page } from '@playwright/test';
import { env, loginAs } from '../support/env';
import { callService, drainTaskQueue, fixture, fixturePassword, reseedFixtures } from '../support/fixtures';
import { storedEmbedding } from '../support/host';
import {
  activateKzStack, grantsOf, kzCourse, Mode, offered, openForm, setActivityMode, siteAllows, sitePolicy,
} from '../support/teacheraccess';

const TUTOR = () => fixture('ADELE_FIXTURE_KZ_TUTOR');
const TEACHER = () => fixture('ADELE_FIXTURE_KZ_TEACHER');
const CM = () => fixture('ADELE_FIXTURE_KZ_CM');
const ENROL_MODES: Mode[] = ['existing_only', 'enrol_student', 'enrol_teacher', 'enrol_editingteacher'];

/**
 * As administrator: allow the enrolment modes and set the activity's mode.
 *
 * @param page The page.
 * @param mode The activity's mode.
 */
async function adminSets(page: Page, mode: Mode): Promise<void> {
  await loginAs(page, env.adminUser, env.adminPassword);
  await siteAllows(page, ENROL_MODES, 'existing_only');
  await setActivityMode(page, CM(), mode);
}

/**
 * The T02 row as the tutor sees it after activating the stack.
 *
 * @param page The page.
 */
async function tutorT02(page: Page) {
  await loginAs(page, TUTOR(), fixturePassword());
  const section = await activateKzStack(page);
  const row = section.locator(`[data-testid="node-course-${kzCourse(2).id}"]`);
  return { section, row, button: row.locator(`[data-testid="node-course-enrol-${kzCourse(2).id}"]`) };
}

/**
 * Enrol the tutor into T02 through the dialog.
 *
 * @param page The page.
 */
async function tutorEnrolsT02(page: Page) {
  const t = await tutorT02(page);
  await t.button.click();
  const dialog = page.getByRole('dialog');
  await dialog.getByRole('button', { name: 'Enrol', exact: true }).click();
  await expect(dialog).toHaveCount(0);
  await expect(t.row.locator('a')).toHaveCount(1);
  return t;
}

test.beforeEach(() => reseedFixtures());

test.describe('ADELE-E2E-K — teachers enrol into node courses (TP4)', () => {
  test('K13: enrol as participant - confirmed, through enrol_adele, with the site\'s role; the course opens', { tag: ['@story:K13'] }, async ({ page }) => {
    const two = kzCourse(2);
    await adminSets(page, 'enrol_student');
    const { row, button } = await tutorT02(page);
    const dialog = page.getByRole('dialog');

    await test.step('the row offers it; the dialog says role, rights and source before anything happens', async () => {
      await expect(row.locator('.adele-course-access-status')).toHaveText('No access – enrolment possible');
      await expect(button).toHaveAccessibleName(`Enrol as participant: course ${two.short} – ${two.full}`);
      await button.click();
      await expect(dialog).toHaveAccessibleName(`Enrol into ${two.full}`);
      await expect(dialog.locator('[data-testid="enrol-confirm-role"]')).toHaveText('Role in the course: Student');
      await expect(dialog.locator('[data-testid="enrol-confirm-source"]')).toContainText('E2E KZ Kurszugriff');
      await expect(dialog.locator('[data-testid="enrol-confirm-source"]')).toContainText('E2E host course E2EKZ');
      expect(grantsOf(TUTOR())).toHaveLength(0);
    });

    await test.step('Escape: nothing enrolled, the focus back on the button', async () => {
      await page.keyboard.press('Escape');
      await expect(dialog).toHaveCount(0);
      await expect(button).toBeFocused();
      expect(grantsOf(TUTOR())).toHaveLength(0);
    });

    await test.step('"Enrol": enrolled through enrol_adele as student; the row has the link, focused', async () => {
      await button.click();
      await dialog.getByRole('button', { name: 'Enrol', exact: true }).click();
      await expect(dialog).toHaveCount(0);
      const link = row.locator('a');
      await expect(link).toBeFocused();
      await expect(row.locator('.adele-course-access-status')).toHaveText('Access');
      expect(grantsOf(TUTOR())).toEqual([{ courseid: Number(two.id), mode: 'enrol_student', role: 'student', enrolled: true }]);
    });

    await test.step('a new session: the course opens; its participants page names the method', async () => {
      await loginAs(page, TUTOR(), fixturePassword());
      await page.goto(`/course/view.php?id=${two.id}`);
      await expect(page.locator('body')).toHaveClass(/path-course-view/);
      await loginAs(page, env.adminUser, env.adminPassword);
      await page.goto(`/user/index.php?id=${two.id}&perpage=5000`);
      const participant = page.locator('table#participants tbody tr').filter({ hasText: 'Fixture Access Tutor' });
      await expect(participant).toContainText('Student');
      await participant.locator('[data-action="showdetails"]').first().click();
      await expect(page.getByRole('dialog')).toContainText('ADELE (teacher access)');
    });
  });

  test('K14: the site takes the mode away - the enrolment goes, the own T01 stays; forged calls refused', { tag: ['@story:K14', '@withdrawal'] }, async ({ page }) => {
    const contextid = Number(fixture('ADELE_FIXTURE_KZ_CM_CONTEXT'));
    const enrol = (courseid: string | number) =>
      callService(page, 'mod_adele_enrol_in_node_course', { contextid, courseid: Number(courseid) });
    await adminSets(page, 'enrol_student');

    await test.step('forged: a course outside the path, a course already open; a learner', async () => {
      await loginAs(page, TUTOR(), fixturePassword());
      await page.goto(`/mod/adele/view.php?id=${CM()}`);
      expect((await enrol(fixture('ADELE_FIXTURE_KZ_HOST'))).error).toBe('accessrequest_notinpath');
      expect((await enrol(kzCourse(1).id)).error).toBe('accessrequest_hasaccess');
      await loginAs(page, fixture('ADELE_FIXTURE_KZ_LEARNER'), fixturePassword());
      await page.goto(`/course/view.php?id=${fixture('ADELE_FIXTURE_KZ_HOST')}`);
      expect((await enrol(kzCourse(2).id)).error).toBe('nopermissions');
      expect(grantsOf(TUTOR())).toHaveLength(0);
    });

    await test.step('enrolled into T02', async () => {
      await tutorEnrolsT02(page);
    });

    await test.step('the administrator takes "Enrol as participant" away', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await siteAllows(page, ['existing_only'], 'existing_only');
      drainTaskQueue();
      expect(grantsOf(TUTOR())).toHaveLength(0);
    });

    await test.step('a new session: T02 closed and without action, T01 still open; the service refuses', async () => {
      const { section, row } = await tutorT02(page);
      await expect(row.locator('.adele-course-access-status')).toHaveText('No course access');
      await expect(row.locator('a, button')).toHaveCount(0);
      await expect(section.locator(`[data-testid="node-course-${kzCourse(1).id}"] a`)).toHaveCount(1);
      expect((await enrol(kzCourse(2).id)).error).toBe('enrol_notallowed');
    });
  });

  test('K15: policy 2 - kept or lowered by somebody without authority; the higher grant goes; no raising again', { tag: ['@story:K15', '@withdrawal'] }, async ({ page }) => {
    await adminSets(page, 'enrol_editingteacher');

    await test.step('the tutor enrols "with editing rights"', async () => {
      await tutorEnrolsT02(page);
      expect(grantsOf(TUTOR()).map((g) => g.role)).toEqual(['editingteacher']);
    });

    await test.step('the editing teacher: the stored mode and the lower ones', async () => {
      await loginAs(page, TEACHER(), fixturePassword());
      const select = await openForm(page, `/course/modedit.php?update=${CM()}`);
      expect((await offered(select)).map((o) => o.split('=')[0]))
        .toEqual(['existing_only', 'enrol_student', 'enrol_teacher', 'enrol_editingteacher']);
      await select.selectOption('enrol_teacher');
      await page.getByRole('button', { name: /Save and return to course|Speichern und zum Kurs/i }).click();
      await expect(page).toHaveURL(/\/course\/view\.php/);
      expect(storedEmbedding(CM()).teachercourseaccessmode).toBe('enrol_teacher');
    });

    await test.step('lowered: the "editing rights" enrolment is gone; the row offers the lower one', async () => {
      expect(grantsOf(TUTOR())).toHaveLength(0);
      const { button } = await tutorT02(page);
      await expect(button).toHaveText('Enrol as non-editing teacher');
    });

    await test.step('raising again is not offered to the editing teacher', async () => {
      await loginAs(page, TEACHER(), fixturePassword());
      const select = await openForm(page, `/course/modedit.php?update=${CM()}`);
      expect((await offered(select)).map((o) => o.split('=')[0])).not.toContain('enrol_editingteacher');
    });
  });

  test('K16: policy 1 - locked for the editing teacher; saving keeps the value', { tag: ['@story:K16'] }, async ({ page }) => {
    await adminSets(page, 'enrol_teacher');
    await sitePolicy(page, 1);
    await loginAs(page, TEACHER(), fixturePassword());
    await page.goto(`/course/modedit.php?update=${CM()}`);
    await expect(page.locator('[data-testid="teachercourseaccessmode-locked"]')).toBeVisible();
    await expect(page.locator('select#id_teachercourseaccessmode')).toHaveCount(0);
    await page.getByRole('button', { name: /Save and return to course|Speichern und zum Kurs/i }).click();
    await expect(page).toHaveURL(/\/course\/view\.php/);
    expect(storedEmbedding(CM()).teachercourseaccessmode).toBe('enrol_teacher');
  });

  test('K17: policy 3 - the editing teacher delegates; back to policy 2, the value stays and the enrolment goes', { tag: ['@story:K17', '@withdrawal'] }, async ({ page }) => {
    await loginAs(page, env.adminUser, env.adminPassword);
    await siteAllows(page, ENROL_MODES, 'existing_only');
    await sitePolicy(page, 3);

    await test.step('the editing teacher chooses "non-editing teacher" (delegation)', async () => {
      await loginAs(page, TEACHER(), fixturePassword());
      const select = await openForm(page, `/course/modedit.php?update=${CM()}`);
      await select.selectOption('enrol_teacher');
      await page.getByRole('button', { name: /Save and return to course|Speichern und zum Kurs/i }).click();
      await expect(page).toHaveURL(/\/course\/view\.php/);
      expect(storedEmbedding(CM()).teachercourseaccessmode).toBe('enrol_teacher');
    });

    await test.step('the tutor enrols as non-editing teacher', async () => {
      await tutorEnrolsT02(page);
      expect(grantsOf(TUTOR()).map((g) => g.role)).toEqual(['teacher']);
    });

    await test.step('the administrator returns to policy 2: the value stays, the enrolment goes', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await sitePolicy(page, 2);
      drainTaskQueue();
      expect(storedEmbedding(CM()).teachercourseaccessmode).toBe('enrol_teacher');
      expect(grantsOf(TUTOR())).toHaveLength(0);
      const { row } = await tutorT02(page);
      await expect(row.locator('.adele-course-access-status')).toHaveText('No access – not available');
      await expect(row.locator(`[data-testid="node-course-reason-${kzCourse(2).id}"]`)).toContainText('no longer has the authority');
    });
  });

  test('K18: the activity deleted - its enrolments go with it', { tag: ['@story:K18', '@withdrawal'] }, async ({ page }) => {
    await adminSets(page, 'enrol_student');
    await tutorEnrolsT02(page);
    await loginAs(page, env.adminUser, env.adminPassword);
    await page.goto(`/course/view.php?id=${fixture('ADELE_FIXTURE_KZ_HOST')}`);
    const sesskey = await page.evaluate(() => (window as unknown as { M: { cfg: { sesskey: string } } }).M.cfg.sesskey);
    await page.goto(`/course/mod.php?sesskey=${sesskey}&delete=${CM()}&confirm=1`);
    drainTaskQueue();
    expect(grantsOf(TUTOR())).toHaveLength(0);
    await loginAs(page, TUTOR(), fixturePassword());
    await page.goto(`/course/view.php?id=${kzCourse(2).id}`);
    await expect(page.locator('body')).not.toHaveClass(/path-course-view/);
  });
});
