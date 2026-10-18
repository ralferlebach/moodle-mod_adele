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
 * teacher-course-access-request - "Request access" in the teacher view of
 * mod_adele (local_adele #600, TP2).
 *
 * Fixture KZ: the stack T01-T03; the teacher without editing rights is in
 * T01 and T03, not in T02; T02 has one person responsible, the editing
 * teacher "Fixture Access Owner" (and nobody else who may enrol). Each test
 * sets the site and the activity to "Request access" through Moodle's own
 * pages; the seed puts both back.
 *
 *   K9   ask: the dialog names the owner and prefills the message; edited
 *        and sent, the owner receives exactly that text - and the teacher is
 *        not enrolled (AK-B2, AK-E4)
 *   K10  opened, edited, cancelled (Escape and Cancel): nothing is sent; the
 *        focus goes back (AK-E4 negative, AK-H3/H4)
 *   K11  T02 loses its owner: nobody to ask - no "Send", the site's contact
 *        and the text to copy (AK-B2 fallback)
 *   K12  forged calls are refused and send nothing; the site takes the mode
 *        away: no request any more (AK-D1, AK-D2, AK-E7 for TP2)
 *
 * @copyright   2026 Wunderbyte GmbH
 * @copyright   2026 Ralf Erlebach
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { test, expect, Page } from '@playwright/test';
import { env, loginAs } from '../support/env';
import { callService, fixture, fixturePassword, reseedFixtures, unenrolFromCourse } from '../support/fixtures';
import {
  activateKzStack, kzCourse, requestsTo, setActivityMode, siteAllows,
} from '../support/teacheraccess';

const OWNER = 'Fixture Access Owner';

/**
 * As administrator: "Request access" allowed on the site and chosen for the
 * KZ activity.
 *
 * @param page The page.
 */
async function requestMode(page: Page): Promise<void> {
  await loginAs(page, env.adminUser, env.adminPassword);
  await siteAllows(page, ['existing_only', 'request'], 'existing_only');
  await setActivityMode(page, fixture('ADELE_FIXTURE_KZ_CM'), 'request');
}

/**
 * The row of T02 and its request button, as the teacher without editing
 * rights sees them after activating the stack.
 *
 * @param page The page.
 */
async function t02(page: Page) {
  await loginAs(page, fixture('ADELE_FIXTURE_KZ_TUTOR'), fixturePassword());
  const section = await activateKzStack(page);
  const row = section.locator(`[data-testid="node-course-${kzCourse(2).id}"]`);
  return { section, row, button: row.locator(`[data-testid="node-course-request-${kzCourse(2).id}"]`) };
}

test.beforeEach(() => reseedFixtures());

test.describe('ADELE-E2E-K — "Request access" in the teacher view (TP2)', () => {
  test('K9: the teacher asks the owner of T02 - edited, sent, received as written; nobody enrolled', { tag: ['@story:K9'] }, async ({ page }) => {
    const two = kzCourse(2);
    const owner = fixture('ADELE_FIXTURE_KZ_OWNER');
    const tutor = fixture('ADELE_FIXTURE_KZ_TUTOR');
    await requestMode(page);

    const { section, row, button } = await t02(page);
    await test.step('each row its own: T01/T03 open with a link, T02 "you can ask" with a button', async () => {
      await expect(row.locator('.adele-course-access-status')).toHaveText('No access – you can ask');
      await expect(row.locator('a')).toHaveCount(0);
      await expect(button).toHaveAccessibleName(`Request access to the course ${two.short} – ${two.full}`);
      for (const n of [1, 3]) {
        await expect(section.locator(`[data-testid="node-course-${kzCourse(n).id}"] a`)).toHaveCount(1);
      }
    });

    const dialog = page.getByRole('dialog');
    await test.step('the dialog: named, the owner as recipient, the message prefilled - nothing sent yet', async () => {
      await button.click();
      await expect(dialog).toHaveAccessibleName(`Request access to ${two.full}`);
      await expect(dialog.locator('[data-testid="access-request-recipient"]')).toHaveText(OWNER);
      const message = dialog.getByRole('textbox', { name: 'Message' });
      await expect(message).toBeFocused();
      const draft = await message.inputValue();
      for (const part of ['E2E host course E2EKZ', 'Verzweigt, gestapelt B1', two.full]) {
        expect(draft).toContain(part);
      }
      expect(requestsTo(owner), 'nothing before "Send"').toHaveLength(0);
      await message.fill(`${draft} (K9)`);
    });

    await test.step('Send: the dialog closes, the row says so, the focus is back on the button', async () => {
      await dialog.getByRole('button', { name: 'Send' }).click();
      await expect(dialog).toHaveCount(0);
      await expect(row.locator('.adele-course-access-status')).toHaveText('Request sent');
      await expect(button).toBeFocused();
    });

    await test.step('the owner received exactly that, from the teacher', async () => {
      const received = requestsTo(owner);
      expect(received).toHaveLength(1);
      expect(received[0].from).toBe(tutor);
      expect(received[0].subject).toContain(two.full);
      expect(received[0].text).toContain('(K9)');
      await loginAs(page, owner, fixturePassword());
      await page.goto('/message/output/popup/notifications.php');
      await expect(page.getByText(received[0].subject).first()).toBeVisible({ timeout: 30_000 });
    });

    await test.step('a request is no enrolment: activated again, T02 is still without access', async () => {
      const again = await t02(page);
      await expect(again.row.locator('.adele-course-access-status')).toHaveText('No access – you can ask');
      await expect(again.row.locator('a')).toHaveCount(0);
    });
  });

  test('K10: opened, edited, cancelled - nothing is sent, the focus goes back', { tag: ['@story:K10'] }, async ({ page }) => {
    const owner = fixture('ADELE_FIXTURE_KZ_OWNER');
    await requestMode(page);
    const { button } = await t02(page);
    const dialog = page.getByRole('dialog');

    await test.step('Escape', async () => {
      await button.click();
      await dialog.getByRole('textbox', { name: 'Message' }).fill('Not to be sent (Escape)');
      await page.keyboard.press('Escape');
      await expect(dialog).toHaveCount(0);
      await expect(button).toBeFocused();
      expect(requestsTo(owner), 'opened and cancelled: nothing sent').toHaveLength(0);
    });

    await test.step('Tab stays in the dialog; Cancel', async () => {
      await page.keyboard.press('Enter');
      await expect(dialog).toBeVisible();
      for (let i = 0; i < 6; i++) {
        await page.keyboard.press('Tab');
        expect(await dialog.evaluate((el) => el.contains(document.activeElement))).toBe(true);
      }
      await dialog.getByRole('button', { name: 'Cancel' }).click();
      await expect(dialog).toHaveCount(0);
      await expect(button).toBeFocused();
    });

    await test.step('the owner received nothing', async () => {
      expect(requestsTo(owner)).toHaveLength(0);
    });
  });

  test('K11: T02 loses its owner - nobody to ask: no "Send", the site\'s contact and the text to copy', { tag: ['@story:K11', '@withdrawal'] }, async ({ page }) => {
    const two = kzCourse(2);
    await requestMode(page);
    await test.step('the administrator names a contact and takes the owner out of T02', async () => {
      await page.goto('/admin/settings.php?section=modsettingadele');
      await page.locator('#id_s_mod_adele_teachercourseaccessrequestfallback').fill('Please write to e2e-support@example.invalid');
      await page.getByRole('button', { name: /Save changes|Änderungen speichern/ }).click();
      await unenrolFromCourse(page, two.id, OWNER);
    });

    const { row, button } = await t02(page);
    await test.step('the row: "nobody to ask", the button prepares', async () => {
      await expect(row.locator('.adele-course-access-status')).toHaveText('No access – nobody to ask');
      await expect(button).toHaveText('Prepare request');
    });

    await test.step('the dialog: no Send, the contact, the message to copy', async () => {
      await button.click();
      const dialog = page.getByRole('dialog');
      await expect(dialog.getByRole('button', { name: 'Send' })).toHaveCount(0);
      await expect(dialog.locator('[data-testid="access-request-fallback"]')).toContainText('e2e-support@example.invalid');
      await expect(dialog.getByRole('textbox', { name: 'Message' })).toHaveValue(new RegExp(two.full));
      await expect(dialog.getByRole('button', { name: 'Copy message' })).toBeVisible();
      await dialog.getByRole('button', { name: 'Close' }).click();
      await expect(button).toBeFocused();
    });
  });

  test('K12: forged calls are refused and send nothing; the mode taken away - no request any more', { tag: ['@story:K12', '@withdrawal'] }, async ({ page }) => {
    const owner = fixture('ADELE_FIXTURE_KZ_OWNER');
    const ownerid = Number(fixture('ADELE_FIXTURE_KZ_OWNER_ID'));
    const contextid = Number(fixture('ADELE_FIXTURE_KZ_CM_CONTEXT'));
    const send = (courseid: string | number, recipientid: number) => callService(page, 'mod_adele_send_access_request', {
      contextid, courseid: Number(courseid), recipientid, message: 'forged',
    });
    await requestMode(page);

    await test.step('as the teacher: wrong recipient, a course outside the path, a course already open - refused', async () => {
      await loginAs(page, fixture('ADELE_FIXTURE_KZ_TUTOR'), fixturePassword());
      await page.goto(`/mod/adele/view.php?id=${fixture('ADELE_FIXTURE_KZ_CM')}`);
      expect((await send(kzCourse(2).id, Number(fixture('ADELE_FIXTURE_KZ_LEARNER_ID')))).error).toBe('accessrequest_norecipient');
      expect((await send(fixture('ADELE_FIXTURE_KZ_HOST'), ownerid)).error).toBe('accessrequest_notinpath');
      expect((await send(kzCourse(1).id, ownerid)).error).toBe('accessrequest_hasaccess');
    });

    await test.step('as a learner of the course: refused', async () => {
      await loginAs(page, fixture('ADELE_FIXTURE_KZ_LEARNER'), fixturePassword());
      await page.goto(`/course/view.php?id=${fixture('ADELE_FIXTURE_KZ_HOST')}`);
      expect((await send(kzCourse(2).id, ownerid)).error).toBe('nopermissions');
    });

    await test.step('the site takes "Request access" away: no button, and the service refuses', async () => {
      await loginAs(page, env.adminUser, env.adminPassword);
      await siteAllows(page, ['existing_only'], 'existing_only');
      const { row, button } = await t02(page);
      await expect(row.locator('.adele-course-access-status')).toHaveText('No course access');
      await expect(button).toHaveCount(0);
      expect((await send(kzCourse(2).id, ownerid)).error).toBe('accessrequest_notallowed');
    });

    await test.step('none of it reached the owner', async () => {
      expect(requestsTo(owner)).toHaveLength(0);
    });
  });
});
