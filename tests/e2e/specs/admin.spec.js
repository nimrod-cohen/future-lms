// @ts-check
const { test, expect } = require('@playwright/test');
const fixtures = require('../fixtures');

const ADMIN = '/wp-admin/admin.php?page=future-lms-settings';

/** Picks an option in one of the searchable dropdowns (the <select> behind it is hidden). */
async function pick(scope, selectSelector, label) {
  const dd = scope.locator(`.flms-dd:has(${selectSelector})`);
  await dd.locator('.flms-dd-trigger').click();
  const search = dd.locator('.flms-dd-search input');
  if (await search.isVisible()) await search.fill(label);
  await dd.locator('.flms-dd-list li', { hasText: label }).first().click();
  await expect(dd.locator('.flms-dd-value')).toHaveText(label);
}

test.describe('admin screen', () => {
  test('runs full-window with a way back to WordPress', async ({ page }) => {
    await page.goto(ADMIN);
    await expect(page.locator('.flms-appbar .flms-name')).toHaveText('Future LMS');
    await expect(page.locator('#wpadminbar')).toBeHidden();
    await expect(page.locator('#adminmenumain')).toBeHidden();
    await expect(page.locator('#wpfooter')).toBeHidden();
    await expect(page.getByRole('link', { name: 'Back to WordPress' })).toHaveAttribute('href', /\/wp-admin\/$/);
    // WordPress's own ⌘K palette must not load here: it would open under ours.
    expect(await page.evaluate(() => !!(window.wp && window.wp.commands))).toBe(false);
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

  const tab = () => page.locator(".flms-panel[data-tab='classes']");
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
    await page.locator(".flms-tab[data-tab='classes']").click();
  });

  test.afterAll(async () => page.close());

  test('picking a course opens its only class', async () => {
    // The course list arrives by AJAX after the page renders.
    await expect(tab().locator('select.courses option', { hasText: 'E2E Course' })).toHaveCount(1);
    await pick(tab(), 'select.courses', 'E2E Course');

    await expect(tab().locator('.class-name')).toHaveText('E2E Paced Class');
    await expect(tab().locator('.class-meta')).toContainText('1 student');
    await expect(tab().locator(".pacing [data-paced='1']")).toHaveAttribute('aria-pressed', 'true');
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
    await tab().locator(".pacing [data-paced='0']").click();
    await settled();

    await expect(tab().locator('.class-message')).toContainText('All 5 lessons are open.');
    await expect(tab().locator('input.open-at')).toHaveCount(0);

    await tab().locator(".pacing [data-paced='1']").click();
    await settled();

    await expect(lessonRow(f.lessons.b1).locator('input.open-at')).not.toHaveValue('');
    await expect(lessonRow(f.lessons.a2).locator('.lesson-status')).toHaveText('No date');
  });
});

