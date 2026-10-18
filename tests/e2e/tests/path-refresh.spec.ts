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
 * path-refresh - stories R26 and F27 (mod_adele #26, #27, #32).
 *
 *   R26  the open path of a learner, under each refresh mode of the site:
 *        exactly one load; mode 0 nothing more, also on focus; mode 1 one
 *        refresh per return, no polling; mode 2 polling at the interval while
 *        visible, paused while hidden, never two requests at once even when
 *        the server answers slowly; mode 3 polling while hidden too; a
 *        failing server does not stop later attempts. A change of the
 *        settings applies with the next page load.
 *   F27  the learner finishes T01 in another tab: the open path takes the
 *        new state over without a reload and moves to the next open node
 *        (learner default); unchanged polls afterwards leave a view the
 *        learner moved alone; with auto-focus switched off the view stays
 *        where it was.
 *
 * Requests are counted in the browser (local_adele_get_user_path_relation),
 * the tab's visibility is the page's own (Playwright keeps the page visible
 * unless told otherwise; hidden is simulated by the visibilitychange event
 * with a hidden state, the way a browser reports it).
 *
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { execFileSync } from 'node:child_process';
import { test, expect, Browser, Page } from '@playwright/test';
import { loginAs } from '../support/env';
import {
  completeCourse, fixture, fixturePassword, reseedFixtures, runCompletion,
} from '../support/fixtures';

// Exactly this method - not local_adele_get_user_path_relations (the list).
const METHOD = '"methodname":"local_adele_get_user_path_relation"';

/**
 * Set the site's settings of the path view (the settings page's own config keys).
 *
 * @param values name => value
 */
function siteSettings(values: Record<string, number>): void {
  for (const [name, value] of Object.entries(values)) {
    execFileSync('php', ['admin/cli/cfg.php', '--component=local_adele', `--name=${name}`, `--set=${value}`],
      { cwd: fixture('ADELE_MOODLE_ROOT'), encoding: 'utf8' });
  }
}

/** Back to the defaults. */
function defaults(): void {
  siteSettings({
    pathpollingmode: 2, pathpollinginterval: 3000, pathpollingdebounce: 300,
    pathautofocusenabled: 1, pathautofocusteachermode: 1, pathautofocuslearnermode: 2,
  });
}

/**
 * Count the path requests of a page, and how many are open at once.
 *
 * @param page The page.
 * @param delay Extra time the server "takes" to answer (ms).
 */
async function counter(page: Page, delay = 0) {
  const c = { calls: 0, open: 0, maxOpen: 0 };
  await page.route('**/lib/ajax/service.php**', async (route) => {
    const body = route.request().postData() || '';
    if (!body.includes(METHOD)) {
      return route.continue();
    }
    c.calls++;
    c.open++;
    c.maxOpen = Math.max(c.maxOpen, c.open);
    try {
      if (delay) {
        await new Promise((r) => setTimeout(r, delay));
      }
      const response = await route.fetch();
      await route.fulfill({ response });
    } finally {
      c.open--;
    }
  });
  return c;
}

/**
 * Open the learner's own path of fixture S26 - its activity page directly,
 * so the count starts with this page (the course page shows the path too).
 *
 * @param page The page, logged in as the learner.
 * @param c A counter to start from zero on this page.
 */
async function openPath(page: Page, c?: { calls: number; open: number; maxOpen: number }): Promise<void> {
  await page.goto(`/course/view.php?id=${fixture('ADELE_FIXTURE_S26_HOST')}`);
  const href = await page.getByRole('main').getByRole('link', { name: 'E2E 26 Pfad' }).getAttribute('href');
  await page.goto('about:blank');
  if (c) {
    c.calls = 0;
    c.maxOpen = 0;
  }
  await page.goto(href || '');
  await expect(page.locator('[data-testid="learningpath-node-dndnode_1"]')).toBeVisible({ timeout: 60_000 });
}

/**
 * Report the page as hidden or visible, as a browser does.
 *
 * @param page The page.
 * @param hidden Hidden or visible.
 */
async function setHidden(page: Page, hidden: boolean): Promise<void> {
  await page.evaluate((h) => {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => (h ? 'hidden' : 'visible') });
    Object.defineProperty(document, 'hasFocus', { configurable: true, value: () => !h });
    if (h) {
      window.dispatchEvent(new Event('blur'));
    }
    document.dispatchEvent(new Event('visibilitychange'));
    if (!h) {
      window.dispatchEvent(new Event('focus'));
    }
  }, hidden);
}

