<?php

use FutureLMS\classes\Student;
use FutureLMS\Tests\TestCase;

/**
 * Student::is_lesson_open is what get_lesson_content checks before serving a
 * lesson. The class schedule and the course's own gates (sequential progress)
 * both apply; neither can open what the other closed.
 */
class StudentLessonAccessTest extends TestCase {

  private function student_in($classId) {
    $id = $this->make_student();
    $this->enroll($id, $classId);
    return new Student($id);
  }

  public function test_self_paced_student_can_open_every_lesson() {
    [$course, , $lessons] = $this->make_course_with_lessons(3);
    $student = $this->student_in($this->make_class($course, false, [['id' => $lessons[2], 'open_at' => '2099-01-01 00:00']]));

    foreach ($lessons as $lesson) $this->assertTrue($student->is_lesson_open($course, $lesson));
  }

  public function test_paced_student_waits_for_each_date() {
    [$course, , $lessons] = $this->make_course_with_lessons(3);
    $student = $this->student_in($this->make_class($course, true, [
      ['id' => $lessons[0], 'open_at' => $this->site_time(-1)],
      ['id' => $lessons[1], 'open_at' => $this->site_time(1)],
    ]));

    $this->assertTrue($student->is_lesson_open($course, $lessons[0]));
    $this->assertFalse($student->is_lesson_open($course, $lessons[1]), 'scheduled for tomorrow');
    $this->assertFalse($student->is_lesson_open($course, $lessons[2]), 'no date in a paced class');
  }

  public function test_the_schedule_belongs_to_the_students_own_class() {
    [$course, , $lessons] = $this->make_course_with_lessons(1);
    $this->make_class($course, true, []); // another cohort, everything closed
    $selfPaced = $this->student_in($this->make_class($course, false));

    $this->assertTrue($selfPaced->is_lesson_open($course, $lessons[0]));
  }

  public function test_sequential_progress_still_locks_a_released_lesson() {
    [$course, , $lessons] = $this->make_course_with_lessons(2, ['sequential_progress' => '1'], ['count_progress' => '1']);
    $student = $this->student_in($this->make_class($course, true, [
      ['id' => $lessons[0], 'open_at' => $this->site_time(-2)],
      ['id' => $lessons[1], 'open_at' => $this->site_time(-1)],
    ]));

    $this->assertTrue($student->is_lesson_open($course, $lessons[0]));
    $this->assertFalse($student->is_lesson_open($course, $lessons[1]), 'lesson 1 not watched yet');
  }

  public function test_sequential_progress_cannot_open_a_lesson_the_schedule_closed() {
    [$course, , $lessons] = $this->make_course_with_lessons(1, ['sequential_progress' => '1'], ['count_progress' => '1']);
    $student = $this->student_in($this->make_class($course, true, [['id' => $lessons[0], 'open_at' => $this->site_time(1)]]));

    $this->assertFalse($student->is_lesson_open($course, $lessons[0]));
  }
}
