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
 * stacked-courses - story S17 (mod_adele #17).
 *
 *     a learner expands the stacked starting node of three courses in
 *     their path
 *     -> every panel is found by its real course id, not by a position
 *     -> its readable name is the course name with the course id
 *     -> it can be reached with the keyboard and is named in the
 *        accessibility tree
 *     -> folded and expanded again, each course keeps its identity
 *     -> each panel shows its short name, course id and status in words,
 *        named by its heading and described by that line; no DOM id twice
 *     -> the expand button says its state and controls the cards; Escape
 *        in a panel folds the stack and gives the button a visible focus
 *   the same stack with its first two courses swapped (fx_17_learner)
 *     -> every course keeps its id, name and short name; the panels follow
 *        the order of the stack
 *
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { test, expect, Page } from '@playwright/test';
import { loginAs } from '../support/env';
import { fixture, fixturePassword, reseedFixtures } from '../support/fixtures';
import { KZ_STACK, kzCourse } from '../support/teacheraccess';

const LEARNER = () => fixture('ADELE_FIXTURE_KZ_LEARNER');

/**
 * Open the KZ embedding and press the stack's "Expand courses".
 *
 * @param page The page, logged in as the learner of E2EKZ.
 */
async function expandStack(page: Page): Promise<void> {
  const tile = page.locator(`[data-testid="learningpath-node-${KZ_STACK}"]`);
  await expect(tile).toBeVisible({ timeout: 60_000 });
  // The button sits on the card image of a node of the zoomed-out graph:
  // the click goes to the button itself, wherever the canvas puts it.
  await tile.locator('.overlay button[title="Expand courses"], .overlay button[title="Kurse ausklappen"]').click({ force: true });
}

/**
 * Identity of every expanded panel, read from the page: course id from the
 * panel, readable name, accessible name and course class of the node.
 *
 * @param page The page.
 */
async function panels(page: Page): Promise<Record<string, { title: string; label: string; cls: string }>> {
  const out: Record<string, { title: string; label: string; cls: string }> = {};
  for (const n of [1, 2, 3]) {
    const course = kzCourse(n);
    const panel = page.locator(`[data-course-id="${course.id}"][data-node-id="${KZ_STACK}"]`);
    await expect(panel, `panel of ${course.short}`).toHaveCount(1, { timeout: 30_000 });
    const node = page.locator(`.vue-flow__node.adele-expanded-course-${course.id}`);
    await expect(node).toHaveCount(1);
    out[course.id] = {
      title: (await panel.getAttribute('title')) || '',
      label: (await node.getAttribute('aria-label')) || '',
      cls: (await node.getAttribute('class')) || '',
    };
  }
  return out;
}

/**
 * What a panel card says about itself: course id, short name, the name of its
 * heading, its description and whether both are its own elements.
 *
 * @param page The page.
 * @param courseid The course.
 */
async function card(page: Page, courseid: string) {
  return page.locator(`[data-testid="expanded-course-${courseid}"]`).evaluate((el) => {
    const heading = document.getElementById(el.getAttribute('aria-labelledby') || '');
    const desc = document.getElementById(el.getAttribute('aria-describedby') || '');
    return {
      id: el.id,
      role: el.getAttribute('role'),
      short: el.getAttribute('data-course-shortname'),
      status: el.getAttribute('data-status'),
      heading: heading && el.contains(heading) ? (heading.textContent || '').trim() : null,
      desc: desc && el.contains(desc) ? (desc.textContent || '').trim() : null,
      x: el.getBoundingClientRect().left,
    };
  });
}

/**
 * The DOM ids that appear more than once on the page - outside the edges of
 * the graph: two of the reference paths store an edge twice, so its path
 * element is drawn twice with the same id (a finding of its own, outside
 * #17: issue draft local_adele-issue-duplicate-edges.md).
 *
 * @param page The page.
 */
const duplicateIds = (page: Page) => page.evaluate(() => {
  const seen = new Map<string, number>();
  document.querySelectorAll('[id]').forEach((e) => {
    if (!e.closest('.vue-flow__edges')) {
      seen.set(e.id, (seen.get(e.id) || 0) + 1);
    }
  });
  return [...seen].filter(([, n]) => n > 1).map(([id]) => id);
});

test.beforeAll(() => reseedFixtures());

test.describe('ADELE-E2E-S17 — stacked courses carry their course (#17)', () => {
  test('each expanded panel is found by its course id, named by its course, reachable by keyboard; stable when expanded again',
    { tag: ['@story:S17'] }, async ({ page }) => {
      await loginAs(page, LEARNER(), fixturePassword());
      await page.goto(`/mod/adele/view.php?id=${fixture('ADELE_FIXTURE_KZ_CM')}`);

      let first: Awaited<ReturnType<typeof panels>> = {};
      await test.step('expanded: three panels, each by its real course id and name', async () => {
        await expandStack(page);
        first = await panels(page);
        for (const n of [1, 2, 3]) {
          const course = kzCourse(n);
          expect(first[course.id].title, 'the readable name is the course name with its id')
            .toMatch(new RegExp(`^${course.full.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')} \\(.*${course.id}\\)$`));
          expect(first[course.id].label, 'the node is named the same in the accessibility tree').toBe(first[course.id].title);
        }
        expect(new Set(Object.values(first).map((p) => p.label)).size, 'no two panels share a name').toBe(3);
      });

      await test.step('by keyboard: each panel takes the focus and is a named button', async () => {
        for (const n of [1, 2, 3]) {
          const course = kzCourse(n);
          const node = page.locator(`.vue-flow__node.adele-expanded-course-${course.id}`);
          await node.focus();
          await expect(node).toBeFocused();
          await expect(page.getByRole('button', { name: first[course.id].label, exact: true })).toHaveCount(1);
        }
      });

      await test.step('each card: heading, a visible line with short name, id and status; no DOM id twice', async () => {
        for (const n of [1, 2, 3]) {
          const course = kzCourse(n);
          const c = await card(page, course.id);
          expect(c.role).toBe('group');
          expect(c.id).toBe(`adele-expanded-course-${KZ_STACK}-${course.id}`);
          expect(c.short, 'the short name on the card').toBe(course.short);
          expect(c.heading, 'named by its own heading').toBe(course.full);
          expect(c.desc, 'described by its own visible line').toContain(course.short);
          expect(c.desc).toContain(course.id);
          expect(c.desc).toMatch(/Not completed|Nicht abgeschlossen|Completed|Abgeschlossen/);
          expect(first[course.id].label, 'the short name is in the accessible name').toContain(course.short);
          await expect(page.locator(`[data-testid="expanded-course-${course.id}"] .adele-expanded-course-desc`)).toBeVisible();
        }
        // The information cards of all three open: still no id twice.
        for (const n of [1, 2, 3]) {
          // A click event on the button itself: the cards overlap on the
          // zoomed-out canvas, a pointer click may land on a neighbour.
          const info = page.locator(`[data-testid="expanded-course-${kzCourse(n).id}"] button.information`);
          await info.dispatchEvent('click');
          await expect(info).toHaveAttribute('aria-expanded', 'true');
        }
        await expect(page.locator('[id$="-information"].additional-card')).toHaveCount(3);
        expect(await duplicateIds(page)).toEqual([]);
        await expect(page.locator('[id*="undefined"]')).toHaveCount(0);
      });

      await test.step('the expand button: state, controlled cards, Escape in a panel folds and the button has a visible focus', async () => {
        const toggle = page.locator(`#adele-stack-toggle-${KZ_STACK}`);
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        const controls = ((await toggle.getAttribute('aria-controls')) || '').split(' ');
        expect(controls).toEqual([1, 2, 3].map((n) => `adele-expanded-course-${KZ_STACK}-${kzCourse(n).id}`)
          .concat(controls.slice(3)));
        for (const id of controls) {
          await expect(page.locator(`[id="${id}"]`), `the button controls ${id}`).toHaveCount(1);
        }
        const node = page.locator(`.vue-flow__node.adele-expanded-course-${kzCourse(2).id}`);
        await node.focus();
        await page.keyboard.press('Escape');
        await expect(page.locator(`[data-node-id="${KZ_STACK}"][data-course-id]`)).toHaveCount(0, { timeout: 30_000 });
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await expect(toggle).toBeFocused();
        const focus = await toggle.evaluate((el) => ({
          visible: el.matches(':focus-visible'),
          outline: getComputedStyle(el).outlineStyle,
        }));
        expect(focus.visible, 'the focus is shown').toBe(true);
        expect(focus.outline).not.toBe('none');
        expect(await toggle.getAttribute('aria-controls')).toBeNull();
      });

      await test.step('expanded again from the keyboard (Enter on the focused button): the same identity for each course', async () => {
        const toggle = page.locator(`#adele-stack-toggle-${KZ_STACK}`);
        await expect(toggle).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        expect(await panels(page)).toEqual(first);
      });
    });

  test('the stack in swapped order: every course keeps its id, name and short name; the panels follow the stack',
    { tag: ['@story:S17'] }, async ({ page }) => {
      const order: number[] = JSON.parse(fixture('ADELE_FIXTURE_S17_ORDER'));
      const byId = Object.fromEntries([1, 2, 3].map((n) => [kzCourse(n).id, kzCourse(n)]));
      expect(String(order[0]), 'the seed swapped the first two courses').toBe(kzCourse(2).id);
      expect(String(order[1])).toBe(kzCourse(1).id);
      await loginAs(page, fixture('ADELE_FIXTURE_S17_LEARNER'), fixturePassword());
      await page.goto(`/mod/adele/view.php?id=${fixture('ADELE_FIXTURE_S17_CM')}`);
      await expandStack(page);
      const cards = [];
      for (const id of order.slice(0, 3).map(String)) {
        await expect(page.locator(`[data-course-id="${id}"][data-node-id="${KZ_STACK}"]`)).toHaveCount(1, { timeout: 30_000 });
        const c = await card(page, id);
        const node = page.locator(`.vue-flow__node.adele-expanded-course-${id}`);
        expect(c.heading, `course ${id}: its own name`).toBe(byId[id].full);
        expect(c.short).toBe(byId[id].short);
        expect(c.id).toBe(`adele-expanded-course-${KZ_STACK}-${id}`);
        expect(await node.getAttribute('aria-label')).toContain(byId[id].short);
        expect(await node.getAttribute('aria-label')).toContain(id);
        cards.push(c);
      }
      // The panels unfold to the right in the order of the stack.
      expect(cards.map((c) => c.x)).toEqual([...cards.map((c) => c.x)].sort((a, b) => a - b));
      await expect(page.locator(`#adele-stack-toggle-${KZ_STACK}`))
        .toHaveAttribute('aria-controls', new RegExp(`^adele-expanded-course-${KZ_STACK}-${order[0]} adele-expanded-course-${KZ_STACK}-${order[1]}`));
      expect(await duplicateIds(page)).toEqual([]);
    });
});
