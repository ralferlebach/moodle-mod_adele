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
 * teacher-course-access - the courses of a tile in the teacher view of
 * mod_adele (issue "Kurszugriff in der Lehrendenansicht", TP1:
 * existing_only).
 *
 * Fixture KZ: the path "Verzweigt, gestapelt B1" (node 1 is a stack of three
 * courses) embedded into E2EKZ. The editing teacher and the teacher without
 * editing rights are both in course 1 and course 3 of the stack, in course 2
 * not at all; a learner is in E2EKZ only.
 *
 *   K1  teacher without editing rights: activate the tile -> the courses ->
 *       an open course opens in a new tab (AK-E1)
 *   K2  the same with editing rights (AK-E2)
 *   K3  no access: no link at all; the service refuses a learner and a
 *       context that is not an embedding (AK-E3)
 *   K4  access taken away outside ADELE: activated again, the link is gone;
 *       the other course stays open (AK-E6)
 *   K5  the stack row by row, by keyboard; nothing of it for a learner or in
 *       the editor (AK-E8, AK-A1)
 *
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { test, expect, Page } from '@playwright/test';
import { env, loginAs } from '../support/env';
import {
  callService,
  fixture,
  fixturePassword,
  reseedFixtures,
  unenrolFromCourse,
} from '../support/fixtures';

const STACK = 'dndnode_1';
const course = (n: number) => ({
  id: fixture(`ADELE_FIXTURE_KZ_COURSE_${n}`),
  short: fixture(`ADELE_FIXTURE_KZ_COURSE_${n}_SHORT`),
  full: fixture(`ADELE_FIXTURE_KZ_COURSE_${n}_FULL`),
});

/**
 * Open the embedding in the host course.
 *
 * @param page The page, logged in.
 */
async function openEmbedding(page: Page): Promise<void> {
  await page.goto(`/mod/adele/view.php?id=${fixture('ADELE_FIXTURE_KZ_CM')}`);
  await expect(page.locator(`[data-testid="learningpath-node-${STACK}"]`)).toBeVisible({ timeout: 60_000 });
}

/**
 * Activate a tile with the mouse, as a teacher does: a click on its title.
 *
 * @param page The page.
 * @param nodeid The node.
 */
async function activate(page: Page, nodeid: string) {
  const tile = page.locator(`[data-testid="learningpath-node-${nodeid}"]`);
  await tile.locator('h5').first().click();
  const section = tile.locator('[data-testid="node-course-access"]');
  await expect(section).toBeVisible({ timeout: 30_000 });
  // The server's answer has arrived once no "checking" note is left.
  await expect(section.locator('.adele-course-access-note[role="status"]')).toHaveCount(0, { timeout: 30_000 });
  return section;
}

/**
 * What a row offers: "<short> | <status> | link" or "... | no link".
 *
 * @param section The course section of the tile.
 * @param courseid The course.
 */
async function row(section: ReturnType<Page['locator']>, courseid: string): Promise<string> {
  const item = section.locator(`[data-testid="node-course-${courseid}"]`);
  await expect(item).toHaveCount(1);
  const short = (await item.locator('.adele-course-access-short').innerText()).trim();
  const status = (await item.locator('.adele-course-access-status').innerText()).trim();
  const link = (await item.locator('a').count()) ? 'link' : 'no link';
  return `${short} | ${status} | ${link}`;
}

/**
 * Follow a row's link and say where the new tab landed.
 *
 * @param page The page.
 * @param section The course section.
 * @param courseid The course.
 */
async function openInNewTab(page: Page, section: ReturnType<Page['locator']>, courseid: string): Promise<Page> {
  const link = section.locator(`[data-testid="node-course-${courseid}"] a`);
  await expect(link).toHaveAttribute('target', '_blank');
  await expect(link).toHaveAttribute('rel', 'noopener noreferrer');
  const [tab] = await Promise.all([page.context().waitForEvent('page'), link.click()]);
  await tab.waitForLoadState();
  return tab;
}

// Every file starts from the seeded state, whatever ran before it
// (support/fixtures: reseedFixtures; E2E issue, section 3B).
test.beforeAll(() => reseedFixtures());

