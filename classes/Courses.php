<?php

namespace FutureLMS\classes;

use Exception;

class Courses {
  private static $instance;

  public static function get_instance() {
    if (!isset(self::$instance)) {
      self::$instance = new Courses();
    }
    return self::$instance;
  }

  protected function __construct() {
    add_action("wp_ajax_edit_course", [$this, "editCourse"]);
    add_action("wp_ajax_change_course_status", [$this, "changeCourseStatus"]);
    add_action("wp_ajax_change_module_status", [$this, "changeModuleStatus"]);
    add_action("wp_ajax_change_lesson_status", [$this, "changeLessonStatus"]);
    add_action("wp_ajax_edit_module", [$this, "editModule"]);
    add_action("wp_ajax_reorder_module", [$this, "reorderModule"]);
    add_action("wp_ajax_reorder_lesson", [$this, "reorderLesson"]);
    add_action("wp_ajax_add_lesson", [$this, "addLesson"]);
  }

  public static function getCoursesTree($courses = null, $enabledOnly = true) {
    global $wpdb;
    $sql = "SELECT pcourse.id AS course_id, pcourse.post_title AS course_name, pcourse.post_status, pmprice.meta_value AS full_price,
    pmurl.meta_value AS course_page_url, pmcurl.meta_value AS charge_url, pmtags.meta_value AS tags
    FROM wp_posts pcourse
    INNER JOIN wp_postmeta pmprice ON pmprice.post_id = pcourse.id AND pmprice.meta_key = 'full_price'
    LEFT OUTER JOIN wp_postmeta pmurl ON pmurl.post_id = pcourse.id AND pmurl.meta_key = 'course_page_url'
    LEFT OUTER JOIN wp_postmeta pmcurl ON pmcurl.post_id = pcourse.id AND pmcurl.meta_key = 'charge_url'
    LEFT OUTER JOIN wp_postmeta pmtags ON pmtags.post_id = pcourse.id AND pmtags.meta_key = 'tags'
    WHERE pcourse.post_type = 'course'
    AND pcourse.post_status <> 'trash' ";

    if ($enabledOnly) {
      $sql .= "AND pcourse.post_status = 'publish'";
    }
    if ($courses) {
      $sql .= " AND pcourse.id IN (" . implode(",", $courses) . ")";
    }

    $sql .= " ORDER BY pcourse.post_title";

    $rows = $wpdb->get_results($sql, ARRAY_A);
    $result = [];

    foreach ($rows as $row) {
      $courseId = $row["course_id"];
      $result[$courseId] = [];
      $result[$courseId]["total"] = 0;
      $result[$courseId]["enabled"] = $row["post_status"] == "publish";
      $result[$courseId]["name"] = $row["course_name"];
      $result[$courseId]["price"] = $row["full_price"];
      $result[$courseId]["course_page_url"] = $row["course_page_url"];
      $result[$courseId]["charge_url"] = $row["charge_url"];
      $result[$courseId]["tags"] = $row["tags"];

      $course = &$result[$courseId];
      $course["modules"] = [];

      $sql = "SELECT pmodule.post_title AS 'module_name', pmodule.ID AS 'module_id',
        pm6.meta_value AS module_order, pmodule.post_status,
        case when pm5.meta_value = '1' then true else false end as count_progress,
        case when pm7.meta_value = '1' then true else false end as intro_module
        FROM wp_posts pmodule
        INNER JOIN wp_postmeta pm2 ON pm2.post_id = pmodule.id AND pm2.meta_key = 'course' AND pm2.meta_value = $courseId
        LEFT OUTER JOIN wp_postmeta pm5 ON pm5.post_id = pmodule.ID AND pm5.meta_key = 'count_progress'
        LEFT OUTER JOIN wp_postmeta pm6 ON pm6.post_id = pmodule.ID AND pm6.meta_key = 'order'
        LEFT OUTER JOIN wp_postmeta pm7 ON pm7.post_id = pmodule.ID AND pm7.meta_key = 'intro_module'
        WHERE pmodule.post_type = 'module'
        AND pmodule.post_status <> 'trash' ";

      if ($enabledOnly) {
        $sql .= " AND pmodule.post_status = 'publish'";
      }

      $sql .= " ORDER BY module_order";

      $moduleRows = $wpdb->get_results($sql, ARRAY_A);

      foreach ($moduleRows as $moduleRow) {
        $moduleId = $moduleRow["module_id"];
        $course["modules"][$moduleId] = [];
        $module = &$course["modules"][$moduleId];
        $module["name"] = $moduleRow["module_name"];
        $module["count_progress"] = $moduleRow["count_progress"] == "1";
        $module["intro_module"] = $moduleRow["intro_module"] == "1";
        $module["order"] = $moduleRow["module_order"];
        $module["enabled"] = $moduleRow["post_status"] == "publish";

        $module["lessons"] = [];

        $sql = "SELECT plesson.post_title AS 'lesson_name', plesson.ID AS lesson_id, pm4.meta_value AS video_list,
          pm7.meta_value AS lesson_number, plesson.post_status
          FROM wp_posts plesson
          INNER JOIN wp_postmeta pm3 ON pm3.post_id = plesson.id AND pm3.meta_key = 'module' AND pm3.meta_value = $moduleId
          LEFT OUTER JOIN wp_postmeta pm4 ON pm4.post_id = plesson.id AND pm4.meta_key = 'video_list'
          LEFT OUTER JOIN wp_postmeta pm7 ON pm7.post_id = plesson.ID AND pm7.meta_key = 'lesson_number'
          WHERE plesson.post_type = 'lesson'
          AND plesson.post_status <> 'trash' ";

        if ($enabledOnly) {
          $sql .= " AND plesson.post_status = 'publish'";
        }
        $sql .= " ORDER BY lesson_number";

        $lessonRows = $wpdb->get_results($sql, ARRAY_A);

        foreach ($lessonRows as $lessonRow) {
          $lessonId = $lessonRow["lesson_id"];
          $module["lessons"][$lessonId] = [];
          $lesson = &$module["lessons"][$lessonId];
          $lesson["name"] = $lessonRow["lesson_name"];
          $lesson["order"] = $lessonRow["lesson_number"];
          $lesson["enabled"] = $lessonRow["post_status"] == "publish";

          $videos = $lessonRow["video_list"];
          $videos = json_decode(empty($videos) ? "[]" : $videos, true);

          $lesson["videos"] = [];
          if (empty($videos)) {
            $lesson["videos"] = ["text"];
            $course["total"] += $moduleRow["count_progress"] == "1" ? 100 : 0;
          } else {
            foreach ($videos as $video) {
              $lesson["videos"][] = $video["video_id"];
              $course["total"] += $moduleRow["count_progress"] == "1" ? 100 : 0;
            }
          }
        }
      }
    }

    return $result;
  }

