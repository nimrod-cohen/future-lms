# future-lms

## Admin styles and icons

The admin screen (Future LMS in wp-admin) is styled with Tailwind CSS and uses Lucide icons. Both are built from `assets-src/`, and the outputs are committed: `admin/css/admin.css` and `admin/js/icons.js`. `assets-src/` itself is kept out of release zips.

```bash
cd assets-src && npm ci && npm run build
```

- `npm run watch` rebuilds the CSS while you edit.
- Utilities are compiled `!important`, because WordPress's admin CSS is still on the page. There is no global reset, which would break the media library window; app styles are scoped to `#flms-app`.
- **Icons:** write `<i data-lucide="name">` in PHP or `COMMON.icon('name')` in JS. Only the icons the code names are bundled, and an unknown name fails the build. Re-run `npm run build` after adding one.
- **Version:** bump the plugin version when you change these files. It is the browsers' cache-buster.

## Tests

Two suites live in `tests/`. Each has its own dependencies, apart from the plugin's `vendor/` (which ships to production). `.gitattributes` keeps `tests/` out of release zips.

### PHP: rules and AJAX handlers (PHPUnit)

Runs a clean WordPress with only future-lms loaded, in a separate `future_lms_tests` database. The test runner **wipes that database on every run**, so it refuses any name that doesn't end in `_tests`. MySQL user, password and host are read from the site's `wp-config.php`.

One-time setup (from the site root):

```bash
wp db query "CREATE DATABASE IF NOT EXISTS future_lms_tests CHARACTER SET utf8mb4"
```

Run:

```bash
cd tests/php && composer install && vendor/bin/phpunit
```

Covers:
- the class schedule rule (`SchoolClass::is_lesson_released`)
- what a student can open (`Student::is_lesson_open`, with sequential progress)
- the Classes-tab handlers (`set_lesson_open_at`, `set_class_pacing`, `get_lessons`, `search_classes`)
- that students can't call them
- the Courses tab search (`search_catalog`): titles, Hebrew included, course/module/lesson IDs, Vimeo IDs and links (whole IDs only), strongest match first, each item once, drafts in, trash out

Environment overrides: `FLMS_TEST_DB`, `FLMS_TEST_DB_USER`, `FLMS_TEST_DB_PASSWORD`, `FLMS_TEST_DB_HOST`, `FLMS_WP_CORE`.

### Browser: admin screen and student view (Playwright)

Runs against the local site (`http://value.dev.test`, or `FLMS_E2E_URL`). Global setup uses wp-cli to create a tagged course, a paced class, an admin user (`flms-e2e-admin`) and a student (`flms-e2e-student`). Teardown deletes exactly those, so the site's real data is never touched; set `FLMS_E2E_KEEP=1` to leave them in place. Each test user gets a single login session: wp-otp-login allows one per user, and a second one would end the first. The script refuses to run on production.

One-time setup:

```bash
cd tests/e2e && npm ci && npx playwright install chromium
```

Run:

```bash
cd tests/e2e && npm test
```

Covers:
- the full-window admin screen
- the Classes tab: picking a class, lesson status and dates, Open now, the module "Set all" control and its confirmations, clearing a date, switching between self-paced and paced
- a student's lesson list ("ייפתח ב־…" on scheduled lessons)
- the server refusing a lesson that hasn't opened
- the ⌘K search window (opening and closing, titles, IDs, videos, arrows / Enter / ⌘Enter, commands, Enter right after typing)
- the Students search
- the searchable dropdowns (search, keyboard, clear)
- no sideways scrolling at phone width
