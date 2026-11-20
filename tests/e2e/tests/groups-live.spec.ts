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
 *   L1  a learner opens a node course -> marker and active counter, also by
 *       polling without a reload; counter -> names -> the learner's path,
 *       centred on the node; Escape gives the focus back
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
      for (const other of [fixture('ADELE_FIXTURE_G16_CORA_NAME'), fixture('ADELE_FIXTURE_G16_DIRK_NAME')]) {
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
      await expect(own).toContainText('2 of 2');
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
      await expect(others.first()).toContainText('3 of 3');
      for (const name of [fixture('ADELE_FIXTURE_G16_ANNA_NAME'), fixture('ADELE_FIXTURE_G16_BERT_NAME')]) {
        await expect(page.locator('body')).not.toContainText(name);
        expect(answers.join('\n')).not.toContain(name);
      }
    });

  test('G3 a non-editing teacher sees the own group only; another group is refused; other enrolled people on request',
    { tag: ['@story:G16'] }, async ({ page }) => {
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
      await toggle.click();
      await expect(toggle).toHaveAttribute('aria-expanded', 'true');
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
    }`;
  execFileSync('php', ['-r', script, '--', fixture('ADELE_FIXTURE_G16_PATH'), fixture('ADELE_FIXTURE_G16_SEPARATE'),
    fixture('ADELE_FIXTURE_G16_VISIBLE')], { cwd: fixture('ADELE_MOODLE_ROOT'), encoding: 'utf8' });
}

/**
 * Open the section "Live activity" of the activity settings.
 *
 * @param page The page.
 */
async function openLiveSection(page: Page): Promise<void> {
  await page.waitForLoadState('load');
  // Moodle may open the section by itself (a field with a value set); click only when closed.
  if (!(await page.locator('#id_livemarkers').isVisible())) {
    await page.locator('#id_livemarkersheader .ftoggler a, #id_livemarkersheader a[data-toggle="collapse"], ' +
      '#id_livemarkersheader a[data-bs-toggle="collapse"]').first().click();
  }
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