test.describe('ADELE-E2E-K — course access in the teacher view of mod_adele (TP1: existing_only)', () => {
  for (const [story, key, label] of [
    ['K1', 'TUTOR', 'without editing rights'],
    ['K2', 'TEACHER', 'with editing rights'],
  ] as const) {
    test(`${story}: a teacher ${label} activates the stack and opens a course in a new tab`, { tag: [`@story:${story}`] }, async ({ page }) => {
      await loginAs(page, fixture(`ADELE_FIXTURE_KZ_${key}`), fixturePassword());
      await openEmbedding(page);

      await test.step('before the activation: no course actions on the graph', async () => {
        await expect(page.locator('[data-testid="node-course-access"]')).toHaveCount(0);
      });

      const section = await test.step('activated: the courses of the tile, each with its status', async () => {
        const s = await activate(page, STACK);
        const [one, two, three] = [course(1), course(2), course(3)];
        expect(await row(s, one.id)).toBe(`${one.short} | Access | link`);
        expect(await row(s, two.id)).toBe(`${two.short} | No course access | no link`);
        expect(await row(s, three.id)).toBe(`${three.short} | Access | link`);
        return s;
      });

      await test.step('"open course" opens the course itself, in a new tab; the graph stays', async () => {
        const one = course(1);
        const tab = await openInNewTab(page, section, one.id);
        expect(new URL(tab.url()).pathname + new URL(tab.url()).search).toBe(`/course/view.php?id=${one.id}`);
        await expect(tab.locator('body')).toHaveClass(/path-course-view/);
        await expect(tab.locator('.page-header-headings h1, .page-context-header h1').first()).toContainText(one.full);
        await tab.close();
        expect(page.url()).toContain('/mod/adele/view.php');
        await expect(section).toBeVisible();
      });
    });
  }

  test('K3: no access gives no link - and the service refuses whoever is not this activity\'s teacher', { tag: ['@story:K3'] }, async ({ page }) => {
    const two = course(2);

    await test.step('the course the teacher is not in: status in words, no link, no button', async () => {
      await loginAs(page, fixture('ADELE_FIXTURE_KZ_TUTOR'), fixturePassword());
      await openEmbedding(page);
      const section = await activate(page, STACK);
      const item = section.locator(`[data-testid="node-course-${two.id}"]`);
      await expect(item).toHaveAttribute('data-access', 'none');
      await expect(item.locator('a, button')).toHaveCount(0);
      expect(await page.locator(`a[href*="/course/view.php?id=${two.id}"]`).count(), 'nowhere on the page').toBe(0);
    });

    await test.step('the service: a learner of the course is refused', async () => {
      await loginAs(page, fixture('ADELE_FIXTURE_KZ_LEARNER'), fixturePassword());
      await page.goto(`/course/view.php?id=${fixture('ADELE_FIXTURE_KZ_HOST')}`);
      const answer = await callService(page, 'mod_adele_get_node_course_access', {
        contextid: Number(fixture('ADELE_FIXTURE_KZ_CM_CONTEXT')),
      });
      expect(answer.error, 'a learner gets no course list').toBe('nopermissions');
    });

    await test.step('the service: the teacher, asking with a context that is no embedding, is refused', async () => {
      await loginAs(page, fixture('ADELE_FIXTURE_KZ_TEACHER'), fixturePassword());
      await page.goto(`/course/view.php?id=${fixture('ADELE_FIXTURE_KZ_HOST')}`);
      const coursecontext = await page.evaluate(() =>
        Number((window as unknown as { M?: { cfg?: { contextid?: number } } }).M?.cfg?.contextid || 0));
      const answer = await callService(page, 'mod_adele_get_node_course_access', { contextid: coursecontext });
      expect(answer.error, 'only the context of a mod_adele activity is answered').toBeTruthy();
    });
  });

  test('K4: access taken away outside ADELE - activated again, the link is gone; the other course stays', { tag: ['@story:K4', '@withdrawal'] }, async ({ page }) => {
    const [one, three] = [course(1), course(3)];

    await test.step('before: course 3 is open to the teacher', async () => {
      await loginAs(page, fixture('ADELE_FIXTURE_KZ_TUTOR'), fixturePassword());
      await openEmbedding(page);
      expect(await row(await activate(page, STACK), three.id)).toBe(`${three.short} | Access | link`);
    });

    await test.step('the administrator unenrols the teacher from course 3 (Moodle\'s participants page)', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await unenrolFromCourse(page, three.id, 'Fixture Access Tutor');
    });

    await test.step('a fresh session, the tile activated again: course 3 without a link, course 1 still open', async () => {
      await loginAs(page, fixture('ADELE_FIXTURE_KZ_TUTOR'), fixturePassword());
      await openEmbedding(page);
      const section = await activate(page, STACK);
      expect(await row(section, three.id)).toBe(`${three.short} | No course access | no link`);
      expect(await row(section, one.id)).toBe(`${one.short} | Access | link`);
    });
  });

  test('K5: the stack by keyboard, each course by its own name - and nothing of it for a learner or in the editor', { tag: ['@story:K5'] }, async ({ page }) => {
    await test.step('Enter on the focused tile activates it; each row carries short and full name', async () => {
      await loginAs(page, fixture('ADELE_FIXTURE_KZ_TEACHER'), fixturePassword());
      await openEmbedding(page);
      const wrapper = page.locator(`.vue-flow__node[data-id="${STACK}"]`);
      await wrapper.focus();
      await page.keyboard.press('Enter');
      const section = page.locator(`[data-testid="learningpath-node-${STACK}"] [data-testid="node-course-access"]`);
      await expect(section).toBeVisible({ timeout: 30_000 });
      await expect(section.locator('.adele-course-access-note[role="status"]')).toHaveCount(0, { timeout: 30_000 });
      for (const n of [1, 2, 3]) {
        const c = course(n);
        const item = section.locator(`[data-testid="node-course-${c.id}"]`);
        await expect(item.locator('.adele-course-access-short')).toHaveText(c.short);
        await expect(item.locator('.adele-course-access-full')).toHaveText(c.full);
      }
      const link = section.locator(`[data-testid="node-course-${course(1).id}"] a`);
      await expect(link).toHaveAccessibleName(`Open course ${course(1).short} – ${course(1).full} in a new tab`);
      const toggle = section.getByRole('button', { name: /Hide course actions/ });
      await expect(toggle).toHaveAttribute('aria-expanded', 'true');
      await expect(page.locator(`#${await toggle.getAttribute('aria-controls')}`)).toBeVisible();
    });

    await test.step('the link is reached and followed by keyboard; Escape closes, the focus goes back to the tile', async () => {
      const section = page.locator(`[data-testid="learningpath-node-${STACK}"] [data-testid="node-course-access"]`);
      const link = section.locator(`[data-testid="node-course-${course(3).id}"] a`);
      await link.focus();
      const [tab] = await Promise.all([page.context().waitForEvent('page'), page.keyboard.press('Enter')]);
      await tab.waitForLoadState();
      expect(tab.url()).toContain(`/course/view.php?id=${course(3).id}`);
      await tab.close();
      await link.focus();
      await page.keyboard.press('Escape');
      await expect(section).toHaveCount(0);
      await expect.poll(() => page.evaluate(() => document.activeElement?.getAttribute('data-id'))).toBe(STACK);
    });

    await test.step('a learner of the course: the tile shows no course actions', async () => {
      await loginAs(page, fixture('ADELE_FIXTURE_KZ_LEARNER'), fixturePassword());
      await openEmbedding(page);
      await page.locator(`[data-testid="learningpath-node-${STACK}"]`).click();
      await page.waitForLoadState('networkidle');
      await expect(page.locator('[data-testid="node-course-access"]')).toHaveCount(0);
    });

    await test.step('the path editor (administrator): activating a tile shows no course actions', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await page.goto('/local/adele/index.php');
      const card = page.locator(`[id^="local-adele-app"] [data-testid="learningpath-row-${fixture('ADELE_FIXTURE_KZ_PATH')}"]`);
      await expect(card).toBeVisible({ timeout: 30_000 });
      await card.getByRole('button', { name: /^(Edit|Bearbeiten):/ }).click();
      await expect(page.locator('[data-testid="learningpath-title"]')).toBeVisible({ timeout: 30_000 });
      await page.waitForLoadState('networkidle');
      await expect(page.locator(`[data-testid="learningpath-node-${STACK}"]`)).toBeVisible({ timeout: 60_000 });
      await page.locator(`[data-testid="learningpath-node-${STACK}"] h5`).first().click();
      await page.waitForLoadState('networkidle');
      await expect(page.locator('[data-testid="node-course-access"]')).toHaveCount(0);
    });
  });
});
