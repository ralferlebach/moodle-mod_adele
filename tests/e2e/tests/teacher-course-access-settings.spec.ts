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
 * teacher-course-access-settings - the setting "Teachers' access to linked
 * courses" (local_adele #600, TP3): the site's allowed modes and default,
 * the activity's own mode, and what acts when the site takes a mode away.
 *
 * Fixture KZ (see teacher-course-access.spec.ts): E2EKZ with the activity
 * "E2E KZ Kurszugriff", seeded with "Existing access only"; the seed puts the
 * site settings back to the shipped state (only "Existing access only").
 *
 *   K6  the administrator allows "Request access" and makes it the default:
 *       a new activity starts with it and offers only the allowed modes;
 *       the existing activity keeps its mode (AK-C1, AK-C2, AK-C3)
 *   K7  the site takes the mode away: stored as before, marked in the form,
 *       saving for another reason keeps it, a forged value is not stored,
 *       the teacher view acts as "Existing access only"; given back, it acts
 *       again (AK-C3, AK-G4 for the saving rule)
 *   K8  a teacher without editing rights uses the course access but cannot
 *       change the setting (AK-C4)
 *
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { test, expect, Page } from '@playwright/test';
import { env, loginAs } from '../support/env';
import { callService, fixture, fixturePassword, reseedFixtures } from '../support/fixtures';
import { embedWith, embeddingCmid, SOURCE_START, storedEmbedding } from '../support/host';

const SETTINGS = '/admin/settings.php?section=modsettingadele';
const LABEL = {
  existing_only: 'Existing access only',
  request: 'Request access',
  enrol_student: 'Enrol as participant',
  enrol_teacher: 'Enrol as non-editing teacher',
  enrol_editingteacher: 'Enrol as teacher (with editing rights)',
} as const;
type Mode = keyof typeof LABEL;

/**
 * As administrator: tick exactly the given modes and set the default, on
 * Moodle's administration page.
 *
 * @param page The page, logged in as administrator.
 * @param modes The modes to allow.
 * @param fallback The default for new activities.
 */
async function siteAllows(page: Page, modes: Mode[], fallback: Mode): Promise<void> {
  await page.goto(SETTINGS);
  for (const mode of Object.keys(LABEL) as Mode[]) {
    const box = page.locator(`#id_s_mod_adele_teachercourseaccessmodes_${mode}`);
    await box.setChecked(modes.includes(mode));
  }
  await page.locator('#id_s_mod_adele_teachercourseaccessdefault').selectOption(fallback);
  await page.getByRole('button', { name: /Save changes|Änderungen speichern/ }).click();
  await expect(page.locator('.alert-success, .alert-info').first()).toBeVisible();
}

/**
 * Open an activity's settings and show the more options of the learning
 * path section.
 *
 * @param page The page.
 * @param url The form's address.
 */
async function openForm(page: Page, url: string) {
  await page.goto(url);
  const select = page.locator('#id_teachercourseaccessmode');
  if (!(await select.isVisible())) {
    await page.locator('#id_adelefieldset .moreless-toggler').click();
  }
  await expect(select).toBeVisible();
  return select;
}

/**
 * The options a select offers, as "value=label".
 *
 * @param select The select.
 */
async function offered(select: ReturnType<Page['locator']>): Promise<string[]> {
  return select.locator('option').evaluateAll((options) =>
    options.map((o) => `${(o as HTMLOptionElement).value}=${(o.textContent || '').trim()}`));
}

/**
 * The mode the teacher view acts with, as the service names it.
 *
 * @param page The page, logged in as a teacher of the activity.
 */
async function actingMode(page: Page): Promise<string> {
  await page.goto(`/mod/adele/view.php?id=${fixture('ADELE_FIXTURE_KZ_CM')}`);
  const answer = await callService(page, 'mod_adele_get_node_course_access', {
    contextid: Number(fixture('ADELE_FIXTURE_KZ_CM_CONTEXT')),
  });
  expect(answer.error, 'the teacher gets an answer').toBeUndefined();
  return String((answer.data as { mode?: string })?.mode);
}

test.beforeAll(() => reseedFixtures());

