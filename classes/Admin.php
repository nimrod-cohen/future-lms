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
    add_action("wp_ajax_change_lesson_status", [$this, "chane_lesson_status"]);
    add_action("wp_ajax_edit_module", [$this, "edit_module"]);
    add_action("wp_ajax_reorder_module", [$this, "reorder_module"]);
    add_action("wp_ajax_reorder_lesson", [$this, "reorder_lesson"]);
    add_action("wp_ajax_add_lesson", [$this, "add_lesson"]);
    add_action("wp_ajax_edit_class", [$this, "edit_class"]);
  }

  public function edit_class() {
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

  public function add_lesson() {
    global $wpdb;

    $moduleId = intval($_POST["module_id"]);
    $name = stripslashes($_POST["name"]);

    $wpdb->insert($wpdb->prefix."posts", [
      "post_title" => $name,
      "post_status" => "draft",
      "post_type" => "lesson",
      "post_parent" => $moduleId
    ]);

    $lessonId = $wpdb->insert_id;
    $order = $wpdb->get_var("select ifnull(max(cast(pm1.meta_value as unsigned int)),0)
    from ".$wpdb->prefix."postmeta pm1
    inner join ".$wpdb->prefix."postmeta pm2 on pm2.meta_key = 'module' and pm2.meta_value = $moduleId
    where pm1.meta_key = 'lesson_number'
    and pm2.post_id = pm1.post_id");
    update_post_meta($lessonId, 'lesson_number', intval($order) + 1);
    update_post_meta($lessonId, 'video_list', '');
    update_post_meta($lessonId, 'additional_files', '');
    update_post_meta($lessonId, 'homework', '');
    $lesson = new Lesson($lessonId);
    $lesson->field('module', $moduleId);
    $lesson->save();

    wp_send_json(['error' => false]);
  }

  public function reorder_lesson() {
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
    global $wpdb;

    $courseId = $_POST["course_id"];
    $status = $_POST["status"];

    $wpdb->update($wpdb->prefix."posts", ["post_status" => $status], ["ID" => $courseId]);

    wp_send_json(['error' => false]);
  }

  public function edit_course() {
    try {
      $courseId = isset($_POST["course_id"]) ? $_POST["course_id"] : false;
      $name = $_POST["name"];
      $price = $_POST["price"];
      $page_url = $_POST["page_url"];
      $charge_url = $_POST["charge_url"];
      $tags = $_POST["tags"];
      $default_class = $_POST["default_class"];

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
      update_post_meta($courseId, 'default_class', $default_class);

      wp_send_json(['error' => false]);
    } catch (Exception $e) {
      wp_send_json(['error' => true, 'message' => $e->getMessage()]);
    }
  }
}

$admin = Admin::get_instance();

?>