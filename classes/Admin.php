<?php
namespace FutureLMS\classes;

use Exception;

class Admin {
  private static $instance;

  public static function get_instance() {
      if (!isset(self::$instance)) {
          self::$instance = new Admin();
      }
      return self::$instance;
  }

  protected function __construct() {
    add_action("wp_ajax_edit_course", [$this, "edit_course"]);
    add_action("wp_ajax_change_course_status", [$this, "change_course_status"]);
    add_action("wp_ajax_change_module_status", [$this, "change_module_status"]);
    add_action("wp_ajax_change_lesson_status", [$this, "change_lesson_status"]);
    add_action("wp_ajax_edit_module", [$this, "edit_module"]);
    add_action("wp_ajax_reorder_module", [$this, "reorder_module"]);
    add_action("wp_ajax_reorder_lesson", [$this, "reorder_lesson"]);
	  add_action( "wp_ajax_edit_class", [ $this, "edit_class" ] );
	  add_action( "wp_ajax_get_lesson_details", [ $this, "get_lesson_details" ] );
	  add_action( "wp_ajax_edit_lesson", [ $this, "edit_lesson" ] );
    add_action("wp_ajax_set_video_duration", [$this, "set_video_duration"]);
    add_action("wp_ajax_set_lesson_duration", [$this, "set_lesson_duration"]);
    add_action("wp_ajax_set_course_duration", [$this, "set_course_duration"]);
  }

  // wp_ajax_* runs for any logged-in user - students included - so every
  // admin-screen handler has to check for itself.
  private function guard_admin() {
    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
  }

  // ---------------------------------------------------------------------------
  // Durations. The course editor measures each video in the browser and
  // reports it here; lesson and course lengths are derived from those, and
  // progress percentages are derived from the lengths.
  // ---------------------------------------------------------------------------

  public function set_video_duration() {
    $this->guard_admin();

    $lessonId = absint($_POST["lesson_id"] ?? 0);
    $videoId = sanitize_text_field($_POST['video_id'] ?? '');
    $duration = absint($_POST["duration"] ?? 0);

    $videos = $this->lesson_videos($lessonId);
    $updated = false;

    foreach ($videos as &$video) {
      if (($video['video_id'] ?? null) === $videoId && $duration > 0) {
        $video['video_duration'] = $duration;
        $updated = true;
      }
    }
    unset($video);

    if ($updated) {
      update_post_meta($lessonId, 'video_list', wp_json_encode($videos));
    }

    $this->update_lesson_duration($lessonId);
    wp_send_json_success();
  }

  public function set_lesson_duration() {
    $this->guard_admin();

    $lessonId = absint($_POST["lesson_id"] ?? 0);
    if (!$lessonId || !get_post($lessonId)) {
      wp_send_json_error('Lesson not found');
    }

    $this->update_lesson_duration($lessonId);
    wp_send_json_success();
  }

  public function set_course_duration() {
    $this->guard_admin();

    global $wpdb;
    $course_id = absint($_POST["course_id"] ?? 0);

    if (!$course_id || get_post($course_id) === null) {
      wp_send_json_error(['message' => 'Course not found.']);
    }

    // Published lessons of the course's published modules; the second sum
    // leaves out modules that do not count towards progress.
    $sql = "SELECT SUM(CAST(ld.meta_value AS UNSIGNED))
      FROM {$wpdb->postmeta} l
      JOIN {$wpdb->postmeta} ld ON ld.post_id = l.post_id AND ld.meta_key = 'lesson_duration'
      JOIN {$wpdb->posts} p_lesson ON p_lesson.ID = l.post_id AND p_lesson.post_status = 'publish'
      WHERE l.meta_key = 'module'
        AND l.meta_value IN (
          SELECT p.ID
          FROM {$wpdb->posts} p
          JOIN {$wpdb->postmeta} pm_course
            ON pm_course.post_id = p.ID AND pm_course.meta_key = 'course' AND pm_course.meta_value = %d
          %COUNTED%
          WHERE p.post_type = 'module' AND p.post_status = 'publish'
        )";
    $counted_join = "JOIN {$wpdb->postmeta} pm_count
            ON pm_count.post_id = p.ID AND pm_count.meta_key = 'count_progress' AND pm_count.meta_value = '1'";

    $total_duration = $wpdb->get_var($wpdb->prepare(str_replace('%COUNTED%', '', $sql), $course_id));
    $counted_duration = $wpdb->get_var($wpdb->prepare(str_replace('%COUNTED%', $counted_join, $sql), $course_id));

    update_post_meta($course_id, 'course_total_duration', $total_duration);
    update_post_meta($course_id, 'course_counted_duration', $counted_duration);

    wp_send_json_success([
      'message' => 'Course duration updated successfully.',
      'lesson_duration' => $total_duration,
      'counted_duration' => $counted_duration
    ]);
  }