/** The canvas transform: where the view stands. */
const transform = async (page: Page): Promise<string> =>
  (await page.locator('.vue-flow__transformationpane').first().getAttribute('style')) || '';

/**
 * Wait until the view has come to rest (the first fit animates for a second).
 *
 * @param page The page.
 */
async function settled(page: Page): Promise<void> {
  let last = '';
  for (let i = 0; i < 40; i++) {
    const now = await transform(page);
    if (now && now === last) {
      return;
    }
    last = now;
    await page.waitForTimeout(400);
  }
}

/**
 * Whether a node lies inside the visible canvas.
 *
 * @param page The page.
 * @param nodeid The node.
 */
async function inView(page: Page, nodeid: string): Promise<boolean> {
  const pane = await page.locator('.vue-flow').first().boundingBox();
  const node = await page.locator(`[data-testid="learningpath-node-${nodeid}"]`).boundingBox();
  const size = page.viewportSize() || { width: 1280, height: 720 };
  if (!pane || !node) {
    return false;
  }
  // The part of the canvas a person sees: the canvas within the window.
  const left = Math.max(pane.x, 0);
  const top = Math.max(pane.y, 0);
  const right = Math.min(pane.x + pane.width, size.width);
  const bottom = Math.min(pane.y + pane.height, size.height);
  const cx = node.x + node.width / 2;
  const cy = node.y + node.height / 2;
  return cx >= left && cx <= right && cy >= top && cy <= bottom;
}

/**
 * Drag the canvas until a node is out of sight - how far that is depends on
 * the zoom the view was restored with. Upwards along the empty left edge of
 * the visible part of the canvas (a press on a node would be a click on it).
 *
 * @param page The page.
 * @param nodeid The node to look away from.
 */
async function lookAway(page: Page, nodeid: string): Promise<void> {
  await settled(page);
  const pane = page.locator('.vue-flow__pane').first();
  const size = page.viewportSize() || { width: 1280, height: 720 };
  for (let attempt = 0; attempt < 8 && await inView(page, nodeid); attempt++) {
    const box = await pane.boundingBox();
    if (!box) {
      break;
    }
    const top = Math.max(box.y, 80) + 15;
    const bottom = Math.min(box.y + box.height, size.height) - 15;
    await page.mouse.move(box.x + 15, bottom);
    await page.mouse.down();
    await page.mouse.move(box.x + 15, top, { steps: 12 });
    await page.mouse.up();
    await page.waitForTimeout(300);
  }
  await settled(page);
  expect(await inView(page, nodeid), `${nodeid} out of sight`).toBe(false);
}

/**
 * As the learner, in a browser of their own: finish a course of the path and
 * let the system act on it.
 *
 * @param browser The browser.
 * @param courseid The course.
 */
async function learnerFinishes(browser: Browser, courseid: string): Promise<void> {
  const context = await browser.newContext();
  const other = await context.newPage();
  await loginAs(other, fixture('ADELE_FIXTURE_S26_LEARNER'), fixturePassword());
  await completeCourse(other, courseid);
  await context.close();
  runCompletion();
}

/**
 * As the teacher of E2E26: open the learner's path in the teacher view.
 *
 * @param page The page, logged in as the teacher.
 */
async function teacherOpensLearner(page: Page): Promise<void> {
  await page.goto(`/mod/adele/view.php?id=${fixture('ADELE_FIXTURE_S26_CM')}`);
  // The user list links each learner; the row carries the name.
  const row = page.locator('#adele-userlist-table tbody tr').filter({ hasText: 'Refresh Learner' });
  await expect(row.first()).toBeVisible({ timeout: 60_000 });
  await row.first().getByRole('link').first().click();
  await expect(page.locator('[data-testid="learningpath-node-dndnode_1"]')).toBeVisible({ timeout: 60_000 });
}

test.beforeAll(() => reseedFixtures());
test.afterAll(() => defaults());

