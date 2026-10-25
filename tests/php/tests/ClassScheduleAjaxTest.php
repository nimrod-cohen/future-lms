<?php

use FutureLMS\Tests\AjaxTestCase;

/**
 * The admin Classes tab talks to these handlers: pacing, lesson dates,
 * the lesson list and the class picker.
 *
 * @group ajax
 */
class ClassScheduleAjaxTest extends AjaxTestCase {

  // --- set_lesson_open_at ---------------------------------------------------

  public function test_sets_one_lesson_date_from_a_datetime_local_value() {
    $this->as_admin();
    [$course, , $lessons] = $this->make_course_with_lessons(2);
    $class = $this->make_class($course, true);

    $res = $this->ajax('set_lesson_open_at', ['class_id' => $class, 'lesson_ids' => $lessons[0], 'open_at' => '2026-12-01T20:00']);

    $this->assertTrue($res['success']);
    $this->assertSame([['id' => $lessons[0], 'open_at' => '2026-12-01 20:00']], $this->class_lessons($class));
  }

  public function test_sets_a_whole_module_at_once() {
    $this->as_admin();
    [$course, , $lessons] = $this->make_course_with_lessons(3);
    $class = $this->make_class($course, true, [['id' => $lessons[1], 'open_at' => '2026-01-01 09:00']]);

    $this->ajax('set_lesson_open_at', ['class_id' => $class, 'lesson_ids' => implode(',', $lessons), 'open_at' => '2026-12-01T20:00']);

    $stored = $this->class_lessons($class);
    $this->assertCount(3, $stored, 'one entry per lesson, the old one replaced not duplicated');
    foreach ($stored as $entry) $this->assertSame('2026-12-01 20:00', $entry['open_at']);
    $this->assertEqualsCanonicalizing($lessons, array_column($stored, 'id'));
  }

