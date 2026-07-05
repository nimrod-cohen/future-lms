<?php
namespace FutureLMS\classes;

use FutureLMS\FutureLMS;

class ProgressManager {
  private static $instance;

  // Reporting clamp: percents rendered in the sidebar, sent in the
  // vi_course_progress_updated event, and factored into course/module
  // completion get rounded up to 100 once you cross this bar. Unchanged
  // by design — the CIO milestones + green-bar gradient still speak the
  // "95% ≈ complete" language.
  const COMPLETED_COURSE_THRESHOLD = 95;

  // Sequential-progress gate ONLY: how much of a lesson counts as
  // "watched enough to open the next one". Deliberately looser than the
  // reporting threshold so a returning student who's e.g. 85% through
  // the last lesson of a module doesn't get stuck behind the gate.
  const SEQUENTIAL_GATE_THRESHOLD = 80;

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

    // Gate enforcement: drop saves for locked lessons. Without this, a stale
    // browser localStorage (or any direct POST) can route a student into a
    // gated lesson, fire the auto-100% text save on visit, and the
    // implicit-completion rule downstream then unlocks every preceding
    // lesson — effectively bypassing the sequential gate. is_lesson_open
    // already factors in live-class drip + sequential rules, so it's the
    // right authoritative check.
    if ($userId && !empty($data['lesson_id'])) {
      $gate_student = new Student($userId);
      if (!$gate_student->is_lesson_open($courseId, $data['lesson_id'])) {
        wp_send_json(['blocked' => true]);
        return;
      }
    }

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

