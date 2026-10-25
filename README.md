# future-lms

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