  public function test_now_opens_lessons_at_the_current_site_minute() {
    $this->as_admin();
    [$course, , $lessons] = $this->make_course_with_lessons(1);
    $class = $this->make_class($course, true, [['id' => $lessons[0], 'open_at' => $this->site_time(10)]]);

    $this->ajax('set_lesson_open_at', ['class_id' => $class, 'lesson_ids' => $lessons[0], 'open_at' => 'now']);

    $openAt = $this->class_lessons($class)[0]['open_at'];
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $openAt);
    $this->assertLessThanOrEqual(1, abs(strtotime($openAt) - strtotime(current_time('Y-m-d H:i'))) / 60);
  }

  public function test_clearing_removes_the_lesson_and_drops_an_old_open_flag() {
    $this->as_admin();
    [$course, , $lessons] = $this->make_course_with_lessons(2);
    $class = $this->make_class($course, true, [
      ['id' => $lessons[0], 'open_at' => '2026-12-01 20:00'],
      ['id' => $lessons[1], 'open' => true],
    ]);

    $this->ajax('set_lesson_open_at', ['class_id' => $class, 'lesson_ids' => implode(',', $lessons), 'open_at' => '']);

    $this->assertSame([], $this->class_lessons($class));
  }

  public function test_rejects_a_malformed_date() {
    $this->as_admin();
    [$course, , $lessons] = $this->make_course_with_lessons(1);
    $class = $this->make_class($course, true);

    $res = $this->ajax('set_lesson_open_at', ['class_id' => $class, 'lesson_ids' => $lessons[0], 'open_at' => 'next tuesday']);

    $this->assertFalse($res['success']);
    $this->assertSame([], $this->class_lessons($class));
  }

  public function test_rejects_ids_that_are_not_a_class_or_lessons() {
    $this->as_admin();
    [$course, , $lessons] = $this->make_course_with_lessons(1);
    $class = $this->make_class($course, true);

    $this->assertFalse($this->ajax('set_lesson_open_at', ['class_id' => $course, 'lesson_ids' => $lessons[0], 'open_at' => 'now'])['success']);
    $this->assertFalse($this->ajax('set_lesson_open_at', ['class_id' => $class, 'lesson_ids' => "{$lessons[0]},$course", 'open_at' => 'now'])['success']);
    $this->assertFalse($this->ajax('set_lesson_open_at', ['class_id' => $class, 'lesson_ids' => '', 'open_at' => 'now'])['success']);
    $this->assertSame([], $this->class_lessons($class), 'nothing half-written');
  }

  // --- set_class_pacing -----------------------------------------------------

  public function test_switching_to_paced_turns_old_open_flags_into_dates() {
    $this->as_admin();
    [$course, , $lessons] = $this->make_course_with_lessons(4);
    $class = $this->make_class($course, false, [
      ['id' => (string) $lessons[0], 'open' => true],
      ['id' => (string) $lessons[1], 'open' => false],
      ['id' => $lessons[2], 'open_at' => '2027-01-01 10:00'],
    ]);

    $res = $this->ajax('set_class_pacing', ['class_id' => $class, 'paced' => '1']);

    $this->assertTrue($res['success']);
    $this->assertSame('1', get_post_meta($class, 'paced', true));
    $byId = array_column($this->class_lessons($class), 'open_at', 'id');
    $this->assertArrayHasKey($lessons[0], $byId, 'an open lesson stays open, now with a date');
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $byId[$lessons[0]]);
    $this->assertArrayNotHasKey($lessons[1], $byId, 'a closed flag is dropped');
    $this->assertSame('2027-01-01 10:00', $byId[$lessons[2]], 'an existing date is kept');
    $this->assertCount(2, $byId);
  }

  public function test_switching_back_to_self_paced_keeps_the_dates() {
    $this->as_admin();
    [$course, , $lessons] = $this->make_course_with_lessons(1);
    $dates = [['id' => $lessons[0], 'open_at' => '2027-01-01 10:00']];
    $class = $this->make_class($course, true, $dates);

    $this->ajax('set_class_pacing', ['class_id' => $class, 'paced' => '0']);

    $this->assertSame('0', get_post_meta($class, 'paced', true));
    $this->assertSame($dates, $this->class_lessons($class));
  }

  public function test_pacing_rejects_a_non_class() {
    $this->as_admin();
    [$course] = $this->make_course_with_lessons(1);
    $this->assertFalse($this->ajax('set_class_pacing', ['class_id' => $course, 'paced' => '1'])['success']);
    $this->assertSame('', get_post_meta($course, 'paced', true));
  }

  // --- permissions ----------------------------------------------------------

  public function test_students_cannot_change_a_schedule() {
    [$course, , $lessons] = $this->make_course_with_lessons(1);
    $class = $this->make_class($course, true);
    wp_set_current_user($this->make_student());

    $this->assertFalse($this->ajax('set_lesson_open_at', ['class_id' => $class, 'lesson_ids' => $lessons[0], 'open_at' => 'now'])['success']);
    $this->assertFalse($this->ajax('set_class_pacing', ['class_id' => $class, 'paced' => '0'])['success']);
    $this->assertSame([], $this->class_lessons($class));
    $this->assertSame('1', get_post_meta($class, 'paced', true));
  }

  // --- get_lessons (admin list and student sidebar) -------------------------

  public function test_lesson_list_reports_released_and_open_at_per_lesson() {
    $this->as_admin();
    [$course, , $lessons] = $this->make_course_with_lessons(3);
    $class = $this->make_class($course, true, [
      ['id' => $lessons[0], 'open_at' => $this->site_time(-1)],
      ['id' => $lessons[1], 'open_at' => '2099-01-01 08:00'],
    ]);

    $rows = $this->ajax('get_lessons', ['class_id' => $class]);

    $this->assertSame(array_map('intval', $lessons), array_map('intval', array_column($rows, 'id')), 'in lesson order');
    [$open, $scheduled, $undated] = $rows;
    $this->assertTrue($open['released']);
    $this->assertTrue($open['open']);
    $this->assertFalse($scheduled['released']);
    $this->assertSame('2099-01-01 08:00', $scheduled['open_at']);
    $this->assertFalse($undated['released']);
    $this->assertNull($undated['open_at']);
  }

  public function test_self_paced_lesson_list_is_all_open() {
    $this->as_admin();
    [$course, , $lessons] = $this->make_course_with_lessons(2);
    $class = $this->make_class($course, false, [['id' => $lessons[0], 'open_at' => '2099-01-01 08:00']]);

    foreach ($this->ajax('get_lessons', ['class_id' => $class]) as $row) {
      $this->assertTrue($row['released']);
      $this->assertTrue($row['open']);
    }
  }

  // --- search_classes (class picker) ----------------------------------------

  public function test_class_picker_returns_pacing_start_date_and_student_count() {
    $this->as_admin();
    [$course] = $this->make_course_with_lessons(1);
    $paced = $this->make_class($course, true);
    update_post_meta($paced, 'start_date', '2026-11-01T20:00');
    $this->enroll($this->make_student(), $paced);
    $this->make_class($this->make_course(), false); // another course's class

    $res = $this->ajax('search_classes', ['course_id' => $course]);

    $this->assertTrue($res['success']);
    $this->assertCount(1, $res['results']);
    $found = $res['results'][0];
    $this->assertSame($paced, (int) $found['id']);
    $this->assertTrue($found['paced']);
    $this->assertSame('2026-11-01T20:00', $found['start_date']);
    $this->assertSame(1, $found['students']);
  }
}
