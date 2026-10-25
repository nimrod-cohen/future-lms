<?php
/**
 * WordPress test config for future-lms.
 *
 * The WP test runner DROPS AND REINSTALLS every table in this database on each
 * run, so it must never point at a real site. The name is fixed here and the
 * run refuses anything that isn't a *_tests database. User, password and host
 * are read from the site's own wp-config.php, so no secrets live in this repo.
 */

$siteRoot = dirname(__DIR__, 5);
$siteConfig = @file_get_contents($siteRoot . '/wp-config.php') ?: '';
$fromSite = function ($name, $default = '') use ($siteConfig) {
  if (preg_match("/define\\(\\s*['\"]" . $name . "['\"]\\s*,\\s*['\"](.*?)['\"]\\s*\\)/", $siteConfig, $m)) {
    return $m[1];
  }
  return $default;
};

define('ABSPATH', getenv('FLMS_WP_CORE') ?: $siteRoot . '/');

define('DB_NAME', getenv('FLMS_TEST_DB') ?: 'future_lms_tests');
define('DB_USER', getenv('FLMS_TEST_DB_USER') ?: $fromSite('DB_USER', 'root'));
define('DB_PASSWORD', getenv('FLMS_TEST_DB_PASSWORD') !== false ? getenv('FLMS_TEST_DB_PASSWORD') : $fromSite('DB_PASSWORD'));
define('DB_HOST', getenv('FLMS_TEST_DB_HOST') ?: $fromSite('DB_HOST', 'localhost'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

if (!preg_match('/_tests$/', DB_NAME)) {
  fwrite(STDERR, "Refusing to run: test database '" . DB_NAME . "' must end in _tests (it gets wiped).\n");
  exit(1);
}

$table_prefix = 'wptests_';

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Future LMS Tests');
define('WP_PHP_BINARY', 'php');
define('WPLANG', '');
define('WP_DEBUG', true);
define('WP_ENVIRONMENT_TYPE', 'development');
