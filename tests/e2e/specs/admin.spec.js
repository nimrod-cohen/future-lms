// @ts-check
const { test, expect } = require('@playwright/test');
const fixtures = require('../fixtures');

const ADMIN = '/wp-admin/admin.php?page=future-lms-settings';

test.describe('admin screen', () => {
  test('runs full-window with a way back to WordPress', async ({ page }) => {
    await page.goto(ADMIN);
    await expect(page.locator('.flms-appbar .flms-name')).toHaveText('Future LMS');
    await expect(page.locator('#wpadminbar')).toBeHidden();
    await expect(page.locator('#adminmenumain')).toBeHidden();
    await expect(page.locator('#wpfooter')).toBeHidden();
    await expect(page.getByRole('link', { name: 'Back to WordPress' })).toHaveAttribute('href', /\/wp-admin\/$/);
  });
});

/**
 * The Classes tab, one step after another on the fixture class:
 * A1 open (yesterday), A2 scheduled (2099-03-04 20:00), A3 / B1 / B2 undated.
 */
test.describe.serial('classes tab', () => {
  let f; // read in beforeAll: test files are collected before global setup runs
  /** @type {import('@playwright/test').Page} */
  let page;

  const tab = () => page.locator(".tab[data-tab='classes']");
  const lessonRow = id => tab().locator(`tr.lesson-row:has(input.open-at[data-lessons='${id}'])`);
  const moduleRow = ids => tab().locator(`tr.module-row:has(input.open-at[data-lessons='${ids.join(',')}'])`);
  const summary = () => tab().locator('.schedule-summary');
  const moduleA = () => [f.lessons.a1, f.lessons.a2, f.lessons.a3];
  const moduleB = () => [f.lessons.b1, f.lessons.b2];

  // Each save re-renders the table; wait for the panel to finish.
  const settled = async () => expect(tab().locator('.class-panel')).not.toHaveClass(/is-busy/);

  const setDate = async (input, value) => {
    await input.fill(value);
    await input.dispatchEvent('change');
    await settled();
  };

  test.beforeAll(async ({ browser }) => {
    f = fixtures();
    page = await browser.newPage({ storageState: '.auth/admin.json' });
    await page.goto(ADMIN);
    await page.locator(".tabular.menu .item[data-tab='classes']").click();
  });

  test.afterAll(async () => page.close());

  test('picking a course opens its only class', async () => {
    // The course list arrives by AJAX after the page renders.
    const option = tab().locator('.ui.dropdown.courses .menu .item', { hasText: 'E2E Course' });
    await expect(option).toHaveCount(1);
    await tab().locator('.ui.dropdown.courses').click();
    await option.click();

    await expect(tab().locator('.class-name')).toHaveText('E2E Paced Class');
    await expect(tab().locator('.class-meta')).toContainText('1 student');
    await expect(tab().locator(".pacing .button[data-paced='1']")).toHaveClass(/active/);
    await settled();
    await expect(summary()).toHaveText(/1\s*open\s*1\s*scheduled\s*3\s*without a date/);
  });

  test('each lesson shows its status and date', async () => {
    await expect(lessonRow(f.lessons.a1).locator('.lesson-status')).toHaveText('Open');
    await expect(lessonRow(f.lessons.a2).locator('.lesson-status')).toHaveText('Scheduled');
    await expect(lessonRow(f.lessons.a2).locator('input.open-at')).toHaveValue('2099-03-04T20:00');
    await expect(lessonRow(f.lessons.a3).locator('.lesson-status')).toHaveText('No date');

    await expect(lessonRow(f.lessons.a1).locator('.open-now')).toHaveCount(0);
    await expect(lessonRow(f.lessons.a3).locator('.open-now')).toBeVisible();
    await expect(moduleRow(moduleA()).locator('.set-all-label')).toHaveText('Set all 3 lessons · their dates differ');
  });

  test('Open now opens an undated lesson right away', async () => {
    await lessonRow(f.lessons.a3).locator('.open-now').click();
    await settled();

    await expect(lessonRow(f.lessons.a3).locator('.lesson-status')).toHaveText('Open');
    await expect(lessonRow(f.lessons.a3).locator('input.open-at')).not.toHaveValue('');
    await expect(summary()).toHaveText(/2\s*open\s*1\s*scheduled\s*2\s*without a date/);
  });

  test('a lesson gets its own date', async () => {
    await setDate(lessonRow(f.lessons.b1).locator('input.open-at'), '2099-06-01T09:30');

    await expect(lessonRow(f.lessons.b1).locator('.lesson-status')).toHaveText('Scheduled');
    await expect(lessonRow(f.lessons.b1).locator('input.open-at')).toHaveValue('2099-06-01T09:30');
  });

  test('setting all lessons asks first when their dates differ, and Cancel changes nothing', async () => {
    let asked = '';
    page.once('dialog', d => {
      asked = d.message();
      d.dismiss();
    });

    await setDate(moduleRow(moduleB()).locator('input.open-at'), '2099-07-01T20:00');

    expect(asked).toBe('1 lesson in this module has its own date. Replace it with 1 Jul 2099, 20:00?');
    await expect(lessonRow(f.lessons.b1).locator('input.open-at')).toHaveValue('2099-06-01T09:30');
    await expect(lessonRow(f.lessons.b2).locator('input.open-at')).toHaveValue('');
  });

  test('confirming sets every lesson in the module', async () => {
    page.once('dialog', d => d.accept());

    await setDate(moduleRow(moduleB()).locator('input.open-at'), '2099-07-01T20:00');

    for (const id of moduleB()) {
      await expect(lessonRow(id).locator('input.open-at')).toHaveValue('2099-07-01T20:00');
      await expect(lessonRow(id).locator('.lesson-status')).toHaveText('Scheduled');
    }
    await expect(moduleRow(moduleB()).locator('.set-all-label')).toHaveText('Set all 2 lessons');
  });

  test('changing a date the whole module shares does not ask', async () => {
    let asked = false;
    const onDialog = d => {
      asked = true;
      d.dismiss();
    };
    page.on('dialog', onDialog);

    await setDate(moduleRow(moduleB()).locator('input.open-at'), '2099-08-01T20:00');

    page.off('dialog', onDialog);
    expect(asked).toBe(false);
    for (const id of moduleB()) {
      await expect(lessonRow(id).locator('input.open-at')).toHaveValue('2099-08-01T20:00');
    }
  });

  test('Open all now asks before opening scheduled lessons', async () => {
    let asked = '';
    page.once('dialog', d => {
      asked = d.message();
      d.accept();
    });

    await moduleRow(moduleB()).locator('.open-now').click();
    await settled();

    expect(asked).toBe('2 of these lessons are scheduled for later. Open them now anyway?');
    for (const id of moduleB()) {
      await expect(lessonRow(id).locator('.lesson-status')).toHaveText('Open');
    }
  });

  test('clearing a lesson date closes it again', async () => {
    await lessonRow(f.lessons.a2).locator('.clear-open-at').click();
    await settled();

    await expect(lessonRow(f.lessons.a2).locator('.lesson-status')).toHaveText('No date');
    await expect(lessonRow(f.lessons.a2).locator('input.open-at')).toHaveValue('');
  });

  test('self-paced opens everything and hides the dates; paced brings them back', async () => {
    await tab().locator(".pacing .button[data-paced='0']").click();
    await settled();

    await expect(tab().locator('.class-message')).toContainText('All 5 lessons are open.');
    await expect(tab().locator('input.open-at')).toHaveCount(0);

    await tab().locator(".pacing .button[data-paced='1']").click();
    await settled();

    await expect(lessonRow(f.lessons.b1).locator('input.open-at')).not.toHaveValue('');
    await expect(lessonRow(f.lessons.a2).locator('.lesson-status')).toHaveText('No date');
  });
});
