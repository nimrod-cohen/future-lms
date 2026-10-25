<?php
/**
 * Boots a clean WordPress (its own *_tests database) with only future-lms
 * loaded - no other plugins, no real site data.
 */

require __DIR__ . '/vendor/autoload.php';

define('WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php');

$testsDir = __DIR__ . '/vendor/wp-phpunit/wp-phpunit';
require $testsDir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', function () {
  // A real request has this by the time plugins load; the plugin's updater uses it.
  require_once ABSPATH . 'wp-admin/includes/plugin.php';
  require dirname(__DIR__, 2) . '/future-lms.php';
  // The plugin keeps its instance in a global; required from here it would be local.
  $GLOBALS['futureLms'] = $futureLms;

  // A fresh install creates the plugin's tables one version step per page
  // load. Run the steps out now so every table exists before the tests.
  for ($i = 0; $i < 20; $i++) {
    $before = get_option(\FutureLMS\classes\VersionManager::SCHOOL_VERSION);
    \FutureLMS\classes\VersionManager::install_version();
    if (get_option(\FutureLMS\classes\VersionManager::SCHOOL_VERSION) === $before) break;
  }
});

require $testsDir . '/includes/bootstrap.php';
require __DIR__ . '/TestCase.php';