test.describe('ADELE-E2E-R26 — refresh modes of an open path (#26)', () => {
  test.describe.configure({ mode: 'serial' });
  test.beforeEach(async ({ page }) => {
    defaults();
    await loginAs(page, fixture('ADELE_FIXTURE_S26_LEARNER'), fixturePassword());
  });
  // A slow answer may still be on its way when a test ends.
  test.afterEach(async ({ page }) => {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
  });

  test('mode 0: one load, nothing more - also on focus', { tag: ['@story:R26'] }, async ({ page }) => {
    siteSettings({ pathpollingmode: 0, pathpollinginterval: 1000 });
    const c = await counter(page);
    await openPath(page, c);
    await page.waitForTimeout(1500);
    expect(c.calls, 'exactly one load').toBe(1);
    await setHidden(page, true);
    await setHidden(page, false);
    await page.waitForTimeout(4000);
    expect(c.calls).toBe(1);
  });

  test('mode 1: one refresh per return within the debounce window, no polling', { tag: ['@story:R26'] }, async ({ page }) => {
    siteSettings({ pathpollingmode: 1, pathpollinginterval: 1000, pathpollingdebounce: 300 });
    const c = await counter(page);
    await openPath(page, c);
    await page.waitForTimeout(4000);
    expect(c.calls, 'one load, no polling').toBe(1);
    await setHidden(page, true);
    await setHidden(page, false);
    await expect.poll(() => c.calls, { timeout: 5000 }).toBe(2);
    await page.waitForTimeout(4000);
    expect(c.calls, 'still no polling').toBe(2);
  });

  test('mode 2: polling while visible, paused while hidden; a slow server never gets two requests at once',
    { tag: ['@story:R26'] }, async ({ page }) => {
      siteSettings({ pathpollingmode: 2, pathpollinginterval: 1000, pathpollingdebounce: 0 });
      const c = await counter(page, 2500);
      await openPath(page, c);
      await expect.poll(() => c.calls, { timeout: 15_000 }).toBeGreaterThanOrEqual(3);
      expect(c.maxOpen, 'one request at a time, though each takes 2.5 s').toBe(1);
      await setHidden(page, true);
      await page.waitForTimeout(3000);
      const hidden = c.calls;
      await page.waitForTimeout(5000);
      expect(c.calls, 'no polling while hidden').toBe(hidden);
      await setHidden(page, false);
      await expect.poll(() => c.calls, { timeout: 10_000 }).toBeGreaterThan(hidden);
      expect(c.maxOpen).toBe(1);
    });

  test('mode 3: polling goes on while hidden', { tag: ['@story:R26'] }, async ({ page }) => {
    siteSettings({ pathpollingmode: 3, pathpollinginterval: 1000, pathpollingdebounce: 0 });
    const c = await counter(page);
    await openPath(page, c);
    await setHidden(page, true);
    const hidden = c.calls;
    await expect.poll(() => c.calls, { timeout: 10_000 }).toBeGreaterThanOrEqual(hidden + 2);
  });

  test('a failing server does not stop later attempts; the page keeps working', { tag: ['@story:R26'] }, async ({ page }) => {
    siteSettings({ pathpollingmode: 2, pathpollinginterval: 1000, pathpollingdebounce: 0 });
    await openPath(page);
    let failing = true;
    let calls = 0;
    await page.route('**/lib/ajax/service.php**', async (route) => {
      if (!(route.request().postData() || '').includes(METHOD)) {
        return route.continue();
      }
      calls++;
      return failing ? route.fulfill({ status: 500, body: 'down' }) : route.continue();
    });
    await expect.poll(() => calls, { timeout: 20_000 }).toBeGreaterThanOrEqual(2);
    failing = false;
    const before = calls;
    await expect.poll(() => calls, { timeout: 30_000 }).toBeGreaterThan(before);
    await expect(page.locator('[data-testid="learningpath-node-dndnode_1"]')).toBeVisible();
  });
});