test.describe('search window (⌘K)', () => {
  let f;
  let page;
  const palette = () => page.locator('dialog.flms-palette');
  const items = () => palette().locator('.flms-palette-item');
  const results = () => palette().locator('.flms-palette-item:not([data-type="command"])');
  const search = async q => {
    if (!(await palette().isVisible())) await page.locator('.flms-cmdk-trigger').click();
    await palette().locator('input').fill(q);
    await expect(palette().locator('.flms-palette-list')).toHaveAttribute('data-query', q);
  };

  test.beforeEach(async ({ page: p }) => {
    page = p;
    f = fixtures();
    await page.goto(`${ADMIN}#courses`);
    await expect(page.locator(`.course[data-course-id='${f.course}']`)).toBeVisible();
  });

  test('opens from the top bar, ⌘K or "/", and Esc closes it', async () => {
    await page.locator('.flms-cmdk-trigger').click();
    await expect(palette()).toBeVisible();
    await expect(palette().locator('input')).toBeFocused();
    await expect(palette().locator('.flms-palette-group').first()).toHaveText('Jump to');
    await page.keyboard.press('Escape');
    await expect(palette()).toBeHidden();

    await page.keyboard.press('ControlOrMeta+k');
    await expect(palette()).toBeVisible();
    await page.keyboard.press('ControlOrMeta+k');
    await expect(palette()).toBeHidden();

    await page.locator('body').click({ position: { x: 5, y: 300 } });
    await page.keyboard.press('/');
    await expect(palette()).toBeVisible();
  });

  test('a title finds lessons and modules, grouped and highlighted', async () => {
    await search('E2E Lesson B');
    await expect(results()).toHaveCount(2);
    await expect(results().nth(0)).toContainText('E2E Lesson B1');
    await expect(results().nth(0)).toContainText('E2E Course › E2E Module B');
    await expect(results().nth(0).locator('mark')).toHaveText('E2E Lesson B');
    await expect(palette().locator('.flms-palette-group', { hasText: 'Lessons' })).toBeVisible();

    await search('e2e module');
    await expect(results()).toHaveCount(2);
    await expect(results().first()).toHaveAttribute('data-type', 'module');
  });

  test('an id finds that course, module or lesson', async () => {
    for (const [type, id] of [['course', f.course], ['module', f.modules.B], ['lesson', f.lessons.a2]]) {
      await search(String(id));
      const first = results().first();
      await expect(first).toHaveAttribute('data-type', type);
      await expect(first).toHaveAttribute('data-id', String(id));
      await expect(palette().locator('.flms-palette-group').first()).toHaveText('Exact ID');
    }
  });

  test('a Vimeo id or link finds the lessons that play it', async () => {
    await search(`https://vimeo.com/${f.video}`);
    await expect(results()).toHaveCount(1);
    await expect(results().first()).toContainText('E2E Lesson A1');
    await expect(palette().locator('.flms-palette-group').first()).toHaveText(`Lessons playing video ${f.video}`);
  });

  test('arrows and Enter show an item in the tree; ⌘Enter edits it', async () => {
    await search('E2E Lesson A');
    await expect(results()).toHaveCount(3);
    await page.keyboard.press('ArrowDown');
    await expect(results().nth(1)).toHaveClass(/is-active/);
    await page.keyboard.press('Enter');
    await expect(palette()).toBeHidden();
    const lesson = page.locator(`.module-lesson[data-lesson-id='${f.lessons.a2}']`);
    await expect(lesson).toBeVisible();
    await expect(lesson).toHaveClass(/flms-flash/);

    await search(String(f.modules.B));
    await page.keyboard.press('ControlOrMeta+Enter');
    await expect(page.locator('.slideout-panel [data-panel-title]')).toHaveText('Edit Module');
    await expect(page.locator(".slideout-panel input[name='module_name']")).toHaveValue('E2E Module B');
    await page.locator(".slideout-panel button[data-panel-action='cancel']").click();

    await search('E2E Course');
    await results().first().click({ modifiers: ['ControlOrMeta'] });
    await expect(page.locator('.slideout-panel [data-panel-title]')).toHaveText('Edit Course');
    await expect(page.locator(".slideout-panel input[name='course_name']")).toHaveValue('E2E Course');
  });

  test('commands jump between tabs', async () => {
    await search('classes');
    await palette().locator('.flms-palette-item[data-type="command"]', { hasText: 'Go to Classes' }).click();
    await expect(palette()).toBeHidden();
    await expect(page.locator(".flms-panel[data-tab='classes']")).toBeVisible();
  });

  test('Enter right after typing acts on what was typed', async () => {
    await page.locator('.flms-cmdk-trigger').click();
    await palette().locator('input').fill('E2E Lesson A'); // let these results show
    await expect(palette().locator('.flms-palette-list')).toHaveAttribute('data-query', 'E2E Lesson A');
    await palette().locator('input').fill(String(f.modules.B));
    await page.keyboard.press('Enter'); // before the search delay is up
    await expect(palette()).toBeHidden();
    await expect(page.locator(`.course-module[data-module-id='${f.modules.B}'] > .flms-module-head`)).toHaveClass(/flms-flash/);
  });

  test('clicking the field while the window is closing opens it again', async () => {
    await page.locator('.flms-cmdk-trigger').click();
    await expect(palette()).toBeVisible();
    await page.evaluate(() => {
      window.adminManager.palette.close();
      window.adminManager.palette.open(); // mid-animation
    });
    await page.waitForTimeout(500); // past the close animation
    await expect(palette()).toBeVisible();
    await palette().locator('input').fill('E2E Course');
    await expect(palette().locator('.flms-palette-list')).toHaveAttribute('data-query', 'E2E Course');
  });

  test('says when nothing matches', async () => {
    await search('zz-no-such-title');
    await expect(palette().locator('.flms-palette-empty')).toContainText('Nothing matches “zz-no-such-title”');
    await expect(items()).toHaveCount(0);
  });
});

