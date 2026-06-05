<?php
namespace FutureLMS\classes;

use FutureLMS\FutureLMS;

class ProgressManager {
  private static $instance;

  const COMPLETED_COURSE_THRESHOLD = 95;
  const PROGRESS_TABLE_NAME = 'flms_progress';

  public static function get_instance() {
    if (!isset(self::$instance)) {
      self::$instance = new ProgressManager();
    }
    return self::$instance;
  }

  public function __construct() {
    add_action("wp_ajax_get_student_progress", [$this, "getStudentProgress"]);
    add_action("wp_ajax_set_student_progress", [$this, "setStudentProgress"]);
  }

  public function setStudentProgress() {
    $userId = get_current_user_id();
    $courseId = intval($_POST["course_id"]);

    $data = [
      'user_id' => $userId,
      'course_id' => $courseId,
      'module_id' => intval($_POST["module_id"]),
      'lesson_id' => intval($_POST["lesson_id"]),
      'video_id' => sanitize_text_field($_POST["video_id"]),
      'percent' => intval($_POST["percent"]),
      'seconds' => intval($_POST["seconds"])
    ];

    // Course tree is needed for both before- and after-save snapshots.
    $courseTree = Course::get_courses_tree([$courseId]);
    $moduleId = (int) $data['module_id'];

    // Snapshot the module percent BEFORE we save so the 5% milestone
    // comparison reflects the state the student crossed from. Course %
    // uses the client-supplied 'progress' field (more reliable than a
    // pre-save recompute when multiple devices are watching) — see below.
    // Modules with count_progress=false return 0 from getModuleProgress,
    // so they never fire the module trigger.
    $oldModulePercent = $moduleId
      ? floor(self::getModuleProgress($userId, $courseId, $moduleId, $courseTree)['percent'])
      : 0;

    self::saveProgress($data);

    $oldRawPercent = isset($_POST["progress"]) ? floatval($_POST["progress"]) : -1;
    $newRawPercent = self::getCourseProgress($userId, $courseId, $courseTree)['percent'];

    $oldMilestone = $oldRawPercent >= 0 ? floor($oldRawPercent) : -1;
    $newMilestone = floor($newRawPercent);

    $newModulePercent = $moduleId
      ? floor(self::getModuleProgress($userId, $courseId, $moduleId, $courseTree)['percent'])
      : 0;

    // Two independent triggers: course % crosses a new 1% step, OR
    // module % crosses a new 5% step (5, 10, 15, …, 100). Either fires
    // the same event payload — we don't double-fire if both happen.
    $courseTriggered  = ($newMilestone >= 1 && $newMilestone <= 100 && $newMilestone > $oldMilestone);
    $oldModuleStep    = (int) floor($oldModulePercent / 5);
    $newModuleStep    = (int) floor($newModulePercent / 5);
    $moduleTriggered  = ($newModuleStep > $oldModuleStep && $newModuleStep >= 1);

    if ($courseTriggered || $moduleTriggered) {
      $data['course_percent'] = (int) $newMilestone;
      $data['module_percent'] = (int) $newModulePercent;

      /**
       * Fires when a student crosses a new course-progress 1% milestone
       * OR a new module-progress 5% milestone (whichever comes first).
       *
       * @param array $data {
       *     @type int    $user_id
       *     @type int    $course_id
       *     @type int    $module_id
       *     @type int    $lesson_id
       *     @type string $video_id
       *     @type int    $percent         Current video percent
       *     @type int    $seconds         Current video watched seconds
       *     @type int    $course_percent  Floored course progress (1 to 100)
       *     @type int    $module_percent  Floored progress of the module the
       *                                   video belongs to (0 to 100; always
       *                                   0 for count_progress=false modules)
       * }
       */
      do_action('vi_course_progress_updated', $data);
    }
  }

