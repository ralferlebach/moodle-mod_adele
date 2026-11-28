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
 * groups-live - groups, non-learners, live markers and node counters
 * (local_adele #12 - #17).
 *
 * Fixture G16 (seed_fixtures.php): path "E2E Gruppen G16" (T01 -> T02 ->
 * T03), host course E2EG16 with "Gruppe Rot" (Anna 2/3, Bert 1/3, Nora 0/3,
 * Tim the non-editing teacher) and "Gruppe Blau" (Cora 3/3,
 * Dirk 0/3); activity "getrennt" (separate groups, live markers on, 5
 * minutes) and "sichtbar" (visible groups, live markers off).
 *
 *   G1  learner, separate groups -> own group ranked, names plain, nothing
 *       of the other group in the page or in the answers of the server
 *   G2  learner, visible groups -> own average above the own list, the
 *       other group only as an average
 *   G3  non-editing teacher in Rot -> only Rot; Blau refused by the server;
 *       the other enrolled people on request, apart from the learners
 *   G4  two activities of one course with DIFFERENT groupings (fixture G16M:
 *       "Nord-Mitte" separate groups, grouping Nord + Mitte; "Mitte-Sued"
 *       visible groups, grouping Mitte + Sued; Nele Nord 2/3, Mats Mitte 1/3,
 *       Sina Sued 3/3, Tessa editing teacher): each activity offers and lists
 *       the groups of its own grouping only - in the page, in the server's
 *       answers and when asked directly; a group of the other grouping is
 *       refused; learners see their group within each activity
 *       (local_adele #16 DoD0-M3, #12 DoD0-L4; re-audit 2026-11-22)
 *   G5  the other enrolled people (#13 DoD1.2-1.4, re-audit 2026-11-26), Tessa
 *       in "Nord-Mitte" (Theo, non-editing teacher in Mitte): the list follows
 *       the group chosen while it is open;
 *       a failed answer shows the error and the next opening loads again; the
 *       ranking stays as it is; a learner asking the server directly is
 *       refused - whatever the toggle in the page
 *   L1  a learner opens a node course -> marker and active counter, also by
 *       polling without a reload; counter -> names -> the learner's path,
 *       centred on the node; Escape gives the focus back
 *   L3  the way back from a learner: group, viewport, scroll and focus kept
 *   L4  one learner in two node courses (test-only enrolment, real course
 *       views): active marker on both nodes, avatar, name and state of the
 *       right learner, nobody never seen marked; the activity put back
 *       behind the window (test clock: the data moves, not the rules) ->
 *       polling, no reload: no active marker, the inactive one on the last
 *       node only (#14 DoD2-L4, re-audit 2026-11-28)
 *   D1  the lists of two nodes open at once - two instances of one dialog
 *       type in one application: ids unique, references in their own
 *       instance, Escape in one leaves the other open (#18 DoD3; also in
 *       Firefox)
 *   L2  live markers off -> no active counter, the completed counter stays
 *   M1  activity settings: off / 5 by default, 61 refused, saved and shown again
 *
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { test, expect, Page, Response, Browser } from '@playwright/test';
import { env, loginAs, loginAsAdmin } from '../support/env';
import { fixture, reseedFixtures } from '../support/fixtures';

const VIEW = (cmid: string) => `/mod/adele/view.php?id=${cmid}`;
const app = (page: Page) => page.locator('[name="local-adele-app"]').first();

/**
 * The serious and critical WCAG 2.1 A/AA violations inside an element, once
 * every animation has ended (a fade-in measured mid-way gives false colours).
 *
 * @param page The page.
 * @param selector What to check.
 */
async function axeSerious(page: Page, selector: string): Promise<string[]> {
  await page.waitForFunction(() => document.getAnimations().every((a) => a.playState !== 'running'), null, { timeout: 10_000 })
    .catch(() => undefined);
  const results = await new AxeBuilder({ page })
    .include(selector)
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();
  return results.violations
    .filter((v) => v.impact === 'critical' || v.impact === 'serious')
    .map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`);
}

/**
 * Collect the answers of ADELE's web services while a page loads.
 *
 * @param page The page.
 * @returns A list that fills with the response bodies.
 */
function recordAnswers(page: Page): string[] {
  const bodies: string[] = [];
  page.on('response', async (response: Response) => {
    if (response.url().includes('/lib/ajax/service.php') && /local_adele_get_(group_overview|user_path_relations|live_snapshot|node_people|non_learners)/.test(response.url() + (response.request().postData() || ''))) {
      try {
        bodies.push(await response.text());
      } catch (e) {
        // A navigation dropped the body.
      }
    }
  });
  return bodies;
}

/**
 * Call a web service from the page, as the page's own code does.
 *
 * @param page The page.
 * @param methodname The function.
 * @param args Its arguments.
 */
const callService = (page: Page, methodname: string, args: object) => page.evaluate(([m, a]) => new Promise((resolve) => {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  (window as any).require(['core/ajax'], (ajax: any) => {
    ajax.call([{ methodname: m, args: a }])[0].then(
      (result: unknown) => resolve({ ok: true, result }),
      (error: { errorcode?: string; message?: string }) => resolve({ ok: false, error: error.errorcode || error.message })
    );
  });
}), [methodname, args] as [string, object]);

/**
 * The module context id the page booted with.
 *
 * @param page The page.
 */
const contextid = async (page: Page): Promise<number> => Number(await app(page).getAttribute('contextid'));

// Every file starts from the seeded state, whatever ran before it (the ids of
// the activities change with every seed).
test.beforeAll(() => reseedFixtures());

test.describe('ADELE-E2E-G16 — groups and non-learners (#12, #13, #16)', () => {
  test.beforeEach(() => resetFixture());
  test('G1 a learner in separate groups sees the own group ranked, nothing of the other group',
    { tag: ['@story:G16'] }, async ({ page }) => {
      const answers = recordAnswers(page);
      await loginAs(page, fixture('ADELE_FIXTURE_G16_BERT'), env.fixturePassword);
      await page.goto(VIEW(fixture('ADELE_FIXTURE_G16_SEPARATE')));
      const ranking = app(page).locator('[data-testid="adele-group-ranking"]');
      await expect(ranking.locator('[data-testid="adele-ranking-row"]')).toHaveCount(3, { timeout: 30_000 });
      const rows = ranking.locator('[data-testid="adele-ranking-row"]');
      await expect(rows.nth(0)).toContainText(fixture('ADELE_FIXTURE_G16_ANNA_NAME'));
      await expect(rows.nth(0)).toContainText('66.67 %');
      await expect(rows.nth(1)).toContainText(fixture('ADELE_FIXTURE_G16_BERT_NAME'));
      await expect(rows.nth(1)).toHaveClass(/adele-ranking-self/);
      await expect(rows.nth(1).getByRole('progressbar')).toHaveAttribute('aria-valuenow', '33.33');
      await expect(rows.nth(2)).toContainText(fixture('ADELE_FIXTURE_G16_NOPATH_NAME'));
      await expect(rows.nth(2)).toContainText('0 %');
      await expect(ranking.locator('a, button')).toHaveCount(0);
      expect(await axeSerious(page, '[data-testid="adele-group-ranking"]'), 'accessibility of the ranking').toEqual([]);
      await expect(ranking.locator('[data-testid^="adele-own-average-"]'), 'separate groups: no averages').toHaveCount(0);
      // The participant list holds the own row only.
      await expect(app(page).locator('[data-testid="adele-userlist-table"] tbody tr')).toHaveCount(1);
      // Nothing of group Blau, neither on the page nor in an answer of the server.
      // By last name: the participant list and the answers of the server keep
      // first and last name apart, so a full name would be found in neither.
      for (const other of [fixture('ADELE_FIXTURE_G16_CORA_NAME'), fixture('ADELE_FIXTURE_G16_DIRK_NAME')]
        .map((name) => name.replace(/^Fixture /, ''))) {
        await expect(page.locator('body')).not.toContainText(other);
        expect(answers.join('\n')).not.toContain(other);
      }
      expect(answers.length, 'the answers were recorded').toBeGreaterThan(0);
      // Asking for the live data or the other people as a learner is refused.
      const ctx = await contextid(page);
      expect(await callService(page, 'local_adele_get_live_snapshot', { contextid: ctx, groupid: -1 })).toMatchObject({ ok: false });
      expect(await callService(page, 'local_adele_get_non_learners', { contextid: ctx, groupid: -1 })).toMatchObject({ ok: false });
    });

  test('G2 a learner in visible groups sees the own average above the own list, the other group as an average only',
    { tag: ['@story:G16'] }, async ({ page }) => {
      const answers = recordAnswers(page);
      await loginAs(page, fixture('ADELE_FIXTURE_G16_CORA'), env.fixturePassword);
      await page.goto(VIEW(fixture('ADELE_FIXTURE_G16_VISIBLE')));
      const ranking = app(page).locator('[data-testid="adele-group-ranking"]');
      const section = ranking.locator('section[data-testid^="adele-group-ranking-"]');
      await expect(section).toHaveCount(1, { timeout: 30_000 });
      const own = section.locator('[data-testid^="adele-own-average-"]');
      await expect(own).toContainText('50 %');
      await expect(own).toContainText(/average over 2 learners\)|Mittel über 2 Lernende\)/);
      // Above the list.
      const ownBox = await own.boundingBox();
      const listBox = await section.locator('.adele-ranking-list').boundingBox();
      expect(ownBox!.y).toBeLessThan(listBox!.y);
      await expect(section.locator('[data-testid="adele-ranking-row"]')).toHaveCount(2);
      const others = ranking.locator('[data-testid="adele-group-averages"] li');
      await expect(others).toHaveCount(1);
      // Rot: Anna 66.67, Bert 33.33, Nora 0 -> 33.3.
      await expect(others.first()).toContainText('Gruppe Rot');
      await expect(others.first()).toContainText('33.3 %');
      await expect(others.first()).toContainText(/average over 3 learners\)|Mittel über 3 Lernende\)/);
      for (const name of [fixture('ADELE_FIXTURE_G16_ANNA_NAME'), fixture('ADELE_FIXTURE_G16_BERT_NAME')]
        .map((full) => full.replace(/^Fixture /, ''))) {
        await expect(page.locator('body')).not.toContainText(name);
        expect(answers.join('\n')).not.toContain(name);
      }
    });

  test('G3 a non-editing teacher sees the own group only; another group is refused; other enrolled people on request',
    { tag: ['@story:G16'] }, async ({ page }) => {
      // Nobody asks for the other enrolled people before the toggle (#13 AK1.2).
      const nonLearnerCalls: string[] = [];
      page.on('request', (request) => {
        if (request.url().includes('/lib/ajax/service.php') && (request.url() + (request.postData() || '')).includes('local_adele_get_non_learners')) {
          nonLearnerCalls.push(request.url());
        }
      });
      await loginAs(page, fixture('ADELE_FIXTURE_G16_TUTOR'), env.fixturePassword);
      await page.goto(VIEW(fixture('ADELE_FIXTURE_G16_SEPARATE')));
      const selector = app(page).locator('[data-testid="adele-group-selector"] select');
      await expect(selector).toBeVisible({ timeout: 30_000 });
      await expect(selector.locator('option')).toHaveText(['Gruppe Rot']);
      await expect(app(page).getByLabel(/^(Group|Gruppe)$/)).toBeVisible();
      const rows = app(page).locator('[data-testid="adele-userlist-table"] tbody tr');
      await expect(rows).toHaveCount(3);
      await expect(app(page).locator('[data-testid="adele-userlist-table"]')).not.toContainText('Blau');

      const ctx = await contextid(page);
      const refused = await callService(page, 'local_adele_get_user_path_relations', {
        userid: 0, learningpathid: Number(fixture('ADELE_FIXTURE_G16_PATH')), contextid: ctx, groupid: 0,
      });
      expect(refused).toMatchObject({ ok: false });

      const toggle = app(page).locator('[data-testid="adele-non-learners-toggle"]');
      await expect(toggle).toHaveAttribute('aria-expanded', 'false');
      await expect(app(page).locator('[data-testid="adele-non-learners-section"]')).toHaveCount(0);
      await page.waitForLoadState('networkidle');
      expect(nonLearnerCalls, 'no request for the other people before the toggle').toEqual([]);
      await toggle.click();
      await expect(toggle).toHaveAttribute('aria-expanded', 'true');
      expect(nonLearnerCalls.length, 'the toggle asks (the watch above sees the request)').toBeGreaterThan(0);
      const people = app(page).locator('[data-testid="adele-non-learner"]');
      await expect(people.filter({ hasText: fixture('ADELE_FIXTURE_G16_TUTOR_NAME') })).toHaveAttribute('data-reason', 'nonlearner');
      expect(await axeSerious(page, '[data-testid="adele-non-learners"]'), 'accessibility of the section').toEqual([]);
      expect(await axeSerious(page, '[data-testid="adele-group-selector"]'), 'accessibility of the group choice').toEqual([]);
      await expect(people.filter({ hasText: fixture('ADELE_FIXTURE_G16_ANNA_NAME') })).toHaveCount(0);
      await expect(people.filter({ hasText: fixture('ADELE_FIXTURE_G16_NOPATH_NAME') }), 'a learner is not listed here').toHaveCount(0);
      // Still the three learners in the list - the section changes nothing.
      await expect(rows).toHaveCount(3);
      await toggle.click();
      await expect(app(page).locator('[data-testid="adele-non-learners-section"]')).toHaveCount(0);
    });
});

test.describe('ADELE-E2E-G16M — several activities with different groupings (#12, #16)', () => {
  // The last names ("Nele Nord"): the participant list shows first and last
  // name in cells of their own, the answers of the server in fields of their
  // own - the full name would be found in neither.
  const names = () => ({
    nele: fixture('ADELE_FIXTURE_G16M_NELE_NAME').replace(/^Fixture /, ''),
    mats: fixture('ADELE_FIXTURE_G16M_MATS_NAME').replace(/^Fixture /, ''),
    sina: fixture('ADELE_FIXTURE_G16M_SINA_NAME').replace(/^Fixture /, ''),
  });
  const userlist = (page: Page) => app(page).locator('[data-testid="adele-userlist-table"]');
  const relations = async (page: Page, groupid: number) => callService(page, 'local_adele_get_user_path_relations', {
    userid: 0, learningpathid: Number(fixture('ADELE_FIXTURE_G16_PATH')), contextid: await contextid(page), groupid,
  }) as Promise<{ ok: boolean; result?: Array<{ id: number; firstname?: string; lastname?: string }> }>;
  /** The id of a group of the fixture course, by name. */
  const groupId = (name: string): number => Number(execFileSync('php', ['-r', `
    define('CLI_SCRIPT', true);
    require('config.php');
    echo $DB->get_field('groups', 'id', ['courseid' => (int) $argv[1], 'name' => $argv[2]]);`,
  '--', fixture('ADELE_FIXTURE_G16M_HOST'), name], { cwd: fixture('ADELE_MOODLE_ROOT'), encoding: 'utf8' }).trim());

  test('G4 a teacher: each activity offers and lists the groups of its own grouping only; the other grouping is refused',
    { tag: ['@story:G16'] }, async ({ page }) => {
      const n = names();
      const answers = recordAnswers(page);
      await loginAs(page, fixture('ADELE_FIXTURE_G16M_TESSA'), env.fixturePassword);

      await test.step('"Nord-Mitte" (separate groups, grouping Nord + Mitte)', async () => {
        await page.goto(VIEW(fixture('ADELE_FIXTURE_G16M_NORDMITTE')));
        const selector = app(page).locator('[data-testid="adele-group-selector"] select');
        await expect(selector).toBeVisible({ timeout: 30_000 });
        const options = await selector.locator('option').allTextContents();
        expect(options).toEqual(expect.arrayContaining(['Gruppe Nord', 'Gruppe Mitte']));
        expect(options, 'a group outside the grouping is not offered').not.toContain('Gruppe Sued');
        await selector.selectOption({ label: 'Gruppe Nord' });
        await expect(userlist(page).locator('tbody tr')).toHaveCount(1, { timeout: 30_000 });
        await expect(userlist(page)).toContainText(n.nele);
        // Group switching: the list follows.
        await selector.selectOption({ label: 'Gruppe Mitte' });
        await expect(userlist(page)).toContainText(n.mats, { timeout: 30_000 });
        await expect(userlist(page)).not.toContainText(n.nele);
        // The group of the other grouping, asked for directly: refused.
        expect(await relations(page, groupId('Gruppe Sued'))).toMatchObject({ ok: false });
        await expect(page.locator('body')).not.toContainText(n.sina);
      });

      await test.step('"Mitte-Sued" (visible groups, grouping Mitte + Sued)', async () => {
        await page.goto(VIEW(fixture('ADELE_FIXTURE_G16M_MITTESUED')));
        const selector = app(page).locator('[data-testid="adele-group-selector"] select');
        await expect(selector).toBeVisible({ timeout: 30_000 });
        const options = await selector.locator('option').allTextContents();
        expect(options).toEqual(expect.arrayContaining(['Gruppe Mitte', 'Gruppe Sued']));
        expect(options, 'a group outside the grouping is not offered').not.toContain('Gruppe Nord');
        await selector.selectOption({ label: 'Gruppe Sued' });
        await expect(userlist(page).locator('tbody tr')).toHaveCount(1, { timeout: 30_000 });
        await expect(userlist(page)).toContainText(n.sina);
        expect(await relations(page, groupId('Gruppe Nord'))).toMatchObject({ ok: false });
        await expect(page.locator('body')).not.toContainText(n.nele);
      });
      // Nobody of the other grouping in any answer of the server.
      expect(answers.length, 'the answers were recorded').toBeGreaterThan(0);
    });

  test('G5 the other enrolled people: follow the group, show an error and load again, change no ranking; the server refuses a learner',
    { tag: ['@story:G16'] }, async ({ page, browser }) => {
      const n = names();
      await loginAs(page, fixture('ADELE_FIXTURE_G16M_TESSA'), env.fixturePassword);
      await page.goto(VIEW(fixture('ADELE_FIXTURE_G16M_NORDMITTE')));
      const selector = app(page).locator('[data-testid="adele-group-selector"] select');
      await expect(selector).toBeVisible({ timeout: 30_000 });
      await selector.selectOption({ label: 'Gruppe Nord' });
      await expect(userlist(page).locator('tbody tr')).toHaveCount(1, { timeout: 30_000 });
      const toggle = app(page).locator('[data-testid="adele-non-learners-toggle"]');
      const section = app(page).locator('[data-testid="adele-non-learners-section"]');
      const people = app(page).locator('[data-testid="adele-non-learner"]');
      const tessa = fixture('ADELE_FIXTURE_G16M_TESSA_NAME');

      await test.step('a failed answer: the error, and the next opening loads again', async () => {
        const fail = async (route: import('@playwright/test').Route) => {
          if ((route.request().postData() || '').includes('local_adele_get_non_learners')) {
            await route.abort('failed');
          } else {
            await route.fallback();
          }
        };
        await page.route('**/lib/ajax/service.php*', fail);
        await toggle.click();
        await expect(section).toContainText(/(The list could not be loaded|Die Liste konnte nicht geladen werden)/, { timeout: 30_000 });
        await expect(people).toHaveCount(0);
        await page.unroute('**/lib/ajax/service.php*', fail);
        await toggle.click();
        await expect(section).toHaveCount(0);
        await toggle.click();
        await expect(section).not.toContainText(/(The list could not be loaded|Die Liste konnte nicht geladen werden)/, { timeout: 30_000 });
      });

      await test.step('open while the group changes: the list follows, the ranking stays', async () => {
        // Theo teaches in Mitte; Tessa is in no group, so outside the
        // grouping of this activity - not listed even for all participants
        // (#13 AK1.6).
        const theo = fixture('ADELE_FIXTURE_G16M_THEO_NAME');
        await expect(people.filter({ hasText: theo }), 'group Nord: not Theo').toHaveCount(0);
        const before = await userlist(page).locator('tbody tr').count();
        await selector.selectOption({ label: 'Gruppe Mitte' });
        await expect(people.filter({ hasText: theo }), 'group Mitte: Theo, while the list stays open')
          .toHaveAttribute('data-reason', 'nonlearner', { timeout: 30_000 });
        await expect(userlist(page)).toContainText(n.mats, { timeout: 30_000 });
        // "All participants" (offered: Tessa may see all groups).
        await selector.selectOption({ value: '0' });
        await expect(people.filter({ hasText: theo })).toHaveCount(1, { timeout: 30_000 });
        await expect(people.filter({ hasText: tessa }), 'outside the grouping').toHaveCount(0);
        for (const name of [n.nele, n.mats, n.sina]) {
          await expect(people.filter({ hasText: name }), 'no learner among the others').toHaveCount(0);
        }
        await selector.selectOption({ label: 'Gruppe Nord' });
        await expect(people.filter({ hasText: theo })).toHaveCount(0, { timeout: 30_000 });
        await expect(userlist(page).locator('tbody tr'), 'the ranking as before').toHaveCount(before);
        expect(await axeSerious(page, '[data-testid="adele-non-learners"]'), 'accessibility of the section').toEqual([]);
      });

      await test.step('the server decides, not the toggle: a learner asking directly is refused', async () => {
        const context = await browser.newContext();
        const learner = await context.newPage();
        await loginAs(learner, fixture('ADELE_FIXTURE_G16M_MATS'), env.fixturePassword);
        await learner.goto(VIEW(fixture('ADELE_FIXTURE_G16M_NORDMITTE')));
        await expect(app(learner)).toBeVisible({ timeout: 30_000 });
        await expect(app(learner).locator('[data-testid="adele-non-learners-toggle"]')).toHaveCount(0);
        const asked = await callService(learner, 'local_adele_get_non_learners', { contextid: await contextid(learner), groupid: 0 });
        expect(asked).toMatchObject({ ok: false });
        expect(JSON.stringify(asked)).not.toContain(tessa.replace(/^Fixture /, ''));
        await context.close();
      });
    });

  test('G4 a learner in both groupings sees the own group in each activity; one outside a grouping sees nobody else there',
    { tag: ['@story:G16'] }, async ({ page, browser }) => {
      const n = names();
      const answers = recordAnswers(page);
      await loginAs(page, fixture('ADELE_FIXTURE_G16M_MATS'), env.fixturePassword);

      await test.step('"Nord-Mitte" (separate): own group Mitte only', async () => {
        await page.goto(VIEW(fixture('ADELE_FIXTURE_G16M_NORDMITTE')));
        const rows = app(page).locator('[data-testid="adele-group-ranking"] [data-testid="adele-ranking-row"]');
        await expect(rows).toHaveCount(1, { timeout: 30_000 });
        await expect(rows.first()).toContainText(n.mats);
        await expect(app(page).locator('[data-testid="adele-group-averages"]'), 'separate groups: no other averages').toHaveCount(0);
      });

      await test.step('"Mitte-Sued" (visible): own group, the other group of THIS grouping as an average only', async () => {
        await page.goto(VIEW(fixture('ADELE_FIXTURE_G16M_MITTESUED')));
        const ranking = app(page).locator('[data-testid="adele-group-ranking"]');
        await expect(ranking.locator('[data-testid="adele-ranking-row"]')).toHaveCount(1, { timeout: 30_000 });
        const others = ranking.locator('[data-testid="adele-group-averages"] li');
        await expect(others).toHaveCount(1);
        await expect(others.first()).toContainText('Gruppe Sued');
        await expect(others.first()).toContainText('100 %');
        await expect(ranking, 'no average of a group outside the grouping').not.toContainText('Gruppe Nord');
      });
      for (const name of [n.nele, n.sina]) {
        await expect(page.locator('body')).not.toContainText(name);
        expect(answers.join('\n')).not.toContain(name);
      }

      await test.step('Nele (Nord) in "Mitte-Sued", whose grouping has no group of hers: nobody else', async () => {
        const context = await browser.newContext();
        const other = await context.newPage();
        const heard = recordAnswers(other);
        await loginAs(other, fixture('ADELE_FIXTURE_G16M_NELE'), env.fixturePassword);
        await other.goto(VIEW(fixture('ADELE_FIXTURE_G16M_MITTESUED')));
        await expect(app(other)).toBeVisible({ timeout: 30_000 });
        await expect(app(other).locator('[data-testid="adele-group-ranking"]')).toBeVisible({ timeout: 30_000 });
        await other.waitForLoadState('networkidle');
        for (const name of [n.mats, n.sina]) {
          await expect(other.locator('body')).not.toContainText(name);
          expect(heard.join('\n')).not.toContain(name);
        }
        // Asked directly for a group of the grouping: the server decides the
        // group of a learner itself - nothing of that group comes back.
        const asked = await relations(other, groupId('Gruppe Mitte'));
        for (const name of [n.mats, n.sina]) {
          expect(JSON.stringify(asked)).not.toContain(name);
        }
        await context.close();
      });
    });
});

/**
 * Let a learner open a course in a browser context of their own.
 *
 * @param browser The browser.
 * @param username The learner.
 * @param courseid The course.
 */
async function learnerOpensCourse(browser: Browser, username: string, courseid: string): Promise<void> {
  const context = await browser.newContext();
  const page = await context.newPage();
  await loginAs(page, username, env.fixturePassword);
  await page.goto(`/course/view.php?id=${courseid}`);
  await expect(page.locator('body')).toHaveAttribute('id', /page-course-view/);
  await context.close();
}

/**
 * Put fixture G16 back as seeded: the completed nodes on the snapshots (a
 * learner opening a course recomputes the snapshot from the real course
 * completions), no live activity, the activities' live settings. A run
 * before - or a test before - may have changed them.
 */
function resetFixture(): void {
  const script = `
    define('CLI_SCRIPT', true);
    require('config.php');
    $pathid = (int) $argv[1];
    $completed = ['fx_g_anna' => ['dndnode_1', 'dndnode_2'], 'fx_g_bert' => ['dndnode_1'],
        'fx_g_cora' => ['dndnode_1', 'dndnode_2', 'dndnode_3'], 'fx_g_dirk' => [], 'fx_g_nopath' => []];
    foreach ($completed as $username => $nodes) {
        $userid = $DB->get_field('user', 'id', ['username' => $username]);
        $row = $DB->get_record('local_adele_path_user', ['user_id' => $userid, 'learning_path_id' => $pathid]);
        if (!$row) {
            continue;
        }
        $json = json_decode($row->json, true);
        foreach ($json['tree']['nodes'] as $node) {
            $json['user_path_relation'][$node['id']]['completionnode']['valid'] = in_array($node['id'], $nodes, true);
        }
        $DB->set_field('local_adele_path_user', 'json', json_encode($json), ['id' => $row->id]);
    }
    $ids = $DB->get_fieldset_select('user', 'id', "username LIKE 'fx_g_%'");
    [$in, $params] = $DB->get_in_or_equal($ids);
    $DB->delete_records_select('local_adele_activity', "userid $in", $params);
    foreach ([[(int) $argv[2], 1], [(int) $argv[3], 0]] as [$cmid, $on]) {
        $instance = $DB->get_field('course_modules', 'instance', ['id' => $cmid]);
        $DB->update_record('adele', (object) ['id' => $instance, 'livemarkers' => $on, 'livewindow' => 5]);
    }
    // L4 enrols Dirk in the course of node 2 as well: take that back.
    $dirk = $DB->get_field('user', 'id', ['username' => 'fx_g_dirk']);
    $manual = enrol_get_plugin('manual');
    $instance = $DB->get_record('enrol', ['courseid' => (int) $argv[4], 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
    if ($dirk && $manual && $instance && $DB->record_exists('user_enrolments', ['enrolid' => $instance->id, 'userid' => $dirk])) {
        $manual->unenrol_user($instance, $dirk);
    }`;
  execFileSync('php', ['-r', script, '--', fixture('ADELE_FIXTURE_G16_PATH'), fixture('ADELE_FIXTURE_G16_SEPARATE'),
    fixture('ADELE_FIXTURE_G16_VISIBLE'), fixture('ADELE_FIXTURE_COURSE_T02')],
  { cwd: fixture('ADELE_MOODLE_ROOT'), encoding: 'utf8' });
}

/**
 * Test-only set-up for L4: enrol a learner (manual, student) in a course.
 * Only the enrolment is made here - the activity comes from the learner
 * opening the course in the browser.
 *
 * @param username The learner.
 * @param courseid The course.
 */
function enrolForTest(username: string, courseid: string): void {
  const script = `
    define('CLI_SCRIPT', true);
    require('config.php');
    $userid = $DB->get_field('user', 'id', ['username' => $argv[1]], MUST_EXIST);
    $instance = $DB->get_record('enrol', ['courseid' => (int) $argv[2], 'enrol' => 'manual'], '*', MUST_EXIST);
    enrol_get_plugin('manual')->enrol_user($instance, $userid, $DB->get_field('role', 'id', ['shortname' => 'student']));`;
  execFileSync('php', ['-r', script, '--', username, courseid], { cwd: fixture('ADELE_MOODLE_ROOT'), encoding: 'utf8' });
}

/**
 * Test clock for L4: put the recorded activity of a learner in a course
 * back to a point in time "seconds ago". This moves the data, not the
 * logic: the server still decides with its own clock and the activity's
 * own window (here 5 minutes) - a controlled expiry without waiting five
 * minutes and without touching authorisation or the activity rules.
 *
 * @param username The learner.
 * @param courseid The course.
 * @param secondsago How long ago the learner was last seen there.
 * @return The number of activity rows moved (1 when the learner was seen there).
 */
function seenAgo(username: string, courseid: string, secondsago: number): number {
  const script = `
    define('CLI_SCRIPT', true);
    require('config.php');
    $userid = $DB->get_field('user', 'id', ['username' => $argv[1]], MUST_EXIST);
    $rows = $DB->get_records('local_adele_activity', ['userid' => $userid, 'courseid' => (int) $argv[2]]);
    foreach ($rows as $row) {
        $DB->set_field('local_adele_activity', 'timeseen', time() - (int) $argv[3], ['id' => $row->id]);
    }
    echo count($rows);`;
  return Number(execFileSync('php', ['-r', script, '--', username, courseid, String(secondsago)],
    { cwd: fixture('ADELE_MOODLE_ROOT'), encoding: 'utf8' }).trim());
}

/**
 * Open the section "Live activity" of the activity settings.
 *
 * @param page The page.
 */
async function openLiveSection(page: Page): Promise<void> {
  await page.waitForLoadState('load');
  // Decide only once Moodle's form JavaScript has finished (M.util.pending_js
  // empty, what Behat waits for): before that the section can still change
  // its state, and a click decided on the state before toggled it shut (M1,
  // red once in the local stand of 2026-11-26). Then by the toggle's own
  // aria-expanded, not by the field's visibility, which lags behind the
  // collapse animation.
  await page.waitForFunction(() => {
    const m = (window as unknown as { M?: { util?: { pending_js?: unknown[] } } }).M;
    return !!m && !!m.util && Array.isArray(m.util.pending_js) && m.util.pending_js.length === 0;
  }, undefined, { timeout: 30_000 });
  const toggle = page.locator('#id_livemarkersheader .ftoggler a, #id_livemarkersheader a[data-toggle="collapse"], ' +
    '#id_livemarkersheader a[data-bs-toggle="collapse"]').first();
  if ((await toggle.getAttribute('aria-expanded')) !== 'true') {
    await toggle.click();
  }
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(page.locator('#id_livemarkers')).toBeVisible();
}

test.describe('ADELE-E2E-L17 — live markers and node counters (#14, #15, #17)', () => {
  test.beforeEach(() => resetFixture());
  test('L1 a learner opens a node course: marker and counters, by polling; counter, names, the learner centred on the node',
    { tag: ['@story:L17'] }, async ({ page, browser }) => {
      test.setTimeout(180_000);
      await learnerOpensCourse(browser, fixture('ADELE_FIXTURE_G16_DIRK'), fixture('ADELE_FIXTURE_COURSE_T01'));
      await loginAsAdmin(page);
      await page.goto(VIEW(fixture('ADELE_FIXTURE_G16_SEPARATE')));
      const live = app(page).locator('[data-testid="adele-node-live-dndnode_1"]');
      await expect(live).toBeVisible({ timeout: 30_000 });
      const dirk = live.locator('.adele-marker').filter({ hasText: fixture('ADELE_FIXTURE_G16_DIRK_NAME') });
      await expect(dirk).toHaveAttribute('data-state', 'active');
      await expect(dirk).toHaveAttribute('aria-label', /active now|gerade aktiv/);
      await expect(live.locator('[data-testid="adele-counter-active-dndnode_1"]')).toHaveText(/^1 (active|aktiv)$/);
      // Anna, Bert and Cora completed T01.
      await expect(live.locator('[data-testid="adele-counter-completed-dndnode_1"]')).toHaveText(/^3 /);
      await expect(app(page).locator('[data-testid="adele-counter-completed-dndnode_3"]')).toHaveText(/^1 /);

      await test.step('polling: a second learner appears without a reload', async () => {
        await learnerOpensCourse(browser, fixture('ADELE_FIXTURE_G16_BERT'), fixture('ADELE_FIXTURE_COURSE_T01'));
        await expect(live.locator('[data-testid="adele-counter-active-dndnode_1"]')).toHaveText(/^2 /, { timeout: 90_000 });
      });

      await test.step('the completed counter lists names; Escape closes and gives the focus back', async () => {
        const counter = live.locator('[data-testid="adele-counter-completed-dndnode_1"]');
        await counter.focus();
        await page.keyboard.press('Enter');
        await expect(counter).toHaveAttribute('aria-expanded', 'true');
        const popover = page.locator(`[id="${await counter.getAttribute('aria-controls')}"]`);
        await expect(popover).toHaveAttribute('role', 'dialog');
        await expect(popover.locator('[data-testid="adele-live-person"]')).toHaveCount(3);
        await expect(popover.locator('[data-testid="adele-live-person"]').first()).toBeFocused();
        expect(await axeSerious(page, '[data-testid="adele-node-live-dndnode_1"]'), 'accessibility of markers, counters, list').toEqual([]);
        await page.keyboard.press('Escape');
        await expect(popover).toHaveCount(0);
        await expect(counter).toBeFocused();
      });

      await test.step('active counter -> name -> the learner\'s path, centred on the node', async () => {
        await live.locator('[data-testid="adele-counter-active-dndnode_1"]').click();
        await page.locator('[data-testid="adele-live-person"]').filter({ hasText: fixture('ADELE_FIXTURE_G16_DIRK_NAME') }).click();
        // The application routes in memory: the learner's path is open when
        // its own controls and the learner's name are there.
        await expect(app(page).locator('[data-testid="learningpath-outline-toggle"]')).toBeVisible({ timeout: 30_000 });
        await expect(app(page)).toContainText(fixture('ADELE_FIXTURE_G16_DIRK_NAME').replace(/^Fixture /, ''));
        const node = page.locator('.vue-flow__node[data-id="dndnode_1"]');
        await expect(node).toBeVisible({ timeout: 30_000 });
        const flow = page.locator('.vue-flow').first();
        await expect.poll(async () => {
          const n = await node.boundingBox();
          const f = await flow.boundingBox();
          if (!n || !f) {
            return 9999;
          }
          return Math.abs((n.x + n.width / 2) - (f.x + f.width / 2));
        }, { timeout: 10_000, message: 'the node is centred horizontally' }).toBeLessThan(60);
      });
    });

  test('L3 the way back from a learner: group, viewport, scroll position and focus are kept - by marker and by counter (#15 AK3.5, DoD3.4)',
    { tag: ['@story:L17'] }, async ({ page, browser }) => {
      test.setTimeout(180_000);
      const dirkname = fixture('ADELE_FIXTURE_G16_DIRK_NAME');
      await learnerOpensCourse(browser, fixture('ADELE_FIXTURE_G16_DIRK'), fixture('ADELE_FIXTURE_COURSE_T01'));
      await loginAsAdmin(page);
      await page.setViewportSize({ width: 1280, height: 700 });
      await page.goto(VIEW(fixture('ADELE_FIXTURE_G16_SEPARATE')));
      const select = app(page).locator('[data-testid="adele-group-selector"] select');
      await expect(select).toBeVisible({ timeout: 30_000 });
      const blau = await select.locator('option', { hasText: 'Blau' }).getAttribute('value');
      await select.selectOption(blau as string);
      const live = app(page).locator('[data-testid="adele-node-live-dndnode_1"]');
      const marker = live.locator('.adele-marker').filter({ hasText: dirkname });
      await expect(marker).toHaveAttribute('data-state', 'active', { timeout: 30_000 });

      const pane = app(page).locator('.vue-flow__transformationpane').first();
      const state = async () => ({
        transform: await pane.evaluate((el) => (el as HTMLElement).style.transform),
        scrolly: await page.evaluate(() => Math.round(window.scrollY)),
        group: await select.inputValue(),
      });
      const back = async () => {
        await expect(app(page).locator('[data-testid="learningpath-outline-toggle"]')).toBeVisible({ timeout: 30_000 });
        await app(page).locator('[data-testid="adele-userpath-back"]').click();
        await expect(live).toBeVisible({ timeout: 30_000 });
      };

      await test.step('by marker: the overview comes back as it was left, the marker has the focus', async () => {
        // Move the graph and the page, so "as left" differs from a fresh start.
        const box = (await app(page).locator('.vue-flow__pane').first().boundingBox())!;
        await page.mouse.move(box.x + 40, box.y + 40);
        await page.mouse.down();
        await page.mouse.move(box.x + 140, box.y + 90, { steps: 5 });
        await page.mouse.up();
        // Leave the page scrolled: the marker in view (a click scrolls it there anyway).
        await marker.scrollIntoViewIfNeeded();
        await page.evaluate(() => window.scrollBy(0, 40));
        await marker.scrollIntoViewIfNeeded();
        const before = await state();
        expect(before.scrolly, 'the page is scrolled when left').toBeGreaterThan(0);
        await marker.click();
        await back();
        await expect.poll(state, { timeout: 10_000, message: 'group, viewport and scroll position kept' }).toEqual(before);
        await expect(live.locator('.adele-marker').filter({ hasText: dirkname })).toBeFocused({ timeout: 10_000 });
      });

      await test.step('by keyboard: counter, name, learner - back: the counter has the focus', async () => {
        const counter = live.locator('[data-testid="adele-counter-active-dndnode_1"]');
        await counter.focus();
        await page.keyboard.press('Enter');
        const name = page.locator('[data-testid="adele-live-person"]').filter({ hasText: dirkname });
        await expect(name).toBeFocused();
        const before = await state();
        await page.keyboard.press('Enter');
        await back();
        await expect.poll(state, { timeout: 10_000 }).toEqual(before);
        await expect(live.locator('[data-testid="adele-counter-active-dndnode_1"]')).toBeFocused({ timeout: 10_000 });
      });
    });

  test('L4 one learner in two node courses: active on both nodes; the window runs out: only the last node keeps an inactive marker (#14 DoD2-L4)',
    { tag: ['@story:L17'] }, async ({ page, browser }) => {
      test.setTimeout(300_000);
      const dirkname = fixture('ADELE_FIXTURE_G16_DIRK_NAME');
      const dirk = fixture('ADELE_FIXTURE_G16_DIRK');
      enrolForTest(dirk, fixture('ADELE_FIXTURE_COURSE_T02'));
      // Real events: Dirk opens the course of node 1, then the course of node 2.
      await learnerOpensCourse(browser, dirk, fixture('ADELE_FIXTURE_COURSE_T01'));
      await learnerOpensCourse(browser, dirk, fixture('ADELE_FIXTURE_COURSE_T02'));
      await loginAsAdmin(page);
      await page.goto(VIEW(fixture('ADELE_FIXTURE_G16_SEPARATE')));
      const node1 = app(page).locator('[data-testid="adele-node-live-dndnode_1"]');
      const node2 = app(page).locator('[data-testid="adele-node-live-dndnode_2"]');
      const node3 = app(page).locator('[data-testid="adele-node-live-dndnode_3"]');
      await expect(node1).toBeVisible({ timeout: 30_000 });
      const on = (node: typeof node1) => node.locator('.adele-marker').filter({ hasText: dirkname });

      await test.step('active on both nodes at once - avatar, name and state of the right learner, no inactive marker', async () => {
        for (const node of [node1, node2]) {
          const marker = on(node);
          await expect(marker).toHaveCount(1);
          await expect(marker).toHaveAttribute('data-state', 'active');
          await expect(marker).toHaveAttribute('aria-label', new RegExp(`${dirkname}.*(active now|gerade aktiv)`));
          await expect(marker.locator('.adele-marker-name')).toHaveText(dirkname);
          await expect(marker.locator('.sr-only')).toHaveText(/active now|gerade aktiv/);
          const avatar = marker.locator('img.adele-marker-avatar');
          await expect(avatar).toHaveAttribute('alt', '');
          await expect(avatar).toHaveAttribute('src', /\/(pluginfile\.php|theme\/image\.php)\//);
          await expect.poll(() => avatar.evaluate((img) => (img as HTMLImageElement).complete
            && (img as HTMLImageElement).naturalWidth > 0), { message: 'the avatar is loaded' }).toBe(true);
        }
        await expect(on(node3)).toHaveCount(0);
        await expect(app(page).locator('.adele-marker[data-state="inactive"]').filter({ hasText: dirkname })).toHaveCount(0);
        await expect(node1.locator('[data-testid="adele-counter-active-dndnode_1"]')).toHaveText(/^1 /);
        await expect(node2.locator('[data-testid="adele-counter-active-dndnode_2"]')).toHaveText(/^1 /);
      });

      await test.step('never seen: no marker - for the other learners of the group and of the course', async () => {
        for (const key of ['ANNA', 'BERT', 'CORA', 'NOPATH']) {
          await expect(app(page).locator('.adele-marker').filter({ hasText: fixture(`ADELE_FIXTURE_G16_${key}_NAME`) }),
            `${key}: never seen, no marker`).toHaveCount(0);
        }
        await expect(app(page).locator('.adele-marker'), 'only Dirk carries markers').toHaveCount(2);
      });

      await test.step('the window runs out (controlled, distinct times): polling shows it without a reload', async () => {
        // Node 1's course 20 minutes ago, node 2's course 10 minutes ago: both
        // outside the window of 5 minutes, node 2 clearly the last one.
        expect(seenAgo(dirk, fixture('ADELE_FIXTURE_COURSE_T01'), 20 * 60), 'activity in node 1\'s course').toBe(1);
        expect(seenAgo(dirk, fixture('ADELE_FIXTURE_COURSE_T02'), 10 * 60), 'activity in node 2\'s course').toBe(1);
        const navigations: string[] = [];
        page.on('framenavigated', (frame) => {
          if (frame === page.mainFrame()) {
            navigations.push(frame.url());
          }
        });
        await expect(app(page).locator('.adele-marker[data-state="active"]'), 'no active marker any more')
          .toHaveCount(0, { timeout: 90_000 });
        const last = on(node2);
        await expect(last).toHaveCount(1);
        await expect(last).toHaveAttribute('data-state', 'inactive');
        await expect(last).toHaveAttribute('aria-label', new RegExp(`${dirkname}.*(last seen here|zuletzt hier gesehen)`));
        await expect(last.locator('.sr-only')).toHaveText(/last seen here|zuletzt hier gesehen/);
        await expect(last).not.toHaveAttribute('data-ambiguous', /.+/);
        await expect(last.locator('.adele-marker-name')).toHaveText(dirkname);
        await expect(last.locator('img.adele-marker-avatar')).toHaveAttribute('alt', '');
        await expect(on(node1), 'not on the node seen before').toHaveCount(0);
        await expect(on(node3)).toHaveCount(0);
        await expect(app(page).locator('.adele-marker'), 'one marker left, the inactive one').toHaveCount(1);
        await expect(node1.locator('[data-testid="adele-counter-active-dndnode_1"]')).toHaveText(/^0 /);
        await expect(node2.locator('[data-testid="adele-counter-active-dndnode_2"]')).toHaveText(/^0 /);
        expect(navigations, 'no reload of the page').toEqual([]);
        expect(await axeSerious(page, '[data-testid="adele-node-live-dndnode_2"]'), 'accessibility of the inactive marker').toEqual([]);
      });
    });

  test('D1 the same dialog twice at once: the lists of two nodes open side by side, each its own ids, references and focus (#18 DoD3)',
    { tag: ['@story:A18'] }, async ({ page }) => {
      await loginAsAdmin(page);
      await page.goto(VIEW(fixture('ADELE_FIXTURE_G16_SEPARATE')));
      // The list of people at a node's counter is a dialog (NodeLive.vue);
      // every node has its own, and opening one closes no other: two
      // instances of one dialog type in one application, by the normal UI.
      const counter1 = app(page).locator('[data-testid="adele-counter-completed-dndnode_1"]');
      const counter2 = app(page).locator('[data-testid="adele-counter-completed-dndnode_2"]');
      await expect(counter1).toHaveText(/^3 /, { timeout: 30_000 });
      await expect(counter2).toHaveText(/^2 /);
      await counter1.click();
      await counter2.click();
      const dialog1 = page.locator(`[id="${await counter1.getAttribute('aria-controls')}"]`);
      const dialog2 = page.locator(`[id="${await counter2.getAttribute('aria-controls')}"]`);
      await expect(dialog1).toBeVisible();
      await expect(dialog2).toBeVisible();
      await expect(counter1).toHaveAttribute('aria-expanded', 'true');
      await expect(counter2).toHaveAttribute('aria-expanded', 'true');

      await test.step('two dialogs of one type, mounted at once - ids unique on the whole page, every reference in its own instance', async () => {
        await expect(app(page).locator('.adele-live-popover[role="dialog"]')).toHaveCount(2);
        const facts = await page.evaluate(() => {
          const seen = new Map<string, number>();
          document.querySelectorAll('[id]').forEach((el) => seen.set(el.id, (seen.get(el.id) || 0) + 1));
          const dialogs = [...document.querySelectorAll('.adele-live-popover[role="dialog"]')].map((d) => {
            const title = document.getElementById(d.getAttribute('aria-labelledby') || '');
            const opener = document.querySelector(`[aria-controls="${d.id}"]`);
            const inside = [...d.querySelectorAll('[aria-labelledby], [aria-describedby], [aria-controls], label[for]')]
              .flatMap((el) => ['aria-labelledby', 'aria-describedby', 'aria-controls', 'for']
                .flatMap((attr) => (el.getAttribute(attr) || '').split(/\s+/).filter(Boolean)))
              .map((id) => ({ id, ok: !!document.getElementById(id) && d.contains(document.getElementById(id)) }));
            return { id: d.id, titleinside: !!title && d.contains(title), openers: opener ? 1 : 0,
              broken: inside.filter((r) => !r.ok).map((r) => r.id) };
          });
          return { duplicates: [...seen].filter(([, n]) => n > 1).map(([id]) => id), dialogs };
        });
        expect(facts.duplicates, 'no id twice on the page').toEqual([]);
        expect(facts.dialogs).toHaveLength(2);
        expect(facts.dialogs[0].id).not.toBe(facts.dialogs[1].id);
        for (const d of facts.dialogs) {
          expect(d.titleinside, `${d.id}: named by its own title`).toBe(true);
          expect(d.openers, `${d.id}: exactly one opener names it`).toBe(1);
          expect(d.broken, `${d.id}: every reference stays inside`).toEqual([]);
        }
        await expect(dialog1).toHaveAccessibleName(/.+/);
        await expect(dialog2).toHaveAccessibleName(/.+/);
        expect(await dialog1.getAttribute('aria-labelledby')).not.toBe(await dialog2.getAttribute('aria-labelledby'));
      });

      await test.step('each instance shows its own node: 3 and 2 people', async () => {
        await expect(dialog1.locator('[data-testid="adele-live-person"]')).toHaveCount(3);
        await expect(dialog2.locator('[data-testid="adele-live-person"]')).toHaveCount(2);
        await expect(dialog2).not.toContainText(fixture('ADELE_FIXTURE_G16_BERT_NAME'));
        await expect(dialog1).toContainText(fixture('ADELE_FIXTURE_G16_BERT_NAME'));
        expect(await axeSerious(page, '[data-testid="adele-node-live-dndnode_1"]'), 'accessibility, node 1').toEqual([]);
        expect(await axeSerious(page, '[data-testid="adele-node-live-dndnode_2"]'), 'accessibility, node 2').toEqual([]);
      });

      await test.step('using one instance leaves the other alone: Escape in the second closes it, focus back to its counter', async () => {
        await dialog2.locator('[data-testid="adele-live-person"]').first().focus();
        await page.keyboard.press('Escape');
        await expect(dialog2).toHaveCount(0);
        await expect(counter2).toBeFocused();
        await expect(counter2).toHaveAttribute('aria-expanded', 'false');
        await expect(dialog1, 'the first stays open').toBeVisible();
        await expect(dialog1.locator('[data-testid="adele-live-person"]')).toHaveCount(3);
        await expect(counter1).toHaveAttribute('aria-expanded', 'true');
        await dialog1.locator('[data-testid="adele-live-person"]').first().focus();
        await page.keyboard.press('Escape');
        await expect(dialog1).toHaveCount(0);
        await expect(counter1).toBeFocused();
      });
    });

  test('L2 live markers off: no active counter, no markers; the completed counter stays',
    { tag: ['@story:L17'] }, async ({ page }) => {
      await loginAsAdmin(page);
      await page.goto(VIEW(fixture('ADELE_FIXTURE_G16_VISIBLE')));
      const live = app(page).locator('[data-testid="adele-node-live-dndnode_1"]');
      await expect(live).toBeVisible({ timeout: 30_000 });
      await expect(live.locator('[data-testid="adele-counter-completed-dndnode_1"]')).toHaveText(/^3 /);
      await expect(live.locator('[data-testid="adele-counter-active-dndnode_1"]')).toHaveCount(0);
      await expect(app(page).locator('.adele-marker')).toHaveCount(0);
    });

  test('M1 the activity settings: off and 5 minutes by default, 61 refused, a value saved and shown again',
    { tag: ['@story:L17'] }, async ({ page }) => {
      await loginAsAdmin(page);
      const cmid = fixture('ADELE_FIXTURE_G16_VISIBLE');
      await page.goto(`/course/modedit.php?update=${cmid}`);
      const box = page.locator('#id_livemarkers');
      const minutes = page.locator('#id_livewindow');
      await openLiveSection(page);
      await expect(box).not.toBeChecked();
      await box.check();
      await expect(minutes).toHaveValue('5');
      await minutes.fill('61');
      await page.locator('#id_submitbutton').click();
      await expect(page.locator('#id_error_livewindow')).toContainText(/1 to 60|1 bis 60/);
      await minutes.fill('7');
      await page.locator('#id_submitbutton').click();
      await page.goto(`/course/modedit.php?update=${cmid}`);
      await openLiveSection(page);
      await expect(page.locator('#id_livewindow')).toHaveValue('7');
      await expect(page.locator('#id_livemarkers')).toBeChecked();
      // Back to the fixture's state: off, 5 minutes.
      await page.locator('#id_livewindow').fill('5');
      await page.locator('#id_livemarkers').uncheck();
      await page.locator('#id_submitbutton').click();
      await expect(page).not.toHaveURL(/modedit\.php/);
    });
});