test.describe('students tab', () => {
  test('lists the students of a course', async ({ page }) => {
    await page.goto(`${ADMIN}#students`);
    const tab = page.locator(".flms-panel[data-tab='students']");
    await expect(tab.locator('select.courses option', { hasText: 'E2E Course' })).toHaveCount(1);
    await pick(tab, 'select.courses', 'E2E Course');
    await expect(tab.locator('select.classes option', { hasText: 'E2E Paced Class' })).toHaveCount(1);
    await expect(tab.locator('.add-student')).toBeDisabled();
    await pick(tab, 'select.classes', 'E2E Paced Class');
    await expect(tab.locator('.add-student')).toBeEnabled();

    await tab.locator('.search-students').click();
    await expect(tab.locator('.result-count')).toHaveText('1 student');
    await expect(tab.locator('table.students tbody tr[data-id]')).toContainText('flms-e2e-student');
  });
});

test.describe('dropdowns', () => {
  test('search, highlight, keyboard and clear', async ({ page }) => {
    await page.goto(`${ADMIN}#students`);
    const tab = page.locator(".flms-panel[data-tab='students']");
    const dd = tab.locator('.flms-dd:has(select.courses)');
    await expect(tab.locator('select.courses option', { hasText: 'E2E Course' })).toHaveCount(1);

    await dd.locator('.flms-dd-trigger').click();
    const search = dd.locator('.flms-dd-search input');
    await expect(search).toBeFocused();
    await search.fill('e2e cou');
    await expect(dd.locator('.flms-dd-list li')).toHaveCount(1);
    await expect(dd.locator('.flms-dd-list li mark')).toHaveText('E2E Cou');

    await search.press('Enter');
    await expect(dd.locator('.flms-dd-panel')).toBeHidden();
    await expect(dd.locator('.flms-dd-value')).toHaveText('E2E Course');
    await expect(tab.locator('select.courses')).not.toHaveValue('');

    await dd.locator('.flms-dd-trigger').press('ArrowDown');
    await expect(dd.locator('.flms-dd-panel')).toBeVisible();
    await expect(search).toHaveValue(''); // reopening starts with an empty search
    await page.keyboard.press('Escape');
    await expect(dd.locator('.flms-dd-panel')).toBeHidden();
    await expect(dd.locator('.flms-dd-trigger')).toBeFocused();

    await dd.locator('.flms-dd-clear').click();
    await expect(dd.locator('.flms-dd-value')).toHaveText('All courses');
    await expect(tab.locator('.flms-dd:has(select.classes) .flms-dd-trigger')).toBeDisabled();
  });

  test('no match says so', async ({ page }) => {
    await page.goto(`${ADMIN}#students`);
    const dd = page.locator(".flms-panel[data-tab='students'] .flms-dd:has(select.courses)");
    await expect(page.locator(".flms-panel[data-tab='students'] select.courses option", { hasText: 'E2E Course' })).toHaveCount(1);
    await dd.locator('.flms-dd-trigger').click();
    await dd.locator('.flms-dd-search input').fill('zzzz-nothing');
    await expect(dd.locator('.flms-dd-empty')).toHaveText('No matches for “zzzz-nothing”');
  });
});

test.describe('on a phone', () => {
  test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

  test('no tab scrolls sideways', async ({ page }) => {
    await page.goto(ADMIN);
    for (const name of ['students', 'courses', 'classes', 'mailer', 'settings']) {
      await page.locator(`.flms-tab[data-tab='${name}']`).click();
      await expect(page.locator(`.flms-panel[data-tab='${name}']`)).toBeVisible();
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      expect(overflow, `${name} tab overflows`).toBeLessThanOrEqual(0);
    }
  });
});