  public function getStudentProgress() {
    try {
      $result = [];
      $studentId = get_current_user_id();

      if (current_user_can('manage_options') && isset($_POST["student_id"])) {
        $studentId = $_POST["student_id"];
      }

      $student = new Student($studentId);
      $classes = $student->attendence_info();
      $courses = array_reduce($classes, function ($carry, $item) {
        if (strtotime($item["registration_date"]) <= strtotime("2023-06-01")) {
          return $carry;
        }

        $carry[] = $item["course_id"];
        return $carry;
      }, []);

      $result["course_tree"] = Course::get_courses_tree($courses);
      $result["progress"] = self::getTotalProgress($studentId, $result["course_tree"]);

      if (isset($_POST["course_id"])) {
        $result["course_progress"] = self::getDetailedLessonsProgress($studentId, $_POST["course_id"], $result["course_tree"]);
      }

      echo json_encode($result);
      die();
    } catch (\Exception $ex) {
      echo json_encode([]);
      die();
    }
  }

  public static function getTotalProgress(int $userId, array $courseTree): array {
    $progressPerCourse = [];

    foreach ($courseTree as $courseId => $_) {
      $progressPerCourse[$courseId] = self::getCourseProgress($userId, $courseId, $courseTree);
    }

    return $progressPerCourse;
  }

  public static function getCourseProgress(int $studentId, int $courseId, array $courseTree): array {
    $courseLessons = self::getLessons($courseId, $courseTree, true);
    $lessonIds = $courseLessons['ids'];
    $lessonsDurations = $courseLessons['durations'];

    if (empty($lessonIds)) {
      return [
        'watched' => 0,
        'duration' => 0,
        'percent' => 0
      ];
    }

    $lessonsProgress = self::queryLessonsProgress($studentId, $courseId, $lessonIds);
    return self::calculate($lessonsProgress, $lessonsDurations);
  }

  /**
   * Aggregate watched / duration / percent for a single lesson (across all
   * of its videos). Uses the same calculate() rules as the course-level
   * computation, so a 'text' row counts as the lesson's full duration when
   * the user marked it 100%.
   *
   * Includes every lesson in the tree (not just count_progress ones) so a
   * direct lesson_id from an event still resolves even when the lesson's
   * module is excluded from course-level scoring.
   */
  public static function getLessonProgress(int $studentId, int $courseId, int $lessonId, array $courseTree): array {
    $courseLessons = self::getLessons($courseId, $courseTree, false);
    $lessonDuration = $courseLessons['durations'][$lessonId] ?? 0;

    if ($lessonDuration <= 0) {
      return ['watched' => 0, 'duration' => 0, 'percent' => 0];
    }

    $progressRows = self::queryLessonsProgress($studentId, $courseId, [$lessonId]);
    return self::calculate($progressRows, [$lessonId => $lessonDuration]);
  }

  /**
   * Aggregate watched / duration / percent for a single module, summing
   * across all of its lessons. Returns 0 when the module is marked
   * count_progress=false — by policy that module doesn't participate in
   * progress reporting (mirrors the course-level scoring rule).
   */
  public static function getModuleProgress(int $studentId, int $courseId, int $moduleId, array $courseTree): array {
    $zero = ['watched' => 0, 'duration' => 0, 'percent' => 0];

    $module = $courseTree[$courseId]["modules"][$moduleId] ?? null;
    if (!$module) return $zero;

    $countProgress = !isset($module["count_progress"]) || $module["count_progress"];
    if (!$countProgress) return $zero;

    $lessonDurations = [];
    foreach ($module["lessons"] as $lessonId => $lesson) {
      $lessonDurations[(int) $lessonId] = (int) ($lesson["duration"] ?? 0);
    }

    if (!$lessonDurations || array_sum($lessonDurations) <= 0) return $zero;

    $progressRows = self::queryLessonsProgress($studentId, $courseId, array_keys($lessonDurations));
    return self::calculate($progressRows, $lessonDurations);
  }

  private static function calculate(array $lessonsProgress, array $lessonDurations): array {
    $watchedSeconds = 0;
    $totalCourseDuration = array_sum($lessonDurations);

    foreach ($lessonsProgress as $lesson) {
      $lessonId = (int) $lesson['lesson_id'];
      $videoId = $lesson['video_id'];
      $seconds = (int) $lesson['seconds'];
      $percent = (int) $lesson['percent'];

      if (!isset($lessonDurations[$lessonId])) {
        continue;
      }

      if ($videoId === 'text') {
        if ($percent === 100) {
          $watchedSeconds += $lessonDurations[$lessonId];
        }
      } else {
        $watchedSeconds += $seconds;
      }
    }

    $percentWatched = $totalCourseDuration > 0 ? round(($watchedSeconds / $totalCourseDuration) * 100, 2) : 0;

    return [
      'watched' => $watchedSeconds,
      'duration' => $totalCourseDuration,
      'percent' => $percentWatched > self::COMPLETED_COURSE_THRESHOLD ? 100 : $percentWatched
    ];
  }

