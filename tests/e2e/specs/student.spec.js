// @ts-check
const { test, expect } = require('@playwright/test');
const fixtures = require('../fixtures');

/**
 * A student in a paced class: A1 opened yesterday, A2 opens in 2099, the rest
 * have no date (closed). The lesson list must show that, and the server must
 * refuse a lesson that hasn't opened even when asked for it directly.
 */
test.describe('student in a paced class', () => {
  // Read once setup has run (test files are collected before global setup).
  let f;
  test.beforeAll(() => {
    f = fixtures();
  });

  test.beforeEach(async ({ page }) => {
    await page.goto('/lobby/');
    const card = page.locator(`.course-card[data-course-id='${f.course}']`);
    await expect(card).toBeVisible();
    await card.locator('button.enter-course').click();
    await expect(page.locator(`.sidebar-lesson[lesson-id='${f.lessons.a1}']`)).toBeAttached();
  });

  test('lesson list shows what is open, what is scheduled and when', async ({ page }) => {
    const row = id => page.locator(`.sidebar-lesson[lesson-id='${id}']`);

    await expect(row(f.lessons.a1)).not.toHaveClass(/locked/);

    await expect(row(f.lessons.a2)).toHaveClass(/locked/);
    // 2099-03-04 20:00, not this year, so the year is shown.
    await expect(row(f.lessons.a2).locator('.opens-at')).toHaveText('ייפתח ב־4.3.2099 בשעה 20:00');

    for (const id of [f.lessons.a3, f.lessons.b1, f.lessons.b2]) {
      await expect(row(id)).toHaveClass(/locked/);
      await expect(row(id).locator('.opens-at')).toHaveCount(0); // no date to announce
    }
  });

  test('server refuses a lesson that has not opened yet', async ({ page }) => {
    const ask = lessonId =>
      page.request
        .post('/wp-admin/admin-ajax.php', {
          form: { action: 'get_lesson_content', course_id: String(f.course), lesson_id: String(lessonId) },
        })
        .then(r => r.json());

    expect((await ask(f.lessons.a1)).error).toBeUndefined();
    expect((await ask(f.lessons.a2)).error).toBe('Failed to load class');
    expect((await ask(f.lessons.a3)).error).toBe('Failed to load class');
  });
});
