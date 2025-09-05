<?php
/**
 * Plugin Name: Future LMS
 * Plugin URI: https://valueinvesting.co.il/
 * Description: Custom plugin for value investing school
 * Version: 1.0.0
 * Author: Nimrod
 * Author URI: https://google.com/?q=who+is+the+dude
 * Tested up to: 6.8.1
 * Requires: 4.6 or higher
 * License: GPLv3 or later
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Requires PHP: 7.1
 * Text Domain: future-lms
 * Domain Path: /languages
 *
 * Copyright 2020- nimrod cohen
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 3, as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

namespace FutureLMS;

use Exception;
use WP_User;
use WP_User_Query;
use NumberFormatter;
use FutureLMS\classes\Student;
use FutureLMS\classes\VersionManager;
use FutureLMS\classes\Course;
use FutureLMS\classes\BaseObject;
use FutureLMS\classes\Lesson;
use FutureLMS\classes\SchoolClass;
use FutureLMS\classes\Settings;

class FutureLMS {
  public $coupons = null;

  function __construct() {
    VersionManager::install_version();

    add_action('init', [$this, 'init_hooks']);

    add_shortcode('flms_course_price', ['FutureLMS\classes\Course', 'get_course_price_box']);
    add_shortcode('flms_school_lobby', [$this, 'show_school_lobby']);
    add_filter('body_class', [$this,'add_school_class_to_body']);

    add_filter('manage_course_posts_columns', [$this, 'addCoursesColumns']);
    add_action('manage_course_posts_custom_column', [$this, 'fillCoursesColumns'], 10, 2);
    add_filter('manage_lesson_posts_columns', [$this, 'addLessonsColumns']);
    add_action('manage_lesson_posts_custom_column', [$this, 'fillLessonsColumns'], 10, 2);
    add_action('plugins_loaded', [$this,'future_lms_load_textdomain']);

    $this->load_woocommerce_integration();
  }

  function future_lms_load_textdomain() {
    load_plugin_textdomain('future-lms', false, dirname(plugin_basename(__FILE__)) . '/languages/');
  }

  public static function TABLE_PREFIX()
  {
    global $wpdb;
    return $wpdb->prefix.'flms_';
  }

  public static function version() {
    $plugin_data = get_file_data(__FILE__, ['Version' => 'Version'], 'plugin');
    return $plugin_data['Version'];
  }

  public function add_school_class_to_body($classes) {
    if (is_singular()) {
      global $post;
      if (has_shortcode($post->post_content, 'flms_school_lobby')) {
          $classes[] = 'school';
      }
    }
    return $classes;
  }

  public function show_school_lobby() {
    if(is_admin() || !is_user_logged_in() || !current_user_can('student')) {
      return;
    }
    //if request is POST and course_id is set, redirect to course page
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['course_id'], $_POST['class_id'], $_POST['lesson_id']) ) {
      require_once plugin_dir_path(__FILE__) . 'front/course.php';
    } else {
      require_once plugin_dir_path(__FILE__) . 'front/lobby.php';
    }
  }

  public function init_hooks() {
    add_action('admin_menu', [$this, 'add_admin_menu']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    add_action("wp_enqueue_scripts", [$this, 'enqueueSchoolScripts']);
    add_action("wp_ajax_search_students", [$this, "search_students"]);
    add_action("wp_ajax_get_all_courses", [$this, "get_all_Courses"]);
    add_action("wp_ajax_get_course_charge_url", [$this, "get_course_charge_url"]);
    add_action("wp_ajax_get_all_payments", [$this, "get_all_payments"]);
    add_action("wp_ajax_search_classes", [$this, "search_classes"]);
    add_action("wp_ajax_get_classes", [$this, "get_student_classes"]);
    add_action("wp_ajax_get_lessons", [$this, "get_class_lessons"]);
    add_action("wp_ajax_get_lesson_content", [$this, "get_lesson_content"]);
    add_action("wp_ajax_set_student_notes", [$this, "set_student_notes"]);
    add_action("wp_ajax_get_student_progress", [$this, "get_student_progress"]);
    add_action("wp_ajax_set_student_progress", [$this, "set_student_progress"]);
    add_action("wp_ajax_get_students", [$this, "get_students"]);
    add_action("wp_ajax_remove_class", [$this, "remove_student_from_class"]);
    add_action("wp_ajax_remove_payment", [$this, "remove_payment"]);
    add_action("wp_ajax_add_student_to_class", [$this, "add_student_to_class"]);
    add_action("wp_ajax_set_lesson", [$this, "setLesson"]);
    add_action("wp_ajax_send_email", [$this, "sendEmail"]);
    add_action("wp_ajax_future_lms_get_settings", [$this, "get_settings"]);
    add_action("wp_ajax_future_lms_set_settings", [$this, "set_settings"]);
    add_action('show_user_profile', [$this, 'extraUserFields']);
    add_action('edit_user_profile', [$this, 'extraUserFields']);
    add_action('manage_users_columns', [$this, 'addExtraUserFieldsToList']);
    add_filter('manage_users_custom_column', [$this, 'addExtraUserFieldsToListData'], 10, 3);
    add_action('manage_edit-module_columns', [$this, 'addExtraModuleFieldsToList']);
    add_filter('manage_module_posts_custom_column', [$this, 'addExtraModuleFieldsToListData'], 10, 2);
    add_action('manage_edit-lesson_columns', [$this, 'addExtraLessonFieldsToList']);
    add_filter('manage_lesson_posts_custom_column', [$this, 'addExtraLessonFieldsToListData'], 10, 2);
    //restrict access to lessons:
    add_action('template_redirect', [$this, 'restrict_school_access']);

    //add student role if not exists
     add_role('student', 'Student', [
        'read' => true,
        'edit_posts' => false,
        'delete_posts' => false,
    ]);
  }

  public function restrict_school_access() {
    if (is_single()) {
      global $post;
      // Check if the post type is lesson, module, class or course
      if (in_array($post->post_type,['lesson','module','class']) && !current_user_can('student')) {
        wp_redirect(wp_login_url());
      }
    }
  }

  public function addLessonsColumns($columns) {
    $columns["videos"] = "Videos";
    return $columns;
  }

  public function fillLessonsColumns($column, $post_id) {

    if (!in_array($column, ['videos'])) {
      return;
    }

    $lesson = BaseObject::factory("lesson", $post_id);
    $videoList = $lesson->field("video_list");
    $videoList = empty($videoList) ? "[]" : $videoList;
    $videoList = json_decode($videoList, true);

    $result = "";
    foreach ($videoList as $video) {
      if (!empty($video["video_id"])) {
        $result .= $video["video_id"] . ", ";
      }
    }

    $result = preg_replace("/, $/", "", $result);

    echo $result;
  }

  public function addCoursesColumns($columns) {
    $columns["full_price"] = "Full Price";
    $columns["discount_price"] = "Discount Price";

    return $columns;
  }

  public function fillCoursesColumns($column, $post_id) {
    if (!in_array($column, ['full_price','discount_price'])) {
      return;
    }

    $course = new Course($post_id);
    $fmt = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
    $price = $course->field($column)?? 0;
    $price = empty($price) ? 0 : floatval($price);
    $currency = Settings::get("store_currency");
    echo $fmt->formatCurrency($price, $currency);
  }

  public function get_settings() {
    $result = Settings::all();
    wp_send_json($result);
  }

  public function set_settings() {
    //loop through all POST variables and update options
    Settings::set_many($_POST);
    wp_send_json(["error" => false, "message" => "Settings saved successfully"]);
  }

  public static function log($msg) {
    if (is_array($msg) || is_object($msg)) {
      $msg = print_r($msg, true);
    }

    $date = date("Y-m-d");
    $datetime = date("Y-m-d H:i:s");
    file_put_contents(
      ABSPATH . DIRECTORY_SEPARATOR . "logs" . DIRECTORY_SEPARATOR . "debug-$date.log",
      "$datetime | $msg\r\n",
      FILE_APPEND);
  }

  public function addExtraUserFieldsToListData($value, $column_name, $user_id) {
    if ('phone' == $column_name) {
      $value = get_user_meta($user_id, 'user_phone', true);
    }
    return $value;
  }

  public function addExtraUserFieldsToList($columns) {
    $columns['phone'] = __('Phone');
    return $columns;
  }

  public function addExtraModuleFieldsToListData($column_name, $module_id) {
    $pod = BaseObject::factory("module", $module_id);
    switch ($column_name) {
      case "course":
        $courseId = $pod->raw("course");
        $course = BaseObject::factory("course", $courseId);
        echo "<a href='" . site_url("/wp-admin/post.php?post=" . $courseId . "&action=edit") . "'>" . $course->raw("name") . "</a>";
        break;
    }
  }

  public function addExtraModuleFieldsToList($columns) {
    $columns['course'] = __('Course');
    $columns['order'] = __('Module #');
    return $columns;
  }

  public function addExtraLessonFieldsToListData($column_name, $lesson_id) {
    global $wpdb;

    if ('module' == $column_name) {
      $module_id = $wpdb->get_var("select meta_value from ".$wpdb->prefix."postmeta where post_id = $lesson_id and meta_key = 'module'");
      $module_order = $wpdb->get_var("select meta_value from ".$wpdb->prefix."postmeta where post_id = $module_id and meta_key = 'order'");
      $module_name = $wpdb->get_var("select post_title from ".$wpdb->prefix."posts where id = $module_id");
      echo "<a href='" . site_url("/wp-admin/post.php?post=" . $module_id . "&action=edit")."'>".$module_name."(".$module_order.")</a>";
    } else if ('lesson_number' == $column_name) {
      $lesson_number = $wpdb->get_var("select meta_value from ".$wpdb->prefix."postmeta where post_id = $lesson_id and meta_key = 'lesson_number'");
      echo $lesson_number;
    }
  }

  public function addExtraLessonFieldsToList($columns) {
    $columns['module'] = __('Module');
    $columns['lesson_number'] = __('Lesson #');
    return $columns;
  }

  public function extraUserFields($user) {?>
    <h3><?php _e("Extra profile information", "blank"); ?></h3>
    <table class="form-table">
      <tr>
        <th><label for="phone"><?php _e("Phone"); ?></label></th>
        <td>
          <input type="text" name="phone" id="phone" value="<?php echo esc_attr(get_user_meta($user->ID, 'user_phone', true)); ?>" class="regular-text" /><br />
          <span class="description"><?php _e("Please enter user phone."); ?></span>
        </td>
      </tr>
    </table>
  <?php 
  }

  public function enqueueSchoolScripts() {
    wp_enqueue_script('future-lms_main_script', plugin_dir_url(__FILE__) . 'front/main.js?time=' . date('Y_m_d_H'), ['wpjsutils']);
    wp_enqueue_style('future-lms_style', plugin_dir_url(__FILE__) . 'front/school.css?time=' . date('Y_m_d_H'));
    wp_enqueue_script('future-lms_school_script', plugin_dir_url(__FILE__) . 'front/school.js?time=' . date('Y_m_d_H'), ['wpjsutils']);

    wp_localize_script('future-lms_school_script', 'school_info', [
      'ajax_url' => admin_url('admin-ajax.php'),
      'theme_url' => plugin_dir_url(__FILE__)
    ]);
  }

  public function enqueue_admin_assets($hook) {
    if ("toplevel_page_future-lms-settings" != $hook) {
      return;
    }
    wp_enqueue_script('future-lms-semantic-js', plugin_dir_url(__FILE__) . 'assets/semantic/semantic.min.js');
    wp_enqueue_style('future-lms-semantic-css', plugin_dir_url(__FILE__) . 'assets/semantic/semantic.min.css');

    wp_enqueue_script('future-lms-admin-common-js', plugin_dir_url(__FILE__) . 'admin/js/common.js?time=' . date('Y_m_d_H'));
    wp_enqueue_script('future-lms-admin-students-js', plugin_dir_url(__FILE__) . 'admin/js/students.js?time=' . date('Y_m_d_H'), ['wpjsutils', 'jquery']);
    wp_enqueue_script('future-lms-admin-courses-js', plugin_dir_url(__FILE__) . 'admin/js/courses.js?time=' . date('Y_m_d_H'), ['wpjsutils', 'jquery']);
    wp_enqueue_script('future-lms-admin-coupons-js', plugin_dir_url(__FILE__) . 'admin/js/coupons.js?time=' . date('Y_m_d_H'), ['wpjsutils', 'jquery', 'future-lms-admin-common-js']);
    wp_enqueue_script('future-lms-admin-settings-js', plugin_dir_url(__FILE__) . 'admin/js/settings.js?time=' . date('Y_m_d_H'), ['wpjsutils', 'jquery', 'future-lms-admin-common-js']);
    wp_enqueue_script('future-lms-admin-js', plugin_dir_url(__FILE__) . 'admin/js/admin.js?time=' . date('Y_m_d_H'), ['future-lms-admin-students-js', 'future-lms-admin-coupons-js']);
    wp_enqueue_style('future-lms-admin-css', plugin_dir_url(__FILE__) . 'admin/css/admin.css', ['future-lms-semantic-css']);

    wp_localize_script('future-lms-admin-js', '__futurelms', [
      'ajax_url' => admin_url('admin-ajax.php'),
      'store_currency' => Settings::get("store_currency"),
    ]);

    wp_enqueue_script('trumbowyg-js', plugin_dir_url(__FILE__) . 'assets/trumbowyg/trumbowyg.min.js');
    wp_enqueue_script('trumbowyg-base64-js', plugin_dir_url(__FILE__) . 'assets/trumbowyg/plugins/base64/trumbowyg.base64.min.js', ['trumbowyg-js']);
    wp_enqueue_script('trumbowyg-HEB-js', plugin_dir_url(__FILE__) . 'assets/trumbowyg/langs/he.min.js', ['trumbowyg-js']);
    wp_enqueue_style('trumbowyg-css', plugin_dir_url(__FILE__) . 'assets/trumbowyg/ui/trumbowyg.min.css');
  }

  public function add_admin_menu() {
    add_menu_page('Future LMS', 'Future LMS', 'manage_options', 'future-lms-settings', [$this, 'show_admin_page'], 'dashicons-schedule', 30);
  }

  public function show_admin_page() {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'admin/admin.php';
  }

  //called when sum is 0, or payment is not by credit card.
  //TODO: remove user creation from here, or ask if create user
  public function add_student_to_class() {
    $courseId = $_REQUEST["course_id"];
    $studentId = $_REQUEST["student_id"];
    $classId = $_REQUEST["class_id"];
    $name = $_REQUEST["name"];
    $phone = $_REQUEST["phone"];
    $email = $_REQUEST["email"];
    $sum = $_REQUEST["sum"];
    $comment = $_REQUEST["comment"];
    $paymentMethod = $_REQUEST["method"];
    $transactionId = $_REQUEST["transactionId"];

    $studentObj = Student::create($email, '', $email);
    if (!$studentObj) {
      wp_send_json(['error' => true, 'message' => 'Failed to create or load student']);
    }
    $studentId = $studentObj->get_id();

    //filter email and phone as some systems may want it in a specific format
    $email = apply_filters('future-lms/student_email', $email);
    $phone = apply_filters('future-lms/student_phone', $phone);

    if (!empty($name)) {
      $arr = ["ID" => $studentId];
      $arr["display_name"] = $name;
      wp_update_user($arr);
    }
    if (!empty($phone)) {
      update_user_meta($studentId, 'user_phone', $phone);
    }

    $student = new Student($studentId);

    $old_class = $student->get_class($courseId);

    if (!$old_class) { //currently not listed at all
      $student->subscribe_to_class($classId, true);
    } else if ($old_class["id"] != $classId) { //already registered to another class
      $student->subscribe_to_class($old_class["id"], false);
      $student->subscribe_to_class($classId, true);
    }
    //save payment
    $paymentId = $student->save_payment($courseId, $classId, $sum, $transactionId, $paymentMethod, $comment);

    //do actions after payment future-lms/payment_notification
    do_action('future-lms/payment_notification', [
      "course_id" => $courseId,
      "student_id" => $studentId,
      "class_id" => $classId,
      "sum" => $sum,
      "transaction_id" => $transactionId,
      "payment_id" => $paymentId,
      "payment_method" => $paymentMethod,
      "comment" => $comment
    ]);

    wp_send_json([]);
  }

  public function remove_payment() {
    $paymentId = $_REQUEST["payment_id"];

    $payment = Student::get_payment($paymentId);

    Student::delete_payment($paymentId);

    do_action('future-lms/payment_removed', [
      "course_id" => $payment["course_id"],
      "student_id" => $payment["student_id"],
      "class_id" => $payment["class_id"],
      "sum" => $payment["sum"],
      "transaction_id" => $payment["transaction_ref"],
      "payment_method" => $payment["method"],
      "comment" => $payment["comment"]
    ]);

    wp_send_json([]);
  }

  public function remove_student_from_class() {
    $courseId = $_REQUEST["course_id"];
    $studentId = $_REQUEST["student_id"];
    $classId = $_REQUEST["class_id"];

    $student = new Student($studentId);

    $class = $student->get_class($courseId);

    if (!$class) { //currently not listed at all
      echo json_encode([]);
      die();
    }
    $student->subscribe_to_class($classId, false);

    wp_send_json([]);
  }

  public function get_student_classes() {
    $courseId = $_REQUEST["course_id"];
    $studentId = $_REQUEST["student_id"];

    $student = new Student($studentId);

    $classes = BaseObject::factory("class", ["where" => "course.id = " . $courseId], -1);

    foreach($classes->results() as $row) {
      $result[] = [
        'id' => $row->ID,
        'title' => $row->post_title,
        'start_date' => strtotime($row->field("start_date")),
        'attending' => $student->is_attending_class($courseId, $row->ID)
      ];
    }

    //sort by start date
    usort($result, function ($a, $b) {
        return $a["start_date"] < $b["start_date"];
    });

    wp_send_json($result);
  }

  public function get_students() {
    try {
      $courseId = intval($_REQUEST["course_id"]);
      $classId = intval($_REQUEST["class_id"]);
      $search = trim($_REQUEST["search"]);
      $month = intval($_REQUEST["month"]);
      $year = intval($_REQUEST["year"]);

      $result = SchoolClass::students($courseId, $classId, $search, $month, $year);

      wp_send_json($result);
    } catch (Exception $ex) {
        wp_send_json(["error" => $ex->getMessage()]);
    }
  }

  public function set_student_progress() {
    $courseId = $_POST["course_id"];
    $moduleId = $_POST["module_id"];
    $lessonId = $_POST["lesson_id"];
    $videoId = $_POST["video_id"];
    $percent = intval($_POST["percent"]);
    $seconds = intval($_POST["seconds"]);

    $student = new Student(get_current_user_id());
    $data = $student->get_progress();

    if (!isset($data["courses"])) {
      $data["courses"] = [];
    }

    $pos = array_search($courseId, array_column($data["courses"], 'course_id'));
    if ($pos === false) { //should not happen
      $course = ["course_id" => $courseId, "modules" => []];
      $data["courses"][] = &$course;
    } else {
      $course = &$data["courses"][$pos];
    }

    //find module
    $pos = array_search($moduleId, array_column($course["modules"], 'module_id'));
    if ($pos === false) {
      $module = ["module_id" => $moduleId, "lessons" => []];
      $course["modules"][] = &$module;
    } else {
      $module = &$course["modules"][$pos];
    }

    //find lesson
    $pos = array_search($lessonId, array_column($module["lessons"], 'lesson_id'));
    if ($pos === false) {
      $lesson = ["lesson_id" => $lessonId, "videos" => []];
      $module["lessons"][] = &$lesson;
    } else {
      $lesson = &$module["lessons"][$pos];
    }

    //find video
    $pos = array_search($videoId, array_column($lesson["videos"], 'video_id'));
    if ($pos === false) {
      $video = ["video_id" => $videoId, "percent" => 0, "seconds" => 0];
      $lesson["videos"][] = &$video;
    } else {
      $video = &$lesson["videos"][$pos];
    }

    if ($video["percent"] < $percent) {
      $video["seconds"] = $seconds;
      $video["percent"] = $percent;

      $student->set_progress($data);
    }
  }

  public function get_student_progress() {
    try {
      $result = [];
      $studentId = get_current_user_id();

      if (current_user_can('manage_options') && isset($_POST["student_id"])) {
        $studentId = $_POST["student_id"];
      }

      $student = new Student($studentId);
      $classes = $student->attendence_info();
      $courses = array_map(function ($class) {
          return $class["course_id"];
      }, $classes);
      $result["progress"] = $student->get_progress();
      $result["course_tree"] = Course::get_courses_tree($courses);

      wp_send_json($result);
    } catch (Exception $ex) {
      wp_send_json(json_encode(["error" => $ex->getMessage()]));
    }
}

  public function set_student_notes() {
    try {
      $lessonId = intval($_POST["lesson_id"]);
      $notes = $_POST["notes"];

      $student = new Student(get_current_user_id());

      $student->set_lesson_notes($lessonId, $notes);

      wp_send_json([]);
    } catch (Exception $ex) {
      wp_send_json(json_encode(["error" => $ex->getMessage()]));
    }
  }

  public function get_lesson_content() {
    try {
      $result = [];

      $courseId = intval($_POST["course_id"]);
      $lessonId = intval($_POST["lesson_id"]);

      //check if course exists
      $course = BaseObject::factory("course", $courseId);
      $student = new Student(get_current_user_id());

      //course id not found or student not listed
      if (!$course->exists()
          || !$student->is_attending_course($courseId)
          || !$student->is_lesson_open($courseId, $lessonId)
      ) {
          throw new Exception("Failed to load class");
          return;
      }

      $lesson = new Lesson($lessonId);

      $result["presentation"] = $lesson->display('presentation');
      $result["homework"] = $lesson->display('homework');
      $result["additionalFiles"] = $lesson->display('additional_files');
      $result["lessonContent"] = $lesson->display('post_content');
      $result["studentNotes"] = $student->get_lesson_notes($lessonId);

      $videos = $lesson->raw('video_list');
      $videos = empty($videos) ? [] : json_decode($videos, true);

      if (!empty($videos)) {
        $result["videos"] = $videos;
      }

      wp_send_json($result);
    } catch (Exception $ex) {
      wp_send_json(["error" => $ex->getMessage()]);
    }
  }

  public static function notify_admins($title, $message) {
    if (wp_get_environment_type() !== "production") {
      return;
    }

    do_action('future-lms/admin_notification', [
      "title" => $title,
      "message" => $message
    ]);
  }

  public function get_class_lessons() {
    try {
      $class_id = $_REQUEST["class_id"];

      $class = new SchoolClass($class_id);
      if (!$class) {
          throw new Exception('cannot find class by id');
      }

      $is_live = $class->raw("is_live_class") == "1";

      if(!$is_live) {
        $class_lessons = [];
      } else {
        $class_lessons = $class->raw("lessons") ?? '[]';
        $class_lessons = json_decode($class_lessons, true);
        $class_lessons = is_array($class_lessons) ? $class_lessons : [];
      }

      $course_id = $class->raw("course");

      $modules = BaseObject::factory("module", ["where" => "course.id = " . $course_id, "orderby" => "cast(order.meta_value  as unsigned int) ASC", "limit" => -1]);

      while ($module = $modules->fetch()) {
        $module_id = $module->raw('ID');

        $lessons = BaseObject::factory("lesson", ["where" => "module.id = " . $module_id, "orderby" => "cast(lesson_number.meta_value as unsigned int) ASC", "limit" => -1]);

        while ($lesson = $lessons->fetch()) {
          $open = !$is_live; //if live class, all lessons are closed by default
          $lesson_id = $lesson->raw("ID");
          if($is_live) {
            $pos = array_search($lesson_id, array_column($class_lessons, 'id'));
            if (false !== $pos) {
              $open = $class_lessons[$pos]["open"] ?? false;
            }
          }

          $result[] = [
            "module_id" => $module_id,
            "module_title" => $module->raw("name", true),
            "module_order" => $module->raw("order", true),
            "intro_module" => $module->raw("intro_module", true),
            "lesson_number" => $lesson->raw("lesson_number", true),
            "id" => $lesson_id,
            "title" => stripslashes($lesson->raw("title", true)),
            "open" => $open
          ];
        }
      }

      //order by module order and lesson number
      usort($result, function ($a, $b) {
        if ($a["module_order"] == $b["module_order"]) {
          return $a["lesson_number"] <=> $b["lesson_number"];
        }
        return $a["module_order"] <=> $b["module_order"];
      });
      wp_send_json($result);
    } catch (Exception $ex) {
        wp_send_json(["error" => $ex->getMessage()]);
    }
  }

  public function sendEmail() {
    $courseId = $_REQUEST["course_id"];
    $classId = $_REQUEST["class_id"];
    $subject = $_REQUEST["subject"];
    $content = $_REQUEST["content"];
    $test = $_REQUEST["test"] == "1";

    $students = [];
    if (!$test) {
      $students = SchoolClass::students($courseId, $classId, null);
    } else {
      $admins = get_users('role=administrator');
      foreach ($admins as $user) {
        $students[] = ["user_email" => $user->user_email];
      }
    }

    $headers = [];
    $headers[] = 'From: Yinon Arieli <yinon@bursa4u.com>';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $content = stripslashes($content);

    $addressees = array_reduce($students, function ($result, $user) {
      $result[] = "Bcc: " . $user["user_email"];
      return $result;
    }, []);

    $addresseeChunks = array_chunk($addressees, 100);

    foreach ($addresseeChunks as $addresseeChunk) {
      $heads = array_merge($headers, $addresseeChunk);

      if (wp_get_environment_type() !== 'production') {
          continue;
      }

      wp_mail(['yinon@bursa4u.com'], $subject, $content, $heads);
    }

    wp_send_json([]);
  }

  public function setLesson() {
    $classId = $_REQUEST["class_id"];
    $lessonId = $_REQUEST["lesson_id"];

    $class = BaseObject::factory("class", $classId);

    if (!$class) {
      wp_send_json(["error" => "Class not found"]);
    }

    $lessons = $class->raw("lessons");

    if ($lessons) {
        $lessons = json_decode($lessons, true);
    } else {
        $lessons = [];
    }

    $found = false;
    foreach ($lessons as &$lesson) {
        if ($lesson["id"] == $lessonId) {
            $lesson["open"] = !$lesson["open"];
            $found = true;
            break;
        }
    }
    if (!$found) {
        $lessons[] = [
            "id" => $lessonId,
            "open" => true
        ];
    }

    $lessons = json_encode($lessons);
    $class->save('lessons', $lessons);
    wp_send_json([]);
  }

  public function get_all_payments() {
    $month = intval($_REQUEST["month"]);
    $year = intval($_REQUEST["year"]);
    $results = Student::get_payments($year, $month);
    wp_send_json($results);
  }

  public function get_course_charge_url() {
    $course = BaseObject::factory('Course', intval($_REQUEST["course_id"]));
    $chargeUrl = $course->field('charge_url');
    $fullPrice = $course->field('full_price');
    $chargeUrl = add_query_arg(['sum' => $fullPrice], $chargeUrl); //will replace if exists.
    $chargeUrl = add_query_arg(['pdesc' => urlencode($course->field('title'))], $chargeUrl);
    wp_send_json(['charge_url' => $chargeUrl]);
  }

  public function get_all_Courses() {
    wp_send_json(["courses" => Course::get_courses_tree(null, false)]);
  }

  public function search_classes() {
    try {
      $search = isset($_REQUEST['search']) ? $_REQUEST['search'] : false;
      $courseId = $_REQUEST['course_id'];

      $result = Course::get_classes($courseId, $search);

      $result = array_map(function ($item) {
        return [
          'id' => $item["id"],
          'title' => $item["title"],
          'value' => $item["id"],
          'name' => $item["title"]
        ];
      }, $result);

      wp_send_json(['success' => true, "results" => $result]);
    } catch (Exception $ex) {
      wp_send_json(['success' => false, "results" => []]);
    }
  }

  public function search_students() {
    $search = $_REQUEST['search'];

    if (empty($search)) {
      wp_send_json(["success" => true, "results" => []]);
    }

    $existing = [];
    if (isset($_REQUEST["class_id"])) {
      $existing = SchoolClass::students(null, $_REQUEST["class_id"]);
    }

    $query = new WP_User_Query([
      'role' => 'student',
      'search' => '*' . esc_attr($search) . '*',
      'search_columns' => [
        'user_login',
        'user_nicename',
        'user_email',
        'user_url'
      ]]);

    $users = $query->get_results();
    $users = array_reduce($users, function ($result, $item) {
      $result[] = [
        'id' => $item->ID,
        'title' => $item->data->display_name
      ];
      return $result;
    }, []);

    $result = [];
    foreach ($users as $user) {
      if (array_search($user["id"], array_column($existing, 'id')) === FALSE) {
          $result[] = $user;
      }
    }

    wp_send_json(["success" => true, "results" => $result]);
  }

  public static function get_template_part($template_name, $args = []) {
    $template_name = ltrim($template_name, '/');
    // Check for override in the theme
    $theme_path = locate_template("future-lms/{$template_name}");

    $relative_path = "front/webparts/{$template_name}";

    if (!$theme_path) {
      $plugin_path = plugin_dir_path(__FILE__) . $relative_path;
      if (!file_exists($plugin_path)) {
        trigger_error("Template not found: $relative_path", E_USER_WARNING);
        return;
      }
      $template = $plugin_path;
    } else {
      $template = $theme_path;
    }

    // Extract arguments to be used as variables in the template
    if (!empty($args) && is_array($args)) {
      extract($args, EXTR_SKIP);
    }

    include $template;
  }

	private function load_woocommerce_integration() {
		$integration_file = plugin_dir_path( __FILE__ ) . 'woocommerce/Integration.php';
		if ( file_exists( $integration_file ) ) {
			include_once $integration_file;
		}
	}
}

$directory = __DIR__ . '/classes';
$files = glob($directory . '/*.php');
foreach ($files as $file) {
    include_once $file;
}

$directory = __DIR__ . '/post-types';
$files = glob($directory . '/*.php');
foreach ($files as $file) {
    include_once $file;
}


$futureLms = new FutureLMS();