  private static function getLessons(int $courseId, array $courseTree, bool $countProgressOnly = true): array {
    $lessonIds = [];
    $lessonDurations = [];

    if (!isset($courseTree[$courseId])) {
      return ['ids' => [], 'durations' => []];
    }

    foreach ($courseTree[$courseId]["modules"] as $module) {
      $shouldCount = !isset($module["count_progress"]) || $module["count_progress"];

      if ($countProgressOnly && !$shouldCount) {
        continue;
      }

      foreach ($module["lessons"] as $lessonId => $lesson) {
        $lessonId = (int) $lessonId;
        $lessonIds[] = $lessonId;
        $lessonDurations[$lessonId] = (int) ($lesson["duration"] ?? 0);
      }
    }

    return [
      'ids' => $lessonIds,
      'durations' => $lessonDurations
    ];
  }

  public static function getDetailedLessonsProgress(int $studentId, int $courseId, array $courseTree): array {
    $courseLessons = self::getLessons($courseId, $courseTree, false);
    $lessonIds = $courseLessons['ids'];

    if (empty($lessonIds)) {
      return [];
    }

    $results = self::queryLessonsProgress($studentId, $courseId, $lessonIds);

    $progress = [];

    foreach ($results as $row) {
      $lessonId = (int) $row['lesson_id'];
      $videoId = $row['video_id'];
      $seconds = (int) $row['seconds'];
      $percent = (int) $row['percent'];

      if (!isset($progress[$lessonId])) {
        $progress[$lessonId] = [];
      }

      $progress[$lessonId][$videoId] = [
        'seconds' => $seconds,
        'percent' => $percent
      ];
    }

    return $progress;
  }

  // --- DB operations (moved from ValueSchoolQuery) ---

  private static function progressTable(): string {
    global $wpdb;
    return $wpdb->prefix . self::PROGRESS_TABLE_NAME;
  }

  public static function saveProgress(array $data): void {
    global $wpdb;
    $table = self::progressTable();

    $userId = $data['user_id'] ?? 0;
    $courseId = $data['course_id'] ?? 0;
    $moduleId = $data['module_id'] ?? 0;
    $lessonId = $data['lesson_id'] ?? 0;
    $videoId = $data['video_id'] ?? '';
    $percent = $data['percent'] ?? 0;
    $seconds = $data['seconds'] ?? 0;

    if (!$userId || !$lessonId || !$videoId) {
      return;
    }

    $existing = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT id, percent FROM $table
             WHERE user_id = %d AND lesson_id = %d AND video_id = %s",
        $userId, $lessonId, $videoId
      ),
      ARRAY_A
    );

    if ($existing) {
      if ($percent > intval($existing['percent'])) {
        $wpdb->update(
          $table,
          [
            'percent' => $percent,
            'seconds' => $seconds,
            'updated_at' => current_time('mysql')
          ],
          ['id' => $existing['id']]
        );
      }
    } else {
      $wpdb->insert(
        $table,
        [
          'user_id' => $userId,
          'course_id' => $courseId,
          'module_id' => $moduleId,
          'lesson_id' => $lessonId,
          'video_id' => $videoId,
          'percent' => $percent,
          'seconds' => $seconds,
          'updated_at' => current_time('mysql')
        ]
      );
    }
  }

  public static function queryLessonsProgress(int $studentId, int $courseId, array $lessonIds): array {
    global $wpdb;
    $table = self::progressTable();

    if (empty($lessonIds)) {
      return [];
    }

    $placeholders = implode(',', array_fill(0, count($lessonIds), '%d'));
    $sql = "
        SELECT lesson_id, video_id, seconds, percent
        FROM $table
        WHERE user_id = %d
          AND course_id = %d
          AND lesson_id IN ($placeholders)
    ";
    $queryArgs = array_merge([$studentId, $courseId], $lessonIds);
    return $wpdb->get_results($wpdb->prepare($sql, ...$queryArgs), ARRAY_A);
  }
}
