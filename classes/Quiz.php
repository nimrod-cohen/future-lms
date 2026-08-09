<?php

namespace FutureLMS\classes;

use Exception;
use FutureLMS\FutureLMS;

/**
 * Multiple-choice quiz attached to a module.
 *
 * A quiz is optional per module and is stored entirely in the module's post
 * meta (there is no `quiz` post type — a quiz has no life of its own outside
 * the module it closes off):
 *
 *   quiz_enabled        '1' / '0'
 *   quiz_title          heading shown to the student
 *   quiz_pass_score     0-100. 0 (or empty) means "no minimum score"
 *   quiz_block_progress '1' / '0' — only meaningful when quiz_pass_score > 0
 *   quiz_reveal_answers '1' / '0' — only meaningful when quiz_pass_score == 0
 *   quiz_questions      JSON [{id, text, options: [..], correct: <index>}]
 *
 * Attempts live in {prefix}flms_quiz_attempts, one row per (student, module):
 * retaking overwrites, resetting deletes. Quizzes deliberately do NOT write
 * to the progress table — a quiz never counts towards course completion.
 *
 * The two mutually exclusive behaviours mirror how the feature is meant to be
 * used: either the quiz is a real gate (minimum score, optionally blocking the
 * rest of the course), or it's a self-check (no minimum, answers may be
 * revealed after submitting). Revealing correct answers on a gating quiz would
 * make the gate meaningless, so `can_reveal()` refuses it.
 */
class Quiz {
  const ATTEMPTS_TABLE = 'quiz_attempts';

  private static $instance;

  public static function get_instance() {
    if (!isset(self::$instance)) {
      self::$instance = new Quiz();
    }
    return self::$instance;
  }

  public function __construct() {
    // Student-facing
    add_action('wp_ajax_get_course_quizzes', [$this, 'ajax_get_course_quizzes']);
    add_action('wp_ajax_get_quiz', [$this, 'ajax_get_quiz']);
    add_action('wp_ajax_submit_quiz', [$this, 'ajax_submit_quiz']);
    add_action('wp_ajax_reset_quiz', [$this, 'ajax_reset_quiz']);
    // Admin
    add_action('wp_ajax_get_quiz_details', [$this, 'ajax_get_quiz_details']);
    add_action('wp_ajax_edit_quiz', [$this, 'ajax_edit_quiz']);
  }

  public static function table(): string {
    return FutureLMS::TABLE_PREFIX() . self::ATTEMPTS_TABLE;
  }

  // --- Configuration -------------------------------------------------------

  /**
   * Normalized quiz config for a module. Always returns the full shape so
   * callers never have to null-check individual keys.
   */
  public static function config(int $moduleId): array {
    $questions = self::questions($moduleId);
    $passScore = (int) get_post_meta($moduleId, 'quiz_pass_score', true);
    $passScore = max(0, min(100, $passScore));

    // A quiz with no questions is treated as absent — it would otherwise
    // score 0/0 and (when blocking) permanently trap the student.
    $enabled = get_post_meta($moduleId, 'quiz_enabled', true) === '1' && !empty($questions);

    return [
      'module_id' => $moduleId,
      'course_id' => (int) get_post_meta($moduleId, 'course', true),
      'enabled' => $enabled,
      'title' => get_post_meta($moduleId, 'quiz_title', true) ?: __('בוחן', 'future-lms'),
      'pass_score' => $passScore,
      'block_progress' => get_post_meta($moduleId, 'quiz_block_progress', true) === '1',
      'reveal_answers' => get_post_meta($moduleId, 'quiz_reveal_answers', true) === '1',
      'questions' => $questions,
    ];
  }

