<?php
/**
 * E2E fixtures, run through wp-cli on the local site:
 *   wp eval-file wp-fixtures.php setup     -> prints JSON (ids + login cookies)
 *   wp eval-file wp-fixtures.php teardown
 *
 * Everything it creates is tagged (_flms_e2e post meta, flms-e2e-* users) and
 * teardown removes exactly that, so the site's real data is never touched.
 * Each test user gets ONE session shared by both its cookies: wp-otp-login
 * keeps a single session per user, so a second session would end the first.
 */

use FutureLMS\FutureLMS;

if (wp_get_environment_type() === 'production') {
  fwrite(STDERR, "Refusing to run e2e fixtures on production.\n");
  exit(1);
}

const FLMS_E2E_MARK = '_flms_e2e';
$users = ['admin' => 'flms-e2e-admin', 'student' => 'flms-e2e-student'];
$cmd = $args[0] ?? 'setup';

function flms_e2e_teardown($users) {
  global $wpdb;
  require_once ABSPATH . 'wp-admin/includes/user.php';

  $ids = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", FLMS_E2E_MARK));
  foreach ($ids as $id) {
    $wpdb->delete(FutureLMS::TABLE_PREFIX() . 'class_to_students', ['class_id' => $id]);
    wp_delete_post($id, true);
  }
  foreach ($users as $login) {
    $user = get_user_by('login', $login);
    if (!$user) continue;
    $wpdb->delete(FutureLMS::TABLE_PREFIX() . 'class_to_students', ['student_id' => $user->ID]);
    $wpdb->delete(FutureLMS::TABLE_PREFIX() . 'progress', ['user_id' => $user->ID]);
    wp_delete_user($user->ID);
  }
}

function flms_e2e_post($type, $title, array $meta = []) {
  $id = wp_insert_post(['post_type' => $type, 'post_title' => $title, 'post_status' => 'publish'], true);
  if (is_wp_error($id)) throw new Exception($id->get_error_message());
  update_post_meta($id, FLMS_E2E_MARK, '1');
  foreach ($meta as $k => $v) update_post_meta($id, $k, $v);
  return $id;
}

function flms_e2e_user($login, $role) {
  $id = wp_insert_user([
    'user_login' => $login,
    'user_email' => $login . '@example.test',
    'user_pass' => wp_generate_password(32),
    'display_name' => $login,
    'role' => $role,
  ]);
  if (is_wp_error($id)) throw new Exception($id->get_error_message());
  return $id;
}

function flms_e2e_cookies($userId) {
  $exp = time() + 4 * HOUR_IN_SECONDS;
  $token = WP_Session_Tokens::get_instance($userId)->create($exp);
  return [
    ['name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie($userId, $exp, 'logged_in', $token)],
    ['name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie($userId, $exp, 'auth', $token)],
  ];
}

flms_e2e_teardown($users); // start clean even if a previous run died

if ($cmd === 'teardown') {
  echo json_encode(['ok' => true]);
  return;
}

$past = wp_date('Y-m-d H:i', time() - DAY_IN_SECONDS);
$future = '2099-03-04 20:00';

$course = flms_e2e_post('course', 'E2E Course');
$moduleA = flms_e2e_post('module', 'E2E Module A', ['course' => $course, 'order' => 1, 'count_progress' => '0']);
$moduleB = flms_e2e_post('module', 'E2E Module B', ['course' => $course, 'order' => 2, 'count_progress' => '0']);
$lesson = function ($module, $n, $title) {
  return flms_e2e_post('lesson', $title, ['module' => $module, 'lesson_number' => $n, 'video_list' => '[]', 'lesson_duration' => 60]);
};
$a1 = $lesson($moduleA, 1, 'E2E Lesson A1');
update_post_meta($a1, 'video_list', wp_slash(json_encode([['order' => '1', 'caption' => '', 'video_id' => '424242']])));
$a2 = $lesson($moduleA, 2, 'E2E Lesson A2');
$a3 = $lesson($moduleA, 3, 'E2E Lesson A3');
$b1 = $lesson($moduleB, 1, 'E2E Lesson B1');
$b2 = $lesson($moduleB, 2, 'E2E Lesson B2');

$class = flms_e2e_post('class', 'E2E Paced Class', [
  'course' => $course,
  'start_date' => '2026-11-01T20:00',
  'paced' => '1',
  'lessons' => wp_slash(json_encode([
    ['id' => $a1, 'open_at' => $past],
    ['id' => $a2, 'open_at' => $future],
  ])),
]);

$admin = flms_e2e_user($users['admin'], 'administrator');
$student = flms_e2e_user($users['student'], 'student');
(new \FutureLMS\classes\Student($student))->subscribe_to_class($class, true);

echo json_encode([
  'course' => $course,
  'class' => $class,
  'modules' => ['A' => $moduleA, 'B' => $moduleB],
  'lessons' => compact('a1', 'a2', 'a3', 'b1', 'b2'),
  'future' => $future,
  'video' => '424242',
  'cookies' => ['admin' => flms_e2e_cookies($admin), 'student' => flms_e2e_cookies($student)],
]);