  public function addLesson() {
    global $wpdb;

    $moduleId = intval($_POST["module_id"]);
    $name = stripslashes($_POST["name"]);

    $wpdb->insert("wp_posts", [
      "post_title" => $name,
      "post_status" => "draft",
      "post_type" => "lesson",
      "post_parent" => $moduleId
    ]);

    $lessonId = $wpdb->insert_id;
    $order = $wpdb->get_var("select ifnull(max(cast(pm1.meta_value as unsigned int)),0)
    from wp_postmeta pm1
    inner join wp_postmeta pm2 on pm2.meta_key = 'module' and pm2.meta_value = $moduleId
    where pm1.meta_key = 'lesson_number'
    and pm2.post_id = pm1.post_id");
    update_post_meta($lessonId, 'lesson_number', intval($order) + 1);
    update_post_meta($lessonId, 'video_list', '');
    update_post_meta($lessonId, 'additional_files', '');
    update_post_meta($lessonId, 'homework', '');
//    update_post_meta($lessonId, 'module', $moduleId);
    $lesson = PodsWrapper::factory('lesson', $lessonId);
    $lesson->save('module', $moduleId);

    echo json_encode(['error' => false]);
    die();
  }

  public function reorderLesson() {
    global $wpdb;

    $moduleId = $_POST["module_id"];
    $lessonId = $_POST["lesson_id"];

    $direction = $_POST["direction"];

    //get all modules of this course ordered by order, and update their order
    $sql = "SELECT posts.ID as lesson_id, pm2.meta_value as 'lesson_number'
      FROM wp_postmeta pm1
      INNER JOIN wp_posts posts on posts.ID = pm1.post_id
      INNER JOIN wp_postmeta pm2 on pm2.meta_key = 'lesson_number' and pm2.post_id = pm1.post_id
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
            echo json_encode(['error' => false]);
            die();
          }

          $temp = $lessons[$i];
          $lessons[$i] = $lessons[$i + 1];
          $lessons[$i + 1] = $temp;
        } else {
          //move up
          if ($i == 0) {
            //already first, do nothing
            echo json_encode(['error' => false]);
            die();
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

    echo json_encode(['error' => false]);
    die();
  }

  public function reorderModule() {
    global $wpdb;

    $courseId = $_POST["course_id"];
    $moduleId = $_POST["module_id"];
    $direction = $_POST["direction"];

    //get all modules of this course ordered by order, and update their order
    $sql = "SELECT posts.ID as module_id, pm2.meta_value as 'order'
      FROM wp_postmeta pm1
      INNER JOIN wp_posts posts on posts.ID = pm1.post_id
      INNER JOIN wp_postmeta pm2 on pm2.meta_key = 'order' and pm2.post_id = pm1.post_id
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
            echo json_encode(['error' => false]);
            die();
          }

          $temp = $modules[$i];
          $modules[$i] = $modules[$i + 1];
          $modules[$i + 1] = $temp;
        } else {
          //move up
          if ($i == 0) {
            //already first, do nothing
            echo json_encode(['error' => false]);
            die();
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

    echo json_encode(['error' => false]);
    die();
  }

  private function fixLessonsOrder($moduleId) {
    global $wpdb;

    //get all modules of this course ordered by order, and update their order
    $sql = "SELECT posts.ID as lesson_id, pm2.meta_value as 'lesson_number'
      FROM wp_postmeta pm1
      INNER JOIN wp_posts posts on posts.ID = pm1.post_id
      INNER JOIN wp_postmeta pm2 on pm2.meta_key = 'lesson_number' and pm2.post_id = pm1.post_id
      WHERE posts.post_status <> 'trash'
      AND pm1.meta_key = 'module' and pm1.post_id = posts.ID and pm1.meta_value = $moduleId
      ORDER BY CAST(pm2.meta_value as UNSIGNED)";

    $lessons = $wpdb->get_results($sql, ARRAY_A);

    //now lets renumber the order from 1 and up
    for ($i = 0; $i < count($lessons); $i++) {
      update_post_meta($lessons[$i]["lesson_id"], 'lesson_number', $i + 1);
    }
  }

  private function fixModulesOrder($courseId) {
    global $wpdb;

    //get all modules of this course ordered by order, and update their order
    $sql = "SELECT posts.ID as module_id, pm2.meta_value as 'order'
      FROM wp_postmeta pm1
      INNER JOIN wp_posts posts on posts.ID = pm1.post_id
      INNER JOIN wp_postmeta pm2 on pm2.meta_key = 'order' and pm2.post_id = pm1.post_id
      WHERE posts.post_status <> 'trash'
      AND pm1.meta_key = 'course' and pm1.post_id = posts.ID and pm1.meta_value = $courseId
      ORDER BY CAST(pm2.meta_value as UNSIGNED)";

    $modules = $wpdb->get_results($sql, ARRAY_A);

    //now lets renumber the order from 1 and up
    for ($i = 0; $i < count($modules); $i++) {
      update_post_meta($modules[$i]["module_id"], 'order', $i + 1);
    }
  }

  public function editModule() {
    global $wpdb;

    $courseId = $_POST["course_id"];
    $moduleId = isset($_POST["module_id"]) ? $_POST["module_id"] : false;
    $name = $_POST["name"];
    $count_progress = $_POST["count_progress"] ?? '1';
    $intro_module = $_POST["intro_module"] ?? '0';

    if (!$moduleId) {
      $wpdb->insert("wp_posts", [
        "post_title" => $name,
        "post_status" => "draft",
        "post_type" => "module",
        "post_parent" => $courseId
      ]);

      $moduleId = $wpdb->insert_id;
      $order = $wpdb->get_var("select ifnull(max(pm1.meta_value),0)
      from wp_postmeta pm1
      inner join wp_postmeta pm2 on pm2.meta_key = 'course' and pm2.meta_value = $courseId
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

    echo json_encode(['error' => false]);
    die();
  }

  public function changeLessonStatus() {
    global $wpdb;

    $lessonId = $_POST["lesson_id"];
    $moduleId = $_POST["module_id"];
    $status = $_POST["status"];

    $wpdb->update("wp_posts", ["post_status" => $status], ["ID" => $lessonId]);
    $this->fixLessonsOrder($moduleId);

    echo json_encode(['error' => false]);
    die();
  }

  public function changeModuleStatus() {
    global $wpdb;

    $courseId = $_POST["course_id"];
    $moduleId = $_POST["module_id"];
    $status = $_POST["status"];

    $wpdb->update("wp_posts", ["post_status" => $status], ["ID" => $moduleId]);

    $this->fixModulesOrder($courseId);

    echo json_encode(['error' => false]);
    die();
  }

  public function changeCourseStatus() {
    global $wpdb;

    $courseId = $_POST["course_id"];
    $status = $_POST["status"];

    $wpdb->update("wp_posts", ["post_status" => $status], ["ID" => $courseId]);

    echo json_encode(['error' => false]);
    die();
  }

  public static function course_has_tag($courseId, $tag): bool {
    $tags = PodsWrapper::get_field('course', $courseId, 'tags', true) ?? '';
    $tags = explode(",", $tags);
    $tags = array_map('trim', $tags);
    return in_array($tag, $tags);
  }

  public function editCourse() {
    try {
      $courseId = isset($_POST["course_id"]) ? $_POST["course_id"] : false;
      $name = $_POST["name"];
      $price = $_POST["price"];
      $page_url = $_POST["page_url"];
      $charge_url = $_POST["charge_url"];
      $tags = $_POST["tags"];

      if (empty($courseId)) {
        //create new course, a course is a wp_post with post_type = course
        $courseId = wp_insert_post([
          'post_title' => $name,
          'post_status' => 'draft',
          'post_type' => 'course'
        ]);
      } else {
        wp_update_post([
          'ID' => $courseId,
          'post_title' => $name
        ]);
      }
      update_post_meta($courseId, 'full_price', $price);
      update_post_meta($courseId, 'course_page_url', $page_url);
      update_post_meta($courseId, 'charge_url', $charge_url);
      update_post_meta($courseId, 'tags', $tags);

      echo json_encode(['error' => false]);
      die();
    } catch (Exception $e) {
      echo json_encode(['error' => true, 'message' => $e->getMessage()]);
      die();
    }
  }
}

$coursesManagement = Courses::get_instance();

?>