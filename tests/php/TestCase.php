<?php

namespace FutureLMS\Tests;

use FutureLMS\FutureLMS;

/**
 * Builds courses the way the admin stores them: posts plus post meta.
 * Course -> Module -> Lesson, and a Class is a cohort of a course.
 */
trait BuildsCourses {
  protected function make_course(array $meta = []) {
    $id = self::factory()->post->create(['post_type' => 'course', 'post_title' => 'Course', 'post_status' => 'publish']);
    foreach ($meta as $k => $v) update_post_meta($id, $k, $v);
    return $id;
  }

  protected function make_module($courseId, $order, array $meta = []) {
    $id = self::factory()->post->create(['post_type' => 'module', 'post_title' => "Module $order", 'post_status' => 'publish']);
    update_post_meta($id, 'course', $courseId);
    update_post_meta($id, 'order', $order);
    update_post_meta($id, 'count_progress', $meta['count_progress'] ?? '0');
    foreach ($meta as $k => $v) update_post_meta($id, $k, $v);
    return $id;
  }

  protected function make_lesson($moduleId, $number) {
    $id = self::factory()->post->create(['post_type' => 'lesson', 'post_title' => "Lesson $number", 'post_status' => 'publish']);
    update_post_meta($id, 'module', $moduleId);
    update_post_meta($id, 'lesson_number', $number);
    update_post_meta($id, 'video_list', '[]');
    return $id;
  }

  /** $lessons is the class's `lessons` list, e.g. [['id' => 5, 'open_at' => '2026-01-01 10:00']]. */
  protected function make_class($courseId, $paced = false, array $lessons = []) {
    $id = self::factory()->post->create(['post_type' => 'class', 'post_title' => 'Class', 'post_status' => 'publish']);
    update_post_meta($id, 'course', $courseId);
    update_post_meta($id, 'paced', $paced ? '1' : '0');
    update_post_meta($id, 'lessons', wp_slash(json_encode($lessons)));
    return $id;
  }

  protected function make_student() {
    return self::factory()->user->create(['role' => 'subscriber']);
  }

  protected function enroll($studentId, $classId) {
    global $wpdb;
    $wpdb->replace(FutureLMS::TABLE_PREFIX() . 'class_to_students', [
      'student_id' => $studentId,
      'class_id' => $classId,
      'registration_date' => current_time('mysql'),
    ]);
  }

  /** A course with one module of $count lessons. Returns [courseId, moduleId, lessonIds]. */
  protected function make_course_with_lessons($count = 3, array $courseMeta = [], array $moduleMeta = []) {
    $course = $this->make_course($courseMeta);
    $module = $this->make_module($course, 1, $moduleMeta);
    $lessons = [];
    for ($i = 1; $i <= $count; $i++) $lessons[] = $this->make_lesson($module, $i);
    return [$course, $module, $lessons];
  }

  /** Site-time "Y-m-d H:i", $days from now. */
  protected function site_time($days) {
    return wp_date('Y-m-d H:i', time() + (int) round($days * DAY_IN_SECONDS));
  }

  protected function class_lessons($classId) {
    return json_decode(get_post_meta($classId, 'lessons', true) ?: '[]', true);
  }
}

abstract class TestCase extends \WP_UnitTestCase {
  use BuildsCourses;
}

abstract class AjaxTestCase extends \WP_Ajax_UnitTestCase {
  use BuildsCourses;

  protected function as_admin() {
    wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
  }

  /**
   * Runs an admin-ajax action and returns the decoded JSON reply.
   *
   * On a live site wp_send_json() ends the request. WP's test handler throws
   * an Exception instead, which handlers wrapped in catch (Exception) would
   * swallow and answer a second time. Ending with an Error mirrors the real
   * exit: nothing in the plugin catches it.
   */
  protected function ajax($action, array $params = []) {
    $_POST = $_REQUEST = $params;
    $stop = function () {
      return function () { throw new AjaxRequestEnded(); };
    };
    add_filter('wp_die_ajax_handler', $stop, 1000);

    ob_start();
    try {
      do_action('wp_ajax_' . $action);
    } catch (AjaxRequestEnded $e) {
      // the handler replied and "exited"
    } finally {
      $output = ob_get_clean();
      remove_filter('wp_die_ajax_handler', $stop, 1000);
    }
    return json_decode($output, true);
  }
}

class AjaxRequestEnded extends \Error {}
