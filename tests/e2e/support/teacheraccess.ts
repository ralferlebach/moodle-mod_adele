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
 * The teacher view's course access (local_adele #600): the site settings,
 * the activity's mode, the service the view asks, the requests sent - shared
 * by the chains of TP1-TP3.
 *
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { execFileSync } from 'node:child_process';
import { expect, Page } from '@playwright/test';
import { callService, fixture } from './fixtures';

export const SETTINGS = '/admin/settings.php?section=modsettingadele';
export const LABEL = {
  existing_only: 'Existing access only',
  request: 'Request access',
  enrol_student: 'Enrol as participant',
  enrol_teacher: 'Enrol as non-editing teacher',
  enrol_editingteacher: 'Enrol as teacher (with editing rights)',
} as const;
export type Mode = keyof typeof LABEL;

/**
 * As administrator: tick exactly the given modes and set the default, on
 * Moodle's administration page.
 *
 * @param page The page, logged in as administrator.
 * @param modes The modes to allow.
 * @param fallback The default for new activities.
 */
export async function siteAllows(page: Page, modes: Mode[], fallback: Mode): Promise<void> {
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
export async function openForm(page: Page, url: string) {
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
export async function offered(select: ReturnType<Page['locator']>): Promise<string[]> {
  return select.locator('option').evaluateAll((options) =>
    options.map((o) => `${(o as HTMLOptionElement).value}=${(o.textContent || '').trim()}`));
}

/**
 * The mode the teacher view acts with, as the service names it.
 *
 * @param page The page, logged in as a teacher of the activity.
 */
export async function actingMode(page: Page): Promise<string> {
  await page.goto(`/mod/adele/view.php?id=${fixture('ADELE_FIXTURE_KZ_CM')}`);
  const answer = await callService(page, 'mod_adele_get_node_course_access', {
    contextid: Number(fixture('ADELE_FIXTURE_KZ_CM_CONTEXT')),
  });
  expect(answer.error, 'the teacher gets an answer').toBeUndefined();
  return String((answer.data as { mode?: string })?.mode);
}


/**
 * As administrator: set an activity's mode through its settings form.
 *
 * @param page The page, logged in as administrator.
 * @param cmid The activity.
 * @param mode The mode.
 */
export async function setActivityMode(page: Page, cmid: string, mode: Mode): Promise<void> {
  const select = await openForm(page, `/course/modedit.php?update=${cmid}`);
  await select.selectOption(mode);
  await page.getByRole('button', { name: /Save and return to course|Speichern und zum Kurs/i }).click();
  await expect(page).toHaveURL(/\/course\/view\.php/);
}

/** A request for course access as the recipient received it. */
export interface ReceivedRequest {
  from: string;
  subject: string;
  text: string;
}

/**
 * The requests for course access a person has received, read on the server
 * (Moodle's notifications of mod_adele/courseaccessrequest).
 *
 * @param username The recipient.
 */
export function requestsTo(username: string): ReceivedRequest[] {
  const out = execFileSync('php', ['-r', `
    define('CLI_SCRIPT', true);
    require('config.php');
    $to = $DB->get_field('user', 'id', ['username' => $argv[1]], MUST_EXIST);
    $rows = $DB->get_records('notifications', ['useridto' => $to, 'component' => 'mod_adele',
      'eventtype' => 'courseaccessrequest'], 'id ASC');
    $list = [];
    foreach ($rows as $row) {
      $list[] = ['from' => (string) $DB->get_field('user', 'username', ['id' => $row->useridfrom]),
        'subject' => (string) $row->subject, 'text' => (string) $row->fullmessage];
    }
    echo json_encode($list);`,
  '--', username], { cwd: fixture('ADELE_MOODLE_ROOT'), encoding: 'utf8' });
  return JSON.parse(out.trim().split('\n').pop() || '[]');
}

/** The stack of fixture KZ: node 1, courses T01-T03. */
export const KZ_STACK = 'dndnode_1';

/**
 * A course of the KZ stack.
 *
 * @param n 1, 2 or 3.
 */
export const kzCourse = (n: number) => ({
  id: fixture(`ADELE_FIXTURE_KZ_COURSE_${n}`),
  short: fixture(`ADELE_FIXTURE_KZ_COURSE_${n}_SHORT`),
  full: fixture(`ADELE_FIXTURE_KZ_COURSE_${n}_FULL`),
});

/**
 * Open the KZ embedding and activate the stack by a click on its title; the
 * course section once the server has answered.
 *
 * @param page The page, logged in as a teacher of E2EKZ.
 */
export async function activateKzStack(page: Page) {
  await page.goto(`/mod/adele/view.php?id=${fixture('ADELE_FIXTURE_KZ_CM')}`);
  const tile = page.locator(`[data-testid="learningpath-node-${KZ_STACK}"]`);
  await expect(tile).toBeVisible({ timeout: 60_000 });
  await tile.locator('h5').first().click();
  const section = tile.locator('[data-testid="node-course-access"]');
  await expect(section).toBeVisible({ timeout: 30_000 });
  await expect(section.locator('.adele-course-access-note[role="status"]')).toHaveCount(0, { timeout: 30_000 });
  return section;
}