  private function lesson_videos($lessonId) {
    $raw = get_post_meta($lessonId, 'video_list', true);
    $videos = is_string($raw) ? json_decode($raw, true) : $raw;
    return is_array($videos) ? $videos : [];
  }

  // A lesson is as long as its videos; a lesson without videos is as long as
  // its text takes to read, and never under a minute.
  private function update_lesson_duration($lessonId) {
    $post = get_post($lessonId);
    if (!$post) {
      return;
    }

    $videos = $this->lesson_videos($lessonId);
    $duration = 0;

    if (!empty($videos)) {
      foreach ($videos as $video) {
        if (!empty($video['video_id']) && !empty($video['video_duration'])) {
          $duration += absint($video['video_duration']);
        }
      }
    } else {
      $text = html_entity_decode(strip_tags((string) $post->post_content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
      preg_match_all('/\p{L}+/u', $text, $words);
      $minutes = round(count($words[0]) / 200); // 200 words a minute
      $duration = max(60, $minutes * 60);
    }

    update_post_meta($lessonId, 'lesson_duration', $duration);
  }

  public function edit_class() {
    $this->guard_admin();

    try{
      $classId = isset($_POST["class_id"]) ? $_POST["class_id"] : "";
      $name = $_POST["name"];
      $courseId = isset($_POST["course_id"]) ? $_POST["course_id"] : false;
      $startDate = isset($_POST['start_date']) ? $_POST['start_date'] : null;
      $lessons = isset($_POST['lessons']) ? $_POST['lessons'] : "[]";

      if (empty($classId)) {
        //create new course, a course is a wp_post with post_type = course
        $classId = wp_insert_post([
          'post_title' => $name,
          'post_status' => 'draft',
          'post_type' => 'class'
        ]);
      } else {
        wp_update_post([
          'ID' => $classId,
          'post_title' => $name
        ]);
      }

      update_post_meta($classId, 'course', $courseId);
      update_post_meta($classId, 'start_date', $startDate);
      update_post_meta($classId, 'lessons', stripslashes($lessons));

      wp_send_json(['error' => false]);
    } catch (Exception $e) {
      wp_send_json(['error' => true, 'message' => $e->getMessage()]);
    }
  }


  public function get_lesson_details() {
    $this->guard_admin();

    try {
      $lessonId = intval($_POST["lesson_id"]);

      $lesson = new Lesson($lessonId);
      if (!$lesson || !$lesson->exists()) {
        wp_send_json(['error' => true, 'message' => 'Lesson not found']);
      }

      $videoListRaw = $lesson->field('video_list');
      $videos = [];
      if (!empty($videoListRaw)) {
        $decoded = json_decode($videoListRaw, true);
        if (is_array($decoded)) {
          foreach ($decoded as $item) {
            if (is_array($item) && isset($item['video_id'])) {
              $videos[] = $item['video_id'];
            }
          }
        } else {
          // Fallback: support legacy newline-separated values
          $lines = preg_split("/(\r\n|\n|\r)/", $videoListRaw);
          $videos = array_values(array_filter(array_map('trim', $lines)));
        }
      }

      $moduleId = intval(get_post_meta($lessonId, 'module', true));
      $lessonNumber = intval(get_post_meta($lessonId, 'lesson_number', true));
      $presentationId = intval(get_post_meta($lessonId, 'presentation', true));
      $presentationUrl = $presentationId ? wp_get_attachment_url($presentationId) : '';
      $presentationIcon = $presentationId ? wp_mime_type_icon($presentationId) : '';
      $presentationFilename = $presentationUrl ? wp_basename($presentationUrl) : '';
      $presentationMime = $presentationId ? get_post_mime_type($presentationId) : '';

      $post = get_post($lessonId);

      wp_send_json([
        'error' => false,
        'id' => $lessonId,
        'name' => $lesson->raw('title'),
        'content' => $post ? wpautop($post->post_content) : '',
        'teaser' => $lesson->raw('teaser') ?? '',
        'videos' => $videos,
        'homework' => wpautop($lesson->raw('homework') ?? ''),
        'additional_files' => wpautop($lesson->raw('additional_files') ?? ''),
        'module_id' => $moduleId,
        'lesson_number' => $lessonNumber,
        'presentation_id' => $presentationId,
        'presentation_url' => $presentationUrl,
        'presentation_icon' => $presentationIcon,
        'presentation_filename' => $presentationFilename,
        'presentation_mime' => $presentationMime
      ]);
    } catch (Exception $e) {
      wp_send_json(['error' => true, 'message' => $e->getMessage()]);
    }
  }

  public function edit_lesson() {
    $this->guard_admin();

    try {
      $lessonId = intval($_POST["lesson_id"]);
      $name = isset($_POST["name"]) ? stripslashes($_POST["name"]) : '';
      $teaser = isset($_POST["teaser"]) ? stripslashes($_POST["teaser"]) : '';
      $videoListJson = isset($_POST["video_list"]) ? stripslashes($_POST["video_list"]) : '[]';
      $homework = isset($_POST["homework"]) ? stripslashes($_POST["homework"]) : '';
      $additional_files = isset($_POST["additional_files"]) ? stripslashes($_POST["additional_files"]) : '';
      $content = isset($_POST["content"]) ? stripslashes($_POST["content"]) : '';
      $newModuleId = isset($_POST["module_id"]) ? intval($_POST["module_id"]) : null;
      $lessonNumber = isset($_POST["lesson_number"]) ? intval($_POST["lesson_number"]) : null;
      $presentationId = isset($_POST["presentation"]) ? intval($_POST["presentation"]) : 0;

      $isNewLesson = empty($lessonId);
      
      if ($isNewLesson) {
        // Create new lesson
        if (empty($newModuleId) || empty($name)) {
          wp_send_json(['error' => true, 'message' => 'Module ID and lesson name are required']);
        }
        
        $lessonId = wp_insert_post([
          'post_title' => $name,
          'post_content' => wp_kses_post($content),
          'post_status' => 'draft',
          'post_type' => 'lesson',
          'post_parent' => $newModuleId
        ]);
        
        if (is_wp_error($lessonId)) {
          wp_send_json(['error' => true, 'message' => 'Failed to create lesson']);
        }
      } else {
        wp_update_post([
          'ID' => $lessonId,
          'post_title' => $name,
          'post_content' => wp_kses_post($content)
        ]);
      }

      // Normalize and save meta
      update_post_meta($lessonId, 'teaser', sanitize_text_field($teaser));

      $decoded = json_decode($videoListJson, true);
      if (!is_array($decoded)) {
        $decoded = [];
      }
      update_post_meta($lessonId, 'video_list', wp_json_encode($decoded));

      update_post_meta($lessonId, 'homework', wp_kses_post($homework));
      update_post_meta($lessonId, 'additional_files', wp_kses_post($additional_files));
      // Delete rather than storing 0, so "no presentation" is a genuinely empty
      // field instead of a falsy-looking id that still reads as a value.
      if ($presentationId) {
        update_post_meta($lessonId, 'presentation', $presentationId);
      } else {
        delete_post_meta($lessonId, 'presentation');
      }


      if ($isNewLesson) {
        // For new lessons, set module and lesson number
        update_post_meta($lessonId, 'module', $newModuleId);
        if ($lessonNumber === null || $lessonNumber < 1) {
          global $wpdb;
          $order = $wpdb->get_var("select ifnull(max(cast(pm1.meta_value as unsigned)),0)
            from " . $wpdb->prefix . "postmeta pm1
            inner join " . $wpdb->prefix . "postmeta pm2 on pm2.meta_key = 'module' and pm2.meta_value = $newModuleId
            where pm1.meta_key = 'lesson_number'
            and pm2.post_id = pm1.post_id");
          $lessonNumber = intval($order) + 1;
        }
        update_post_meta($lessonId, 'lesson_number', $lessonNumber);
        $this->fix_lessons_order($newModuleId);
      } else {
        // For existing lessons, handle module changes
        $oldModuleId = intval(get_post_meta($lessonId, 'module', true));
        $targetModuleId = $newModuleId !== null ? $newModuleId : $oldModuleId;

        if ($targetModuleId !== $oldModuleId && $targetModuleId > 0) {
          update_post_meta($lessonId, 'module', $targetModuleId);
          if ($lessonNumber === null || $lessonNumber < 1) {
            global $wpdb;
            $order = $wpdb->get_var("select ifnull(max(cast(pm1.meta_value as unsigned)),0)
              from " . $wpdb->prefix . "postmeta pm1
              inner join " . $wpdb->prefix . "postmeta pm2 on pm2.meta_key = 'module' and pm2.meta_value = $targetModuleId
              where pm1.meta_key = 'lesson_number'
              and pm2.post_id = pm1.post_id");
            $lessonNumber = intval($order) + 1;
          }
          update_post_meta($lessonId, 'lesson_number', $lessonNumber);
          $this->fix_lessons_order($oldModuleId);
          $this->fix_lessons_order($targetModuleId);
        } else {
          if ($lessonNumber !== null && $lessonNumber > 0) {
            update_post_meta($lessonId, 'lesson_number', $lessonNumber);
            $this->fix_lessons_order($targetModuleId);
          }
        }
      }

      wp_send_json(['error' => false]);
    } catch (Exception $e) {
      wp_send_json(['error' => true, 'message' => $e->getMessage()]);
    }
  }

  public function reorder_lesson() {
    $this->guard_admin();

    global $wpdb;

    $moduleId = $_POST["module_id"];
    $lessonId = $_POST["lesson_id"];

    $direction = $_POST["direction"];

    //get all modules of this course ordered by order, and update their order
    $sql = "SELECT posts.ID as lesson_id, pm2.meta_value as 'lesson_number'
      FROM ".$wpdb->prefix."postmeta pm1
      INNER JOIN ".$wpdb->prefix."posts posts on posts.ID = pm1.post_id
      INNER JOIN ".$wpdb->prefix."postmeta pm2 on pm2.meta_key = 'lesson_number' and pm2.post_id = pm1.post_id
      WHERE posts.post_status <> 'trash'
      AND pm1.meta_key = 'module' and pm1.post_id = posts.ID and pm1.meta_value = $moduleId
      ORDER BY CAST(pm2.meta_value as UNSIGNED)";

    $lessons = $wpdb->get_results($sql, ARRAY_A);

    //find position of current lessonId in lessons array
    for ($i = 0; $i < count($lessons); $i++) {
      if ($lessons[$i]["lesson_id"] == $lessonId) {
        if ($direction == "1") {
          //move down
          if ($i == count($lessons) - 1) {
            //already last, do nothing
            wp_send_json(['error' => false]);
          }

          $temp = $lessons[$i];
          $lessons[$i] = $lessons[$i + 1];
          $lessons[$i + 1] = $temp;
        } else {
          //move up
          if ($i == 0) {
            //already first, do nothing
            wp_send_json(['error' => false]);
          }

          $temp = $lessons[$i];
          $lessons[$i] = $lessons[$i - 1];
          $lessons[$i - 1] = $temp;
        }
        break;
      }
    }

    //now lets renumber the order from 1 and up
    for ($i = 0; $i < count($lessons); $i++) {
      update_post_meta($lessons[$i]["lesson_id"], 'lesson_number', $i + 1);
    }

    wp_send_json(['error' => false]);
  }

  public function reorder_module() {
    $this->guard_admin();

    global $wpdb;

    $courseId = $_POST["course_id"];
    $moduleId = $_POST["module_id"];
    $direction = $_POST["direction"];

    //get all modules of this course ordered by order, and update their order
    $sql = "SELECT posts.ID as module_id, pm2.meta_value as 'order'
      FROM ".$wpdb->prefix."postmeta pm1
      INNER JOIN ".$wpdb->prefix."posts posts on posts.ID = pm1.post_id
      INNER JOIN ".$wpdb->prefix."postmeta pm2 on pm2.meta_key = 'order' and pm2.post_id = pm1.post_id
      WHERE posts.post_status <> 'trash'
      AND pm1.meta_key = 'course' and pm1.post_id = posts.ID and pm1.meta_value = $courseId
      ORDER BY CAST(pm2.meta_value as UNSIGNED)";

    $modules = $wpdb->get_results($sql, ARRAY_A);

    //find position of current moduleId in modules array
    for ($i = 0; $i < count($modules); $i++) {
      if ($modules[$i]["module_id"] == $moduleId) {
        if ($direction == "1") {
          //move down
          if ($i == count($modules) - 1) {
            //already last, do nothing
            wp_send_json(['error' => false]);
          }

          $temp = $modules[$i];
          $modules[$i] = $modules[$i + 1];
          $modules[$i + 1] = $temp;
        } else {
          //move up
          if ($i == 0) {
            //already first, do nothing
            wp_send_json(['error' => false]);
          }

          $temp = $modules[$i];
          $modules[$i] = $modules[$i - 1];
          $modules[$i - 1] = $temp;
        }
        break;
      }
    }

    //now lets renumber the order from 1 and up
    for ($i = 0; $i < count($modules); $i++) {
      update_post_meta($modules[$i]["module_id"], 'order', $i + 1);
    }

    wp_send_json(['error' => false]);
  }

  public function edit_module() {
    $this->guard_admin();

    global $wpdb;

    $courseId = $_POST["course_id"];
    $moduleId = isset($_POST["module_id"]) ? $_POST["module_id"] : false;
    $name = $_POST["name"];
    $teaser = $_POST["teaser"] ?? '';
    $count_progress = $_POST["count_progress"] ?? '1';
    $intro_module = $_POST["intro_module"] ?? '0';

    if (!$moduleId) {
      $wpdb->insert($wpdb->prefix."posts", [
        "post_title" => $name,
        "post_status" => "draft",
        "post_type" => "module",
        "post_parent" => $courseId
      ]);

      $moduleId = $wpdb->insert_id;
      $order = $wpdb->get_var("select ifnull(max(pm1.meta_value),0)
      from ".$wpdb->prefix."postmeta pm1
      inner join ".$wpdb->prefix."postmeta pm2 on pm2.meta_key = 'course' and pm2.meta_value = $courseId
      where pm1.meta_key = 'order'
      and pm2.post_id = pm1.post_id");
      update_post_meta($moduleId, 'order', intval($order) + 1);
      update_post_meta($moduleId, 'course', $courseId);
    } else {
      wp_update_post([
        'ID' => $moduleId,
        'post_title' => $name
      ]);
    }

    update_post_meta($moduleId, 'count_progress', $count_progress);
    update_post_meta($moduleId, 'intro_module', $intro_module);
    update_post_meta($moduleId, 'teaser', sanitize_text_field($teaser));

    wp_send_json(['error' => false]);
  }


  public function change_lesson_status() {
    $this->guard_admin();

    global $wpdb;

    $lessonId = $_POST["lesson_id"];
    $moduleId = $_POST["module_id"];
    $status = $_POST["status"];

    $wpdb->update($wpdb->prefix."posts", ["post_status" => $status], ["ID" => $lessonId]);
    $this->fix_lessons_order($moduleId);

    wp_send_json(['error' => false]);
  }

  private function fix_lessons_order($moduleId) {
    global $wpdb;

    //get all modules of this course ordered by order, and update their order
    $sql = "SELECT posts.ID as lesson_id, pm2.meta_value as 'lesson_number'
      FROM ".$wpdb->prefix."postmeta pm1
      INNER JOIN ".$wpdb->prefix."posts posts on posts.ID = pm1.post_id
      INNER JOIN ".$wpdb->prefix."postmeta pm2 on pm2.meta_key = 'lesson_number' and pm2.post_id = pm1.post_id
      WHERE posts.post_status <> 'trash'
      AND pm1.meta_key = 'module' and pm1.post_id = posts.ID and pm1.meta_value = $moduleId
      ORDER BY CAST(pm2.meta_value as UNSIGNED)";

    $lessons = $wpdb->get_results($sql, ARRAY_A);

    //now lets renumber the order from 1 and up
    for ($i = 0; $i < count($lessons); $i++) {
      update_post_meta($lessons[$i]["lesson_id"], 'lesson_number', $i + 1);
    }
  }

  public function change_module_status() {
    $this->guard_admin();

    global $wpdb;

    $courseId = $_POST["course_id"];
    $moduleId = $_POST["module_id"];
    $status = $_POST["status"];

    $wpdb->update($wpdb->prefix."posts", ["post_status" => $status], ["ID" => $moduleId]);

    $this->fix_modules_order($courseId);

    wp_send_json(['error' => false]);
  }

  private function fix_modules_order($courseId) {
    global $wpdb;

    //get all modules of this course ordered by order, and update their order
    $sql = "SELECT posts.ID as module_id, pm2.meta_value as 'order'
      FROM ".$wpdb->prefix."postmeta pm1
      INNER JOIN ".$wpdb->prefix."posts posts on posts.ID = pm1.post_id
      INNER JOIN ".$wpdb->prefix."postmeta pm2 on pm2.meta_key = 'order' and pm2.post_id = pm1.post_id
      WHERE posts.post_status <> 'trash'
      AND pm1.meta_key = 'course' and pm1.post_id = posts.ID and pm1.meta_value = $courseId
      ORDER BY CAST(pm2.meta_value as UNSIGNED)";

    $modules = $wpdb->get_results($sql, ARRAY_A);

    //now lets renumber the order from 1 and up
    for ($i = 0; $i < count($modules); $i++) {
      update_post_meta($modules[$i]["module_id"], 'order', $i + 1);
    }
  }

  public function change_course_status() {
    $this->guard_admin();

    global $wpdb;

    $courseId = $_POST["course_id"];
    $status = $_POST["status"];

    $wpdb->update($wpdb->prefix."posts", ["post_status" => $status], ["ID" => $courseId]);

    wp_send_json(['error' => false]);
  }

  public function edit_course() {
    $this->guard_admin();

    try {
      $courseId = isset($_POST["course_id"]) ? $_POST["course_id"] : false;
      $name = $_POST["name"];
      $tags = $_POST["tags"];
      $default_class = $_POST["default_class"];

      if (empty($courseId)) {
        //create new course, a course is a wp_post with post_type = course
        $courseId = wp_insert_post([
          'post_title' => $name,
          'post_content' => wp_kses_post($_POST["post_content"] ?? ''),
          'post_status' => 'draft',
          'post_type' => 'course'
        ]);
      } else {
        wp_update_post([
          'ID' => $courseId,
          'post_title' => $name,
          'post_content' => wp_kses_post($_POST["post_content"] ?? '')
        ]);
      }

      $meta_fields = [
        'tags' => $tags,
        'default_class' => $default_class,
        'course_code' => $_POST["course_code"] ?? '',
        'short_name' => $_POST["short_name"] ?? '',
        'short_description' => $_POST["short_description"] ?? '',
        'what_you_learn' => $_POST["what_you_learn"] ?? '',
        'color' => $_POST["color"] ?? '#aabbcc',
        'full_price' => $_POST["full_price"] ?? '',
        'discount_price' => $_POST["discount_price"] ?? '',
        'maxpay' => $_POST["maxpay"] ?? '',
        'initial_student_count' => $_POST["initial_student_count"] ?? '0',
        'diploma_enabled' => $_POST["diploma_enabled"] ?? '0',
        'sequential_progress' => $_POST["sequential_progress"] ?? '0',
        'lecturer_name' => $_POST["lecturer_name"] ?? '',
        'lecturer_signature' => $_POST["lecturer_signature"] ?? ''
      ];
      
      foreach ($meta_fields as $key => $value) {
        update_post_meta($courseId, $key, $value);
      }
      
      if (isset($_POST["course_image"])) {
        set_post_thumbnail($courseId, $_POST["course_image"]);
      }

      // Trigger product creation/update after response is sent
      add_action('shutdown', function() use ($courseId) {
        do_action('future-lms/course_saved', $courseId, get_post($courseId));
      });

      wp_send_json(['error' => false]);
    } catch (Exception $e) {
      wp_send_json(['error' => true, 'message' => $e->getMessage()]);
    }
  }
}

$admin = Admin::get_instance();

?>