  /**
   * Everything about a quiz EXCEPT the questions (and therefore except the
   * correct answers). This is what goes into the course tree, because
   * `get_all_courses` is reachable by any logged-in user — shipping the
   * answer key in it would hand students the solutions.
   *
   * Shares the keys `is_blocking()` / `can_reveal()` read, so summaries and
   * full configs are interchangeable for gating decisions.
   *
   * Built from ONE `get_post_meta($moduleId)` rather than a read per key:
   * this runs for every module of every course in `Course::get_courses_tree`,
   * which sits on the progress hot path, and Pods filters every single
   * metadata read. Seven reads per module turned a ~75ms tree build into
   * ~270ms; one read per module keeps it flat. For the same reason the
   * question count is its own meta key (written on save) instead of being
   * derived by decoding the questions JSON here.
   */
  public static function summary(int $moduleId, array $meta = null): array {
    if ($meta === null) {
      $meta = get_post_meta($moduleId);
    }

    $value = function ($key) use ($meta) {
      $raw = $meta[$key] ?? null;
      return is_array($raw) ? ($raw[0] ?? '') : (string) ($raw ?? '');
    };

    $count = (int) $value('quiz_question_count');
    $enabledFlag = $value('quiz_enabled') === '1';

    // Fallback for a quiz saved before the count was denormalized (or if the
    // two ever drift): only pay for the decode when there's a quiz at all.
    if ($enabledFlag && $count === 0) {
      $count = count(self::sanitize_questions(json_decode($value('quiz_questions') ?: '[]', true) ?: []));
    }

    return [
      'module_id' => $moduleId,
      'course_id' => (int) $value('course'),
      'enabled' => $enabledFlag && $count > 0,
      'title' => $value('quiz_title') ?: __('בוחן', 'future-lms'),
      'pass_score' => max(0, min(100, (int) $value('quiz_pass_score'))),
      'block_progress' => $value('quiz_block_progress') === '1',
      'reveal_answers' => $value('quiz_reveal_answers') === '1',
      'question_count' => $count,
    ];
  }

  public static function questions(int $moduleId): array {
    $raw = get_post_meta($moduleId, 'quiz_questions', true);
    if (empty($raw)) return [];

    $questions = json_decode($raw, true);
    if (!is_array($questions)) return [];

    return self::sanitize_questions($questions);
  }

  /**
   * Drops anything that can't be answered or scored: a question needs text,
   * at least two options and a `correct` index that points at one of them.
   */
  public static function sanitize_questions($questions): array {
    $clean = [];
    $nextId = 1;

    foreach ((array) $questions as $question) {
      if (!is_array($question)) continue;

      $text = trim((string) ($question['text'] ?? ''));
      $options = array_values(array_filter(
        array_map(function ($o) { return trim((string) $o); }, (array) ($question['options'] ?? [])),
        function ($o) { return $o !== ''; }
      ));
      $correct = (int) ($question['correct'] ?? 0);

      if ($text === '' || count($options) < 2) continue;
      if ($correct < 0 || $correct >= count($options)) $correct = 0;

      $id = (int) ($question['id'] ?? 0);
      if ($id <= 0) $id = $nextId;
      $nextId = max($nextId, $id + 1);

      $clean[] = [
        'id' => $id,
        'text' => $text,
        'options' => $options,
        'correct' => $correct,
      ];
    }

    // Guarantee unique ids — stored answers are keyed by them, so a
    // collision would silently mis-grade a retake after an edit.
    $seen = [];
    foreach ($clean as &$question) {
      while (isset($seen[$question['id']])) {
        $question['id'] = $nextId++;
      }
      $seen[$question['id']] = true;
    }
    unset($question);

    return $clean;
  }

  public static function is_blocking(array $config): bool {
    return $config['enabled'] && $config['pass_score'] > 0 && $config['block_progress'];
  }

  /**
   * Correct answers may only be shown when the quiz isn't a scored gate —
   * see the class docblock.
   */
  public static function can_reveal(array $config): bool {
    return $config['enabled'] && $config['pass_score'] === 0 && $config['reveal_answers'];
  }

  /** Questions as the student may see them — never carries `correct`. */
  public static function public_questions(array $config, bool $withAnswers = false): array {
    return array_map(function ($question) use ($withAnswers) {
      $public = [
        'id' => $question['id'],
        'text' => $question['text'],
        'options' => $question['options'],
      ];
      if ($withAnswers) {
        $public['correct'] = $question['correct'];
      }
      return $public;
    }, $config['questions']);
  }

  // --- Attempts ------------------------------------------------------------