test.describe('ADELE-E2E-K — teachers\' access to linked courses: the setting (TP3)', () => {
  test('K6: allowed modes and default of the site - a new activity starts with the default, the old one keeps its mode', { tag: ['@story:K6'] }, async ({ page }) => {
    const host = fixture('ADELE_FIXTURE_KZ_HOST');
    await loginAs(page, env.adminUser, env.adminPassword);

    await test.step('shipped: only "Existing access only" is offered', async () => {
      const select = await openForm(page, `/course/modedit.php?add=adele&course=${host}&section=0`);
      expect(await offered(select)).toEqual([`existing_only=${LABEL.existing_only}`]);
    });

    await test.step('the administrator allows "Request access" and makes it the default', async () => {
      await siteAllows(page, ['existing_only', 'request'], 'request');
    });

    await test.step('a new activity: the allowed modes only, "Request access" preselected - and stored so', async () => {
      const select = await openForm(page, `/course/modedit.php?add=adele&course=${host}&section=0`);
      expect(await offered(select)).toEqual([`existing_only=${LABEL.existing_only}`, `request=${LABEL.request}`]);
      await expect(select).toHaveValue('request');
      await embedWith(page, host, 'E2E KZ neu', [SOURCE_START]);
      const cmid = await embeddingCmid(page, host, 'E2E KZ neu');
      expect(storedEmbedding(cmid).teachercourseaccessmode).toBe('request');
    });

    await test.step('the existing activity keeps "Existing access only"', async () => {
      expect(storedEmbedding(fixture('ADELE_FIXTURE_KZ_CM')).teachercourseaccessmode).toBe('existing_only');
      const select = await openForm(page, `/course/modedit.php?update=${fixture('ADELE_FIXTURE_KZ_CM')}`);
      await expect(select).toHaveValue('existing_only');
    });
  });

  test('K7: the site takes the mode away - stored as before, acts as "Existing access only"; given back, it acts again', { tag: ['@story:K7', '@withdrawal'] }, async ({ page }) => {
    const cm = fixture('ADELE_FIXTURE_KZ_CM');

    await test.step('allowed: the activity is set to "Request access"; the teacher view acts with it', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await siteAllows(page, ['existing_only', 'request'], 'existing_only');
      const select = await openForm(page, `/course/modedit.php?update=${cm}`);
      await select.selectOption('request');
      await page.getByRole('button', { name: /Save and return to course|Speichern und zum Kurs/i }).click();
      await expect(page).toHaveURL(/\/course\/view\.php/);
      expect(storedEmbedding(cm).teachercourseaccessmode).toBe('request');
      await loginAs(page, fixture('ADELE_FIXTURE_KZ_TUTOR'), fixturePassword());
      expect(await actingMode(page)).toBe('request');
    });

    await test.step('the administrator takes "Request access" away', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await siteAllows(page, ['existing_only'], 'existing_only');
    });

    await test.step('the form: the stored mode is still there, marked, with a warning in sight', async () => {
      await page.goto(`/course/modedit.php?update=${cm}`);
      await expect(page.locator('[data-testid="teachercourseaccessmode-notallowed"]')).toBeVisible();
      const select = page.locator('#id_teachercourseaccessmode');
      await expect(select).toBeVisible();
      expect(await offered(select)).toEqual([
        `existing_only=${LABEL.existing_only}`,
        `request=${LABEL.request} (no longer allowed on this site)`,
      ]);
      await expect(select).toHaveValue('request');
    });

    await test.step('saving the form for another reason keeps the stored mode', async () => {
      await page.locator('#id_name').fill('E2E KZ Kurszugriff');
      await page.getByRole('button', { name: /Save and return to course|Speichern und zum Kurs/i }).click();
      await expect(page).toHaveURL(/\/course\/view\.php/);
      expect(storedEmbedding(cm).teachercourseaccessmode).toBe('request');
    });

    await test.step('a forged value (a mode the site does not allow, added to the form) is not stored', async () => {
      await page.goto(`/course/modedit.php?update=${cm}`);
      await page.locator('#id_teachercourseaccessmode').evaluate((select) => {
        const option = document.createElement('option');
        option.value = 'enrol_editingteacher';
        option.textContent = 'forged';
        select.appendChild(option);
        (select as HTMLSelectElement).value = 'enrol_editingteacher';
      });
      await page.getByRole('button', { name: /Save and return to course|Speichern und zum Kurs/i }).click();
      await page.waitForLoadState('networkidle');
      expect(storedEmbedding(cm).teachercourseaccessmode, 'neither the forged mode nor a silent change').toBe('request');
    });

    await test.step('the teacher view acts as "Existing access only"', async () => {
      await loginAs(page, fixture('ADELE_FIXTURE_KZ_TUTOR'), fixturePassword());
      expect(await actingMode(page)).toBe('existing_only');
    });

    await test.step('given back: the stored mode acts again, the warning is gone', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await siteAllows(page, ['existing_only', 'request'], 'existing_only');
      await page.goto(`/course/modedit.php?update=${cm}`);
      await expect(page.locator('[data-testid="teachercourseaccessmode-notallowed"]')).toHaveCount(0);
      await loginAs(page, fixture('ADELE_FIXTURE_KZ_TUTOR'), fixturePassword());
      expect(await actingMode(page)).toBe('request');
    });
  });

  test('K8: a teacher without editing rights uses the course access but cannot change the setting', { tag: ['@story:K8'] }, async ({ page }) => {
    // Whatever the chains before left stored: it must stay so.
    const before = storedEmbedding(fixture('ADELE_FIXTURE_KZ_CM')).teachercourseaccessmode;
    await loginAs(page, fixture('ADELE_FIXTURE_KZ_TUTOR'), fixturePassword());

    await test.step('the course access works for them', async () => {
      expect(Object.keys(LABEL)).toContain(await actingMode(page));
    });

    await test.step('the activity settings are refused - by Moodle, not just hidden', async () => {
      const response = await page.goto(`/course/modedit.php?update=${fixture('ADELE_FIXTURE_KZ_CM')}`);
      await expect(page.locator('#id_teachercourseaccessmode')).toHaveCount(0);
      const refused = (response?.status() ?? 200) >= 400
        || (await page.locator('.errorbox, [data-rel="fatalerror"], .alert-danger').count()) > 0;
      expect(refused, 'Moodle refuses the form to a teacher without editing rights').toBe(true);
      expect(storedEmbedding(fixture('ADELE_FIXTURE_KZ_CM')).teachercourseaccessmode).toBe(before);
    });
  });
});
