<?php

use FutureLMS\classes\SchoolClass;
use FutureLMS\Tests\TestCase;

/**
 * The class schedule rule: self-paced opens everything; paced opens each
 * lesson on its own date & hour, and an undated lesson stays closed.
 */
class LessonReleaseRuleTest extends TestCase {

  public function test_self_paced_opens_every_lesson_whatever_the_list_says() {
    $lessons = [
      ['id' => 1, 'open_at' => $this->site_time(30)],
      ['id' => 2, 'open' => false],
    ];
    $this->assertTrue(SchoolClass::is_lesson_released($lessons, 1, false));
    $this->assertTrue(SchoolClass::is_lesson_released($lessons, 2, false));
    $this->assertTrue(SchoolClass::is_lesson_released($lessons, 3, false));
  }

  public function test_paced_lesson_opens_once_its_date_passes() {
    $lessons = [
      ['id' => 1, 'open_at' => $this->site_time(-1)],
      ['id' => 2, 'open_at' => $this->site_time(1)],
    ];
    $this->assertTrue(SchoolClass::is_lesson_released($lessons, 1, true));
    $this->assertFalse(SchoolClass::is_lesson_released($lessons, 2, true));
  }

  public function test_paced_lesson_opens_at_the_exact_minute() {
    $now = current_time('Y-m-d H:i');
    $this->assertTrue(SchoolClass::is_lesson_released([['id' => 1, 'open_at' => $now]], 1, true));
  }

  public function test_paced_lesson_without_a_date_is_closed() {
    $this->assertFalse(SchoolClass::is_lesson_released([], 7, true));
    $this->assertFalse(SchoolClass::is_lesson_released([['id' => 8, 'open_at' => $this->site_time(-1)]], 7, true));
  }

  public function test_paced_honours_an_old_manual_open_flag() {
    $lessons = [['id' => 1, 'open' => true], ['id' => 2, 'open' => false]];
    $this->assertTrue(SchoolClass::is_lesson_released($lessons, 1, true));
    $this->assertFalse(SchoolClass::is_lesson_released($lessons, 2, true));
  }

  public function test_a_date_wins_over_an_old_flag() {
    $lessons = [['id' => 1, 'open' => true, 'open_at' => $this->site_time(2)]];
    $this->assertFalse(SchoolClass::is_lesson_released($lessons, 1, true));
  }

  public function test_lesson_ids_match_whether_stored_as_strings_or_numbers() {
    $lessons = [['id' => '42', 'open_at' => $this->site_time(-1)]];
    $this->assertTrue(SchoolClass::is_lesson_released($lessons, 42, true));
    $this->assertTrue(SchoolClass::is_lesson_released($lessons, '42', true));
  }

  public function test_dates_compare_in_site_time_not_utc() {
    // Asia/Jerusalem is UTC+2/+3: an hour ahead in UTC is still in the past here.
    update_option('timezone_string', 'Asia/Jerusalem');
    $justPassed = wp_date('Y-m-d H:i', time() - 30 * MINUTE_IN_SECONDS);
    $this->assertTrue(SchoolClass::is_lesson_released([['id' => 1, 'open_at' => $justPassed]], 1, true));
    $soon = wp_date('Y-m-d H:i', time() + 30 * MINUTE_IN_SECONDS);
    $this->assertFalse(SchoolClass::is_lesson_released([['id' => 1, 'open_at' => $soon]], 1, true));
  }

  public function test_is_paced_reads_the_class_flag() {
    [$course] = $this->make_course_with_lessons(1);
    $this->assertTrue(SchoolClass::is_paced($this->make_class($course, true)));
    $this->assertFalse(SchoolClass::is_paced($this->make_class($course, false)));
    $legacy = self::factory()->post->create(['post_type' => 'class']);
    $this->assertFalse(SchoolClass::is_paced($legacy), 'a class saved before pacing existed is self-paced');
  }

  public function test_student_count_counts_distinct_enrolled_students() {
    [$course] = $this->make_course_with_lessons(1);
    $class = $this->make_class($course);
    $this->enroll($this->make_student(), $class);
    $this->enroll($this->make_student(), $class);
    $this->assertSame(2, SchoolClass::student_count($class));
    $this->assertSame(0, SchoolClass::student_count($this->make_class($course)));
  }
}