  public static function attempt(int $studentId, int $moduleId): ?array {
    global $wpdb;
    $table = self::table();

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM $table WHERE user_id = %d AND module_id = %d",
      $studentId, $moduleId
    ), ARRAY_A);

    if (!$row) return null;

    return [
      'score' => (int) $row['score'],
      'correct_count' => (int) $row['correct_count'],
      'total_count' => (int) $row['total_count'],
      'passed' => (int) $row['passed'] === 1,
      'answers' => json_decode($row['answers'] ?: '{}', true) ?: [],
      'submitted_at' => $row['submitted_at'],
    ];
  }

  public static function reset_attempt(int $studentId, int $moduleId): void {
    global $wpdb;
    $wpdb->delete(self::table(), ['user_id' => $studentId, 'module_id' => $moduleId]);
  }

  /**
   * Grades $answers (questionId => option index) and persists the attempt,
   * replacing any previous one for this student+module.
   */
  public static function grade(int $studentId, array $config, array $answers): array {
    global $wpdb;

    $correctCount = 0;
    $breakdown = [];

    foreach ($config['questions'] as $question) {
      $given = array_key_exists($question['id'], $answers) ? (int) $answers[$question['id']] : -1;
      $isCorrect = $given === (int) $question['correct'];
      if ($isCorrect) $correctCount++;

      $breakdown[] = [
        'id' => $question['id'],
        'answered' => $given,
        'correct' => $isCorrect,
      ];
    }

    $total = count($config['questions']);
    $score = $total > 0 ? (int) round($correctCount / $total * 100) : 0;
    // With no minimum score there is nothing to fail — every submitted
    // attempt counts as passed so a quiz can never gate by accident.
    $passed = $config['pass_score'] > 0 ? $score >= $config['pass_score'] : true;

    $stored = [];
    foreach ($answers as $questionId => $optionIndex) {
      $stored[(int) $questionId] = (int) $optionIndex;
    }

    $wpdb->replace(self::table(), [
      'user_id' => $studentId,
      'course_id' => $config['course_id'],
      'module_id' => $config['module_id'],
      'score' => $score,
      'correct_count' => $correctCount,
      'total_count' => $total,
      'passed' => $passed ? 1 : 0,
      'answers' => wp_json_encode($stored),
      'submitted_at' => current_time('mysql'),
    ]);

    return [
      'score' => $score,
      'correct_count' => $correctCount,
      'total_count' => $total,
      'passed' => $passed,
      'breakdown' => $breakdown,
    ];
  }

  public static function has_passed(int $studentId, int $moduleId): bool {
    $attempt = self::attempt($studentId, $moduleId);
    return $attempt !== null && $attempt['passed'];
  }

  // --- Progress gating -----------------------------------------------------

  /**
   * Cheap pre-check so the (relatively expensive) lock map isn't built for the
   * overwhelming majority of courses, which have no blocking quiz at all.
   */
  public static function has_blocking_quiz(int $courseId): bool {
    global $wpdb;

    $count = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*)
       FROM {$wpdb->postmeta} pm_course
       INNER JOIN {$wpdb->postmeta} pm_enabled
         ON pm_enabled.post_id = pm_course.post_id
        AND pm_enabled.meta_key = 'quiz_enabled' AND pm_enabled.meta_value = '1'
       INNER JOIN {$wpdb->postmeta} pm_block
         ON pm_block.post_id = pm_course.post_id
        AND pm_block.meta_key = 'quiz_block_progress' AND pm_block.meta_value = '1'
       WHERE pm_course.meta_key = 'course' AND pm_course.meta_value = %d",
      $courseId
    ));

    return (int) $count > 0;
  }

  /**
   * Modules shut behind a not-yet-passed blocking quiz, as [moduleId => true].
   *
   * Modules are walked in course order; once a module with a blocking,
   * unpassed quiz is hit, every module AFTER it is locked — lessons and quiz
   * alike (otherwise a student stuck at module 1 could still sit module 3's
   * quiz, which would make the gate pointless). The blocking module itself
   * stays open: its lessons are what you need to pass its quiz.
   */
  public static function blocked_modules(int $studentId, int $courseId, array $courseTree = null): array {
    if (!self::has_blocking_quiz($courseId)) return [];

    if ($courseTree === null) {
      $courseTree = Course::get_courses_tree([$courseId]);
    }
    $course = $courseTree[$courseId] ?? null;
    if (!$course) return [];

    $blockedModules = [];
    $blocked = false;

    foreach (($course['modules'] ?? []) as $moduleId => $module) {
      if ($blocked) {
        $blockedModules[(int) $moduleId] = true;
        continue;
      }

      $config = $module['quiz'] ?? self::summary((int) $moduleId);
      if (self::is_blocking($config) && !self::has_passed($studentId, (int) $moduleId)) {
        $blocked = true;
      }
    }

    return $blockedModules;
  }

  /**
   * Module quizzes shut by the course's sequential-progress rule, as
   * [moduleId => true].
   *
   * A quiz renders after the last lesson of its module, so on a sequential
   * course it has to obey the same order the lessons do: it stays locked
   * until every counted lesson up to and including its own module is
   * finished. Without this a student could jump straight to the quiz from a
   * course they'd barely started.
   *
   * Uses SEQUENTIAL_GATE_THRESHOLD (not the 100%/95% reporting clamp) so the
   * quiz opens on exactly the same condition that opens the next lesson —
   * two different bars would be visibly inconsistent in the sidebar.
   *
   * Modules with count_progress = false opt out entirely, mirroring the rule
   * for their lessons: the sequential gate never locks non-counting content.
   */
  public static function sequentially_locked_modules(int $studentId, int $courseId, array $courseTree = null): array {
    if (get_post_meta($courseId, 'sequential_progress', true) !== '1') return [];

    if ($courseTree === null) {
      $courseTree = Course::get_courses_tree([$courseId]);
    }
    $course = $courseTree[$courseId] ?? null;
    if (!$course) return [];

    $locked = [];
    $incompleteSeen = false;

    foreach (($course['modules'] ?? []) as $moduleId => $module) {
      $counts = !empty($module['count_progress']);

      // Scan this module's own lessons BEFORE deciding about its quiz — the
      // quiz sits after them, so an unfinished lesson here locks it.
      if (!$incompleteSeen && $counts) {
        foreach (($module['lessons'] ?? []) as $lessonId => $_) {
          $progress = ProgressManager::getLessonProgress($studentId, $courseId, (int) $lessonId, $courseTree);
          if (((int) $progress['percent']) < ProgressManager::SEQUENTIAL_GATE_THRESHOLD) {
            $incompleteSeen = true;
            break;
          }
        }
      }

      if ($incompleteSeen && $counts) {
        $locked[(int) $moduleId] = true;
      }
    }

    return $locked;
  }

  /**
   * Every reason a module's quiz can be shut: an earlier blocking quiz that
   * hasn't been passed, or the sequential-progress order. Single entry point
   * so the sidebar's `locked` flag and the server-side refusal can't drift.
   */
  public static function locked_modules(int $studentId, int $courseId, array $courseTree = null): array {
    if ($courseTree === null) {
      $courseTree = Course::get_courses_tree([$courseId]);
    }

    return self::blocked_modules($studentId, $courseId, $courseTree)
      + self::sequentially_locked_modules($studentId, $courseId, $courseTree);
  }

  /**
   * The quiz-blocking gate expressed per lesson, as [lessonId => false].
   * Absence from the map means "this rule doesn't lock it" — same convention
   * as Student::sequential_lock_map.
   */
  public static function locked_lessons(int $studentId, int $courseId, array $courseTree = null): array {
    $blockedModules = self::blocked_modules($studentId, $courseId, $courseTree);
    if (empty($blockedModules)) return [];

    if ($courseTree === null) {
      $courseTree = Course::get_courses_tree([$courseId]);
    }

    $locked = [];
    foreach (($courseTree[$courseId]['modules'] ?? []) as $moduleId => $module) {
      if (empty($blockedModules[(int) $moduleId])) continue;
      foreach (($module['lessons'] ?? []) as $lessonId => $_) {
        $locked[(int) $lessonId] = false;
      }
    }

    return $locked;
  }

  // --- AJAX: student ------------------------------------------------------

  /**
   * A quiz in a module that an earlier blocking quiz has shut is off limits —
   * otherwise a student stuck at module 1 could still sit (and pass) the quiz
   * of module 3 and unlock their way forward.
   */
  private static function assert_module_open(int $studentId, array $config): void {
    $locked = self::locked_modules($studentId, (int) $config['course_id']);
    if (!empty($locked[(int) $config['module_id']])) {
      throw new Exception(__('הבוחן נעול עד להשלמת השיעורים הקודמים בקורס', 'future-lms'));
    }
  }

  /**
   * Summary of every quiz in a course plus this student's standing, for the
   * course sidebar. Deliberately question-free — the question set only ships
   * when the student actually opens the quiz.
   */
  public function ajax_get_course_quizzes() {
    try {
      $studentId = get_current_user_id();
      $courseId = intval($_REQUEST['course_id'] ?? 0);

      $student = new Student($studentId);
      if (!$courseId || !$student->is_attending_course($courseId)) {
        throw new Exception(__('Access denied', 'future-lms'));
      }

      $tree = Course::get_courses_tree([$courseId]);
      $lockedModules = self::locked_modules($studentId, $courseId, $tree);
      $result = [];

      foreach (($tree[$courseId]['modules'] ?? []) as $moduleId => $module) {
        $config = $module['quiz'] ?? self::summary((int) $moduleId);
        if (!$config['enabled']) continue;

        $attempt = self::attempt($studentId, (int) $moduleId);

        $result[] = [
          'module_id' => (int) $moduleId,
          'module_order' => (int) ($module['order'] ?? 0),
          // Carried so the sidebar can render a module that exists only to
          // hold a final exam — it has no lessons, so nothing else in the
          // payload would tell the client the module is there.
          'module_name' => $module['name'] ?? '',
          'intro_module' => !empty($module['intro_module']),
          'title' => $config['title'],
          'question_count' => (int) $config['question_count'],
          'pass_score' => $config['pass_score'],
          'blocking' => self::is_blocking($config),
          'locked' => !empty($lockedModules[(int) $moduleId]),
          'attempt' => $attempt ? [
            'score' => $attempt['score'],
            'correct_count' => $attempt['correct_count'],
            'total_count' => $attempt['total_count'],
            'passed' => $attempt['passed'],
          ] : null,
        ];
      }

      wp_send_json($result);
    } catch (Exception $ex) {
      wp_send_json(['error' => $ex->getMessage()]);
    }
  }

  public function ajax_get_quiz() {
    try {
      $studentId = get_current_user_id();
      $moduleId = intval($_REQUEST['module_id'] ?? 0);

      $config = self::config($moduleId);
      $student = new Student($studentId);

      if (!$config['enabled'] || !$config['course_id'] || !$student->is_attending_course($config['course_id'])) {
        throw new Exception(__('Access denied', 'future-lms'));
      }
      self::assert_module_open($studentId, $config);

      $attempt = self::attempt($studentId, $moduleId);
      // Answers are only ever revealed after the student has committed to
      // one, and only on a non-gating quiz.
      $reveal = $attempt !== null && self::can_reveal($config);

      wp_send_json([
        'module_id' => $moduleId,
        'course_id' => $config['course_id'],
        'title' => $config['title'],
        'pass_score' => $config['pass_score'],
        'blocking' => self::is_blocking($config),
        'can_reveal' => self::can_reveal($config),
        'questions' => self::public_questions($config, $reveal),
        'attempt' => $attempt,
      ]);
    } catch (Exception $ex) {
      wp_send_json(['error' => $ex->getMessage()]);
    }
  }

  public function ajax_submit_quiz() {
    try {
      $studentId = get_current_user_id();
      $moduleId = intval($_REQUEST['module_id'] ?? 0);

      $config = self::config($moduleId);
      $student = new Student($studentId);

      if (!$config['enabled'] || !$config['course_id'] || !$student->is_attending_course($config['course_id'])) {
        throw new Exception(__('Access denied', 'future-lms'));
      }
      self::assert_module_open($studentId, $config);

      $answers = $_REQUEST['answers'] ?? '{}';
      if (is_string($answers)) {
        $answers = json_decode(stripslashes($answers), true);
      }
      if (!is_array($answers)) $answers = [];

      $result = self::grade($studentId, $config, $answers);
      $reveal = self::can_reveal($config);

      wp_send_json([
        'score' => $result['score'],
        'correct_count' => $result['correct_count'],
        'total_count' => $result['total_count'],
        'passed' => $result['passed'],
        'pass_score' => $config['pass_score'],
        'can_reveal' => $reveal,
        // Which questions were right, and the right answer, only when the
        // quiz allows revealing.
        'breakdown' => $reveal ? $result['breakdown'] : null,
        'questions' => $reveal ? self::public_questions($config, true) : null,
      ]);
    } catch (Exception $ex) {
      wp_send_json(['error' => $ex->getMessage()]);
    }
  }

  public function ajax_reset_quiz() {
    try {
      $studentId = get_current_user_id();
      $moduleId = intval($_REQUEST['module_id'] ?? 0);

      $config = self::config($moduleId);
      $student = new Student($studentId);

      if (!$config['course_id'] || !$student->is_attending_course($config['course_id'])) {
        throw new Exception(__('Access denied', 'future-lms'));
      }

      self::reset_attempt($studentId, $moduleId);
      wp_send_json(['ok' => true]);
    } catch (Exception $ex) {
      wp_send_json(['error' => $ex->getMessage()]);
    }
  }

  // --- AJAX: admin --------------------------------------------------------

  /** Full quiz incl. the answer key — administrators only. */
  public function ajax_get_quiz_details() {
    try {
      if (!current_user_can('manage_options')) {
        throw new Exception(__('Access denied', 'future-lms'));
      }

      $moduleId = intval($_REQUEST['module_id'] ?? 0);
      if (!$moduleId || get_post_type($moduleId) !== 'module') {
        throw new Exception(__('Module not found', 'future-lms'));
      }

      $config = self::config($moduleId);
      // config() reports `enabled: false` for a quiz with no questions yet,
      // which is right for gating but wrong for the editor — the switch has
      // to reflect what's actually stored so a half-built quiz doesn't
      // silently turn itself off on the next save.
      $config['enabled'] = get_post_meta($moduleId, 'quiz_enabled', true) === '1';
      $config['error'] = false;

      wp_send_json($config);
    } catch (Exception $ex) {
      wp_send_json(['error' => true, 'message' => $ex->getMessage()]);
    }
  }

  public function ajax_edit_quiz() {
    try {
      if (!current_user_can('manage_options')) {
        throw new Exception(__('Access denied', 'future-lms'));
      }

      $moduleId = intval($_POST['module_id'] ?? 0);
      if (!$moduleId || get_post_type($moduleId) !== 'module') {
        throw new Exception(__('Module not found', 'future-lms'));
      }

      $questions = $_POST['questions'] ?? '[]';
      if (is_string($questions)) {
        $questions = json_decode(stripslashes($questions), true);
      }
      $questions = self::sanitize_questions($questions);

      $passScore = max(0, min(100, intval($_POST['pass_score'] ?? 0)));
      $enabled = ($_POST['enabled'] ?? '0') === '1' ? '1' : '0';
      $blockProgress = ($_POST['block_progress'] ?? '0') === '1' ? '1' : '0';
      $revealAnswers = ($_POST['reveal_answers'] ?? '0') === '1' ? '1' : '0';

      // Enforce the mutual exclusion server-side too: the admin UI hides the
      // irrelevant toggle, but a stale form shouldn't be able to save a
      // gating quiz that also gives the answers away.
      if ($passScore === 0) $blockProgress = '0';
      else $revealAnswers = '0';

      update_post_meta($moduleId, 'quiz_enabled', $enabled);
      update_post_meta($moduleId, 'quiz_title', sanitize_text_field($_POST['title'] ?? ''));
      update_post_meta($moduleId, 'quiz_pass_score', $passScore);
      update_post_meta($moduleId, 'quiz_block_progress', $blockProgress);
      update_post_meta($moduleId, 'quiz_reveal_answers', $revealAnswers);
      // wp_slash is required, not decorative: update_post_meta() runs
      // wp_unslash() on the value, and the JSON we just built carries its own
      // backslashes (`\uXXXX` for every Hebrew character, `\"` for a quote
      // inside an answer). Handing it over unslashed let WP strip exactly
      // those, storing a string that no longer parses — the questions saved,
      // then read back as an empty quiz. Pre-slashing makes WP's unslash a
      // round trip.
      update_post_meta($moduleId, 'quiz_questions', wp_slash(wp_json_encode($questions)));
      // Denormalized so summary() never has to decode the questions JSON —
      // see the note there.
      update_post_meta($moduleId, 'quiz_question_count', count($questions));

      wp_send_json(['error' => false, 'questions' => $questions]);
    } catch (Exception $ex) {
      wp_send_json(['error' => true, 'message' => $ex->getMessage()]);
    }
  }
}