test.describe('ADELE-E2E-F27 — auto-focus after a change (#27)', () => {
  test.describe.configure({ mode: 'serial' });

  test('learner default: T01 finished in another tab -> the open path shows it without reload and moves to the next open node; later polls leave a moved view alone',
    { tag: ['@story:F27', '@withdrawal'] }, async ({ page, context }) => {
      defaults();
      siteSettings({ pathpollinginterval: 1000 });
      await loginAs(page, fixture('ADELE_FIXTURE_S26_LEARNER'), fixturePassword());
      await openPath(page);
      const nodes = JSON.parse(fixture('ADELE_FIXTURE_S26_NODES'));
      await expect(page.locator('[data-testid="learningpath-node-dndnode_1"]')).toHaveAttribute('data-status', 'accessible');

      await test.step('the learner looks far away from the path', async () => {
        // Drag the canvas until node 2 is out of sight - how far that is
        // depends on the zoom the view was restored with.
        // Upwards along the empty left edge of the visible part of the
        // canvas (a press on a node would be a click on it).
        await settled(page);
        const pane = page.locator('.vue-flow__pane').first();
        const size = page.viewportSize() || { width: 1280, height: 720 };
        for (let attempt = 0; attempt < 8 && await inView(page, 'dndnode_2'); attempt++) {
          const box = await pane.boundingBox();
          if (!box) {
            break;
          }
          const top = Math.max(box.y, 80) + 15;
          const bottom = Math.min(box.y + box.height, size.height) - 15;
          await page.mouse.move(box.x + 15, bottom);
          await page.mouse.down();
          await page.mouse.move(box.x + 15, top, { steps: 12 });
          await page.mouse.up();
          await page.waitForTimeout(300);
        }
        await page.waitForTimeout(1200);
        expect(await inView(page, 'dndnode_2')).toBe(false);
      });

      await test.step('T01 finished in another tab: the open path changes by itself and shows the next node', async () => {
        const other = await context.newPage();
        await completeCourse(other, String(nodes.dndnode_1));
        await other.close();
        runCompletion();
        await expect(page.locator('[data-testid="learningpath-node-dndnode_1"]'))
          .toHaveAttribute('data-status', 'completed', { timeout: 30_000 });
        await expect.poll(() => inView(page, 'dndnode_2'), { timeout: 10_000 }).toBe(true);
      });

      await test.step('moved alone afterwards: unchanged polls keep the learner\'s view', async () => {
        const pane = page.locator('.vue-flow__pane').first();
        const box = await pane.boundingBox();
        if (box) {
          await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
          await page.mouse.down();
          await page.mouse.move(box.x + box.width / 2 + 150, box.y + box.height / 2 + 80, { steps: 8 });
          await page.mouse.up();
        }
        await page.waitForTimeout(800);
        const placed = await transform(page);
        await page.waitForTimeout(5000);
        expect(await transform(page), 'several polls without a change').toBe(placed);
      });
    });

  test('auto-focus off: a change shows, the view stays where it was', { tag: ['@story:F27'] }, async ({ page, context }) => {
    reseedFixtures();
    defaults();
    siteSettings({ pathpollinginterval: 1000, pathautofocusenabled: 0 });
    await loginAs(page, fixture('ADELE_FIXTURE_S26_LEARNER'), fixturePassword());
    await openPath(page);
    const nodes = JSON.parse(fixture('ADELE_FIXTURE_S26_NODES'));
    await settled(page);
    const before = await transform(page);
    const other = await context.newPage();
    await completeCourse(other, String(nodes.dndnode_1));
    await other.close();
    runCompletion();
    await expect(page.locator('[data-testid="learningpath-node-dndnode_1"]'))
      .toHaveAttribute('data-status', 'completed', { timeout: 30_000 });
    await page.waitForTimeout(1500);
    expect(await transform(page), 'no move').toBe(before);
  });

  test('teacher default (Testfall 1, 2, 6): the learner finishes T01 -> the teacher\'s view of their path shows node 1; then T02 -> node 2; unchanged polls leave a moved view',
    { tag: ['@story:F27', '@withdrawal'] }, async ({ page, browser }) => {
      reseedFixtures();
      defaults();
      siteSettings({ pathpollinginterval: 1000 });
      const nodes = JSON.parse(fixture('ADELE_FIXTURE_S26_NODES'));
      await loginAs(page, fixture('ADELE_FIXTURE_S26_TEACHER'), fixturePassword());
      await teacherOpensLearner(page);

      await test.step('T01 finished: the teacher\'s view moves to node 1, completed last', async () => {
        await lookAway(page, 'dndnode_1');
        await learnerFinishes(browser, String(nodes.dndnode_1));
        await expect(page.locator('[data-testid="learningpath-node-dndnode_1"]'))
          .toHaveAttribute('data-status', 'completed', { timeout: 30_000 });
        await expect.poll(() => inView(page, 'dndnode_1'), { timeout: 10_000 }).toBe(true);
      });

      await test.step('T02 finished too: the view moves to node 2, completed last', async () => {
        await lookAway(page, 'dndnode_2');
        await learnerFinishes(browser, String(nodes.dndnode_2));
        await expect(page.locator('[data-testid="learningpath-node-dndnode_2"]'))
          .toHaveAttribute('data-status', 'completed', { timeout: 60_000 });
        await expect.poll(() => inView(page, 'dndnode_2'), { timeout: 10_000 }).toBe(true);
      });

      await test.step('unchanged polls: no snap back', async () => {
        await lookAway(page, 'dndnode_2');
        const placed = await transform(page);
        await page.waitForTimeout(4000);
        expect(await transform(page)).toBe(placed);
      });
    });

  test('Testfall 8: a drag in progress is not interrupted by a change that arrives meanwhile',
    { tag: ['@story:F27'] }, async ({ page, browser }) => {
      reseedFixtures();
      defaults();
      siteSettings({ pathpollinginterval: 1000 });
      const nodes = JSON.parse(fixture('ADELE_FIXTURE_S26_NODES'));
      await loginAs(page, fixture('ADELE_FIXTURE_S26_LEARNER'), fixturePassword());
      await openPath(page);
      await settled(page);
      const pane = page.locator('.vue-flow__pane').first();
      const box = await pane.boundingBox();
      expect(box).not.toBeNull();
      const x = (box?.x ?? 0) + 15;
      const y = Math.min((box?.y ?? 0) + (box?.height ?? 0), (page.viewportSize()?.height ?? 720)) - 20;
      await page.mouse.move(x, y);
      await page.mouse.down();
      await page.mouse.move(x, y - 60, { steps: 6 });
      const held = await transform(page);
      // Still holding: the change is stored meanwhile and polls fall due.
      await learnerFinishes(browser, String(nodes.dndnode_1));
      await page.waitForTimeout(4000);
      expect(await transform(page), 'the view stays where the drag holds it').toBe(held);
      await page.mouse.up();
      await expect(page.locator('[data-testid="learningpath-node-dndnode_1"]'))
        .toHaveAttribute('data-status', 'completed', { timeout: 30_000 });
    });

  test('Testfall 4: two next nodes open at once -> the view shows both together, not one of them',
    { tag: ['@story:F27'] }, async ({ page, context }) => {
      reseedFixtures();
      defaults();
      siteSettings({ pathpollinginterval: 1000 });
      const nodes = JSON.parse(fixture('ADELE_FIXTURE_S26_NODES'));
      await loginAs(page, fixture('ADELE_FIXTURE_S27P_LEARNER'), fixturePassword());
      await page.goto(`/mod/adele/view.php?id=${fixture('ADELE_FIXTURE_S27P_CM')}`);
      await expect(page.locator('[data-testid="learningpath-node-dndnode_1"]')).toBeVisible({ timeout: 60_000 });
      await expect(page.locator('[data-testid="learningpath-node-dndnode_3"]')).not.toHaveAttribute('data-status', 'accessible');
      await lookAway(page, 'dndnode_2');
      await lookAway(page, 'dndnode_3');
      expect(await inView(page, 'dndnode_2') || await inView(page, 'dndnode_3'), 'both next nodes out of sight').toBe(false);

      const other = await context.newPage();
      await completeCourse(other, String(nodes.dndnode_1));
      await other.close();
      runCompletion();
      for (const id of ['dndnode_2', 'dndnode_3']) {
        await expect(page.locator(`[data-testid="learningpath-node-${id}"]`))
          .toHaveAttribute('data-status', 'accessible', { timeout: 30_000 });
      }
      await expect.poll(async () => (await inView(page, 'dndnode_2')) && (await inView(page, 'dndnode_3')), {
        timeout: 10_000,
        message: 'both parallel next nodes in view together',
      }).toBe(true);
    });
});