      // Lesson percent is included for context only — it does not have
      // its own trigger (see the trigger flags above). Computed only when
      // we're actually firing.
      $lessonId = (int) ($data['lesson_id'] ?? 0);
      $data['lesson_percent'] = $lessonId
        ? (int) floor(self::getLessonProgress($userId, $courseId, $lessonId, $courseTree)['percent'])
        : 0;

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
       *     @type int    $lesson_percent  Floored progress of the lesson the
       *                                   video belongs to (0 to 100;
       *                                   context only — does not fire on
       *                                   its own milestone)
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
   * Aggregate watched / duration / percent for a single lesson.
   *
   * Three completion modes:
   *   1) Text lesson (videos === ['text']): "complete" means a single
   *      `text` progress row at 100% exists — visiting the lesson is enough.
   *   2) Video lesson with known lesson_duration: standard seconds/duration
   *      math via calculate(); a >95% watch is clamped to 100% (legacy rule).
   *   3) Video lesson with unknown lesson_duration: fraction of videos that
   *      have hit 100%. Without this fallback, lessons whose duration meta
   *      hasn't been crawled yet always read as 0% and never satisfy a
   *      sequential gate even after the student watched them to the end.
   *
   * Includes every lesson in the tree (not just count_progress ones) so a
   * direct lesson_id from an event still resolves even when the lesson's
   * module is excluded from course-level scoring.
   */
  public static function getLessonProgress(int $studentId, int $courseId, int $lessonId, array $courseTree): array {
    $lessonNode = null;
    foreach (($courseTree[$courseId]["modules"] ?? []) as $module) {
      if (isset($module["lessons"][$lessonId])) {
        $lessonNode = $module["lessons"][$lessonId];
        break;
      }
    }
    if (!$lessonNode) {
      return ['watched' => 0, 'duration' => 0, 'percent' => 0];
    }

    $videos = $lessonNode["videos"] ?? [];
    $isTextLesson = ($videos === ['text']) || empty($videos);
    $progressRows = self::queryLessonsProgress($studentId, $courseId, [$lessonId]);

    if ($isTextLesson) {
      // Direct visit: the lesson itself has a 100% text row → complete.
      foreach ($progressRows as $row) {
        if ($row['video_id'] === 'text' && (int) $row['percent'] >= 100) {
          return ['watched' => 1, 'duration' => 1, 'percent' => 100];
        }
      }
      // Implicit completion: any LATER lesson in the course tree has progress.
      // The student couldn't sensibly be there without moving past this text
      // lesson, so treat it as visited. This unblocks the sequential gate when
      // a student arrives at a later lesson via direct navigation / a stale
      // localStorage resume / a skip — without it the earlier text lesson
      // would permanently trap forward progress until visited explicitly.
      $sequence = [];
      foreach (($courseTree[$courseId]["modules"] ?? []) as $m) {
        foreach (($m["lessons"] ?? []) as $lid => $_) {
          $sequence[] = (int) $lid;
        }
      }
      $myPos = array_search((int) $lessonId, $sequence, true);
      if ($myPos !== false) {
        $laterLessons = array_slice($sequence, $myPos + 1);
        if (!empty($laterLessons)) {
          $laterRows = self::queryLessonsProgress($studentId, $courseId, $laterLessons);
          foreach ($laterRows as $row) {
            if ((int) $row['percent'] > 0) {
              return ['watched' => 1, 'duration' => 1, 'percent' => 100];
            }
          }
        }
      }
      return ['watched' => 0, 'duration' => 0, 'percent' => 0];
    }

    $lessonDuration = (int) ($lessonNode["duration"] ?? 0);
    if ($lessonDuration > 0) {
      $result = self::calculate($progressRows, [$lessonId => $lessonDuration]);
    } else {
      // No duration meta — fall back to averaged per-video percent (same shape
      // as the sidebar's green-bar gradient). Strict "every video at exactly
      // 100%" was wrong here: Vimeo's last timeupdate is often 98–99% rather
      // than a clean 100, so a fully-watched lesson looked complete in the
      // sidebar but blocked the sequential gate. The 95% threshold clamp
      // covers that gap.
      $videoCount = count($videos);
      if ($videoCount === 0) {
        return ['watched' => 0, 'duration' => 0, 'percent' => 0];
      }
      $totalPercent = 0;
      foreach ($progressRows as $row) {
        if (in_array($row['video_id'], $videos, true)) {
          $totalPercent += (int) $row['percent'];
        }
      }
      $average = $totalPercent / $videoCount;
      $result = [
        'watched'  => $totalPercent,
        'duration' => $videoCount * 100,
        'percent'  => $average >= self::COMPLETED_COURSE_THRESHOLD ? 100 : $average,
      ];
    }

    // Implicit completion (also applied to video lessons): a student can't
    // sensibly be on a LATER lesson without having moved past this one. If
    // any later lesson has progress, treat this lesson as complete — even if
    // its direct percent is below the threshold. This unblocks long-time
    // students whose old progress records stopped at 88% / 92% etc. when
    // sequential gating is later enabled on the course.
    if (((int) $result['percent']) < 100) {
      $sequence = [];
      foreach (($courseTree[$courseId]["modules"] ?? []) as $m) {
        foreach (($m["lessons"] ?? []) as $lid => $_) {
          $sequence[] = (int) $lid;
        }
      }
      $myPos = array_search((int) $lessonId, $sequence, true);
      if ($myPos !== false) {
        $laterLessons = array_slice($sequence, $myPos + 1);
        if (!empty($laterLessons)) {
          $laterRows = self::queryLessonsProgress($studentId, $courseId, $laterLessons);
          foreach ($laterRows as $row) {
            if ((int) $row['percent'] > 0) {
              return [
                'watched'  => $result['duration'],
                'duration' => $result['duration'],
                'percent'  => 100,
              ];
            }
          }
        }
      }
    }

    return $result;
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
      'percent' => $percentWatched >= self::COMPLETED_COURSE_THRESHOLD ? 100 : $percentWatched
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

  /**
   * For "continue where I left off" entry points: returns the lesson id that
   * makes the most sense to land the student on, given their current progress.
   *
   *   - Skip intro/non-counting modules (drip lesson selection still handles
   *     those explicitly; sequential gating already does too).
   *   - First counted lesson that the student hasn't finished → resume there.
   *   - If every counted lesson is 100% complete → land on the last counted
   *     lesson (so the student can re-watch / read homework).
   *   - If the course has no counted lessons at all (edge case), return the
   *     first lesson in the tree.
   *
   * Returns 0 when nothing can be resolved (no course / no lessons), so the
   * caller can fall back to its own default.
   */
  public static function findResumeLesson(int $studentId, int $courseId, array $courseTree = null): int {
    if ($courseTree === null) {
      $courseTree = Course::get_courses_tree([$courseId]);
    }
    $course = $courseTree[$courseId] ?? null;
    if (!$course) return 0;

    $counted = [];
    $anyLessonFirst = 0;
    foreach (($course["modules"] ?? []) as $module) {
      foreach (($module["lessons"] ?? []) as $lid => $_) {
        if (!$anyLessonFirst) $anyLessonFirst = (int) $lid;
      }
      if (empty($module["count_progress"])) continue;
      foreach (($module["lessons"] ?? []) as $lid => $_) {
        $counted[] = (int) $lid;
      }
    }

    if (empty($counted)) {
      return $anyLessonFirst;
    }

    // Use the same threshold as the sequential gate so "resume" lands on
    // the first lesson the student still needs to open, not on lessons
    // they already watched enough of to satisfy the gate.
    foreach ($counted as $lid) {
      $p = self::getLessonProgress($studentId, $courseId, $lid, $courseTree);
      if (((int) $p['percent']) < self::SEQUENTIAL_GATE_THRESHOLD) {
        return $lid;
      }
    }

    return end($counted) ?: $anyLessonFirst;
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
