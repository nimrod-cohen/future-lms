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
use WP_Query;
use WP_User_Query;
use NumberFormatter;
use FutureLMS\classes\DBManager;
use FutureLMS\classes\VersionManager;
use FutureLMS\classes\Courses;
use FutureLMS\classes\PodsWrapper;

class FutureLMS {
    public $coupons = null;

    function __construct() {
        VersionManager::installVersion();

       // $this->coupons = Coupons::get_instance();

        add_action('init', [$this, 'initHooks']);

        add_shortcode('flms_course_price', ['FutureLMS\classes\Courses', 'get_course_price_box']);
        add_shortcode('flms_school_lobby', [$this, 'showSchoolLobby']);
        add_filter('body_class', [$this,'add_school_class_to_body']);

        add_filter('manage_course_posts_columns', [$this, 'addCoursesColumns']);
        add_action('manage_course_posts_custom_column', [$this, 'fillCoursesColumns'], 10, 2);
        add_filter('manage_lesson_posts_columns', [$this, 'addLessonsColumns']);
        add_action('manage_lesson_posts_custom_column', [$this, 'fillLessonsColumns'], 10, 2);
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

    public function showSchoolLobby() {
      if(is_admin() || !is_user_logged_in() || !current_user_can('student')) {
        return;
      }
      //if request is POST and course_id is set, redirect to course page
      if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['course_id'])) {
        require_once plugin_dir_path(__FILE__) . 'front/course.php';
      } else {
        require_once plugin_dir_path(__FILE__) . 'front/lobby.php';
      }

    }

    public function initHooks() {
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminScript']);
        add_action("wp_enqueue_scripts", [$this, 'enqueueSchoolScripts']);
        add_action("wp_ajax_search_students", [$this, "searchStudents"]);
        add_action("wp_ajax_get_all_courses", [$this, "getAllCourses"]);
        add_action("wp_ajax_get_course_charge_url", [$this, "getCourseChargeUrl"]);
        add_action("wp_ajax_get_all_payments", [$this, "getAllPayments"]);
        add_action("wp_ajax_search_classes", [$this, "searchClasses"]);
        add_action("wp_ajax_get_classes", [$this, "getClasses"]);
        add_action("wp_ajax_get_lessons", [$this, "getLessons"]);
        add_action("wp_ajax_get_lesson_content", [$this, "getLessonContent"]);
        add_action("wp_ajax_set_student_notes", [$this, "setStudentNotes"]);
        add_action("wp_ajax_get_student_progress", [$this, "getStudentProgress"]);
        add_action("wp_ajax_set_student_progress", [$this, "setStudentProgress"]);
        add_action("wp_ajax_get_students", [$this, "getStudents"]);
        add_action("wp_ajax_remove_class", [$this, "removeStudentFromClass"]);
        add_action("wp_ajax_remove_payment", [$this, "removePayment"]);
        add_action("wp_ajax_add_class", [$this, "addStudentToClass"]);
        add_action("wp_ajax_set_lesson", [$this, "setLesson"]);
        add_action("wp_ajax_send_email", [$this, "sendEmail"]);
        add_action("wp_ajax_value_get_settings", [$this, "getSettings"]);
        add_action("wp_ajax_value_set_settings", [$this, "setSettings"]);
        add_action("restrict_manage_posts", [$this, "showTagsFilter"]);
        add_action('show_user_profile', [$this, 'extraUserFields']);
        add_action('edit_user_profile', [$this, 'extraUserFields']);
        add_action('personal_options_update', [$this, 'saveExtraUserFields']);
        add_action('edit_user_profile_update', [$this, 'saveExtraUserFields']);
        add_action('manage_users_columns', [$this, 'addExtraUserFieldsToList']);
        add_filter('manage_users_custom_column', [$this, 'addExtraUserFieldsToListData'], 10, 3);
        add_action('manage_edit-module_columns', [$this, 'addExtraModuleFieldsToList']);
        add_filter('manage_module_posts_custom_column', [$this, 'addExtraModuleFieldsToListData'], 10, 2);
        add_action('manage_edit-lesson_columns', [$this, 'addExtraLessonFieldsToList']);
        add_filter('manage_lesson_posts_custom_column', [$this, 'addExtraLessonFieldsToListData'], 10, 2);
        //restrict access to lessons:
        add_action('template_redirect', [$this, 'restrictSchoolAccess']);

    }

    public function restrictSchoolAccess() {
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

        $lesson = PodsWrapper::factory("lesson", $post_id);
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

        $course = PodsWrapper::factory("course", $post_id);
        $fmt = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
        $price = $course->field($column);
        echo $fmt->formatCurrency($price, "ILS");
    }

    public function getSettings() {
        $zoomAppClientId = get_option('zoom_app_client_id', '');
        $zoomAppClientSecret = get_option('zoom_app_client_secret', '');
        $zoomAccountId = get_option('zoom_account_id', '');
        $shorturlsToken = get_option('shorturls_token', '');
        $shorturlsUsername = get_option('shorturls_username', '');
        $whatsapp019Token = get_option('whatsapp_019_token', '');
        $whatsapp019Username = get_option('whatsapp_019_username', '');
        $whatsapp_019_phone = get_option('whatsapp_019_phone', '');

        echo json_encode([
            'zoom_app_client_id' => $zoomAppClientId,
            'zoom_app_client_secret' => $zoomAppClientSecret,
            'zoom_account_id' => $zoomAccountId,
            'shorturls_token' => $shorturlsToken,
            'shorturls_username' => $shorturlsUsername,
            'whatsapp_019_token' => $whatsapp019Token,
            'whatsapp_019_username' => $whatsapp019Username,
            'whatsapp_019_phone' => $whatsapp_019_phone
        ]);

        die;
    }

    public function setSettings() {
        $zoomAppClientId = $_POST["zoom_app_client_id"];
        $zoomAppClientSecret = $_POST["zoom_app_client_secret"];
        $zoomAccountId = $_POST["zoom_account_id"];
        $shorturlsToken = $_POST["shorturls_token"];
        $shorturlsUsername = $_POST["shorturls_username"];
        $whatsapp019Token = $_POST["whatsapp_019_token"];
        $whatsapp019Username = $_POST["whatsapp_019_username"];
        $whatsapp019Phone = $_POST["whatsapp_019_phone"];

        update_option('zoom_app_client_id', $zoomAppClientId, true);
        update_option('zoom_app_client_secret', $zoomAppClientSecret, true);
        update_option('zoom_account_id', $zoomAccountId, true);
        update_option('shorturls_token', $shorturlsToken, true);
        update_option('shorturls_username', $shorturlsUsername, true);
        update_option('whatsapp_019_token', $whatsapp019Token, true);
        update_option('whatsapp_019_username', $whatsapp019Username, true);
        update_option('whatsapp_019_phone', $whatsapp019Phone, true);

        echo json_encode(["error" => false, "message" => "Settings saved successfully"]);
        die;
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
        if ('course' == $column_name) {
            $pod = PodsWrapper::factory("module", $module_id);
            $course = $pod->raw("course");
            echo "<a href='" . site_url("/wp-admin/post.php?post=" . $course["ID"] . "&action=edit") . "'>" . $course["post_title"] . "</a>";
        } else if ("order" == $column_name) {
            $pod = PodsWrapper::factory("module", $module_id);
            echo $pod->raw("order");
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
    <?php }

    private function parseUserData() {
        if (!isset($_REQUEST["user_id"])) {
            throw new Exception("Field user_id is not set in transaction details");
        }

        $userIdField = $_REQUEST["user_id"];

        // Decode Base64 to JSON string
        $userIdField = urldecode(base64_decode($userIdField));

        FutureLMS::log('payment user details are:');
        FutureLMS::log($userIdField);

        //try strip slashes first and decode json
        $userData = json_decode(stripslashes($userIdField), true);

        if (isset($userData) && (isset($userData["id"]) || isset($userData["email"]))) {
            $userData["email"] = urldecode(strtolower($userData["email"]));
            return $userData;
        }

        //try without stripping slashes
        $userData = json_decode($userIdField, true);
        if (isset($userData) && (isset($userData["id"]) || isset($userData["email"]))) {
            $userData["email"] = urldecode(strtolower($userData["email"]));
            return $userData;
        }

        //try as split string
        $userFields = explode(",", $userIdField);
        $userData = [];
        foreach ($userFields as $field) {
            $fieldParts = explode(":", $field);
            if (count($fieldParts) == 2) {
                $userData[$fieldParts[0]] = $fieldParts[1];
            }
        }

        if (isset($userData) && (isset($userData["id"]) || isset($userData["email"]))) {
            $userData["email"] = urldecode(strtolower($userData["email"]));
            return $userData;
        }

        throw new Exception("Mising user data for this transaction");
    }

    public function showTagsFilter() {
        if (!is_admin()) {
            return;
        }

        global $wpdb, $table_prefix;

        $post_type = (isset($_GET['post_type'])) ? $_GET['post_type'] : 'post';

        //only add filter to post type you want
        if (!in_array($post_type, ['lesson', 'module'])) {
            return;
        }

        //query database to get a list of years for the specific post type:
        $values = array();
        $tags = $wpdb->get_results("SELECT distinct(t.name) AS tag, t.slug
            FROM " . $table_prefix . "terms t
            INNER JOIN " . $table_prefix . "term_taxonomy tt on tt.term_id=t.term_id
            WHERE tt.taxonomy = 'post_tag'
            order by t.name");
        foreach ($tags as &$tagRow) {
            $values[$tagRow->tag] = $tagRow->slug;
        }

        //give a unique name in the select field
        ?><select name="tag">
        <option value="">All courses</option>
        <?php
        $current_v = isset($_GET['tag']) ? $_GET['tag'] : '';
        foreach ($values as $label => $value) {
            printf(
                '<option value="%s"%s>%s</option>',
                $value,
                $value == $current_v ? ' selected="selected"' : '',
                $label
            );
        }
        ?>
        </select>
        <?php
    }

    public function enqueueSchoolScripts() {
        wp_enqueue_script('futurelms_main_script', plugin_dir_url(__FILE__) . 'front/main.js?time=' . date('Y_m_d_H'), ['wpjsutils']);
        wp_enqueue_style('futurelms_style', plugin_dir_url(__FILE__) . 'front/school.css?time=' . date('Y_m_d_H'));
        wp_enqueue_script('futurelms_school_script', plugin_dir_url(__FILE__) . 'front/school.js?time=' . date('Y_m_d_H'), ['bootstrap', 'wpjsutils']);

        wp_localize_script('futurelms_school_script', 'school_info', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'theme_url' => plugin_dir_url(__FILE__)
        ]);
    }

    public function enqueueAdminScript($hook) {

        if (in_array($hook, ['post.php', 'post-new.php']) &&
            get_current_screen()->post_type == 'webinar') {

            wp_enqueue_script('future-lms-admin-webinars-js', plugin_dir_url(__FILE__) . 'admin/js/webinars.js?time=' . date('Y_m_d_H'), ['wpjsutils']);
            wp_enqueue_style('future-lms-webinars-css', plugin_dir_url(__FILE__) . 'admin/css/webinars.css');
            return;
        }

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

        wp_localize_script('future-lms-admin-js', '__futurelms', ['ajax_url' => admin_url('admin-ajax.php')]);

        wp_enqueue_script('trumbowyg-js', plugin_dir_url(__FILE__) . 'assets/trumbowyg/trumbowyg.min.js');
        wp_enqueue_script('trumbowyg-base64-js', plugin_dir_url(__FILE__) . 'assets/trumbowyg/plugins/base64/trumbowyg.base64.min.js', ['trumbowyg-js']);
        wp_enqueue_script('trumbowyg-HEB-js', plugin_dir_url(__FILE__) . 'assets/trumbowyg/langs/he.min.js', ['trumbowyg-js']);
        wp_enqueue_style('trumbowyg-css', plugin_dir_url(__FILE__) . 'assets/trumbowyg/ui/trumbowyg.min.css');

    }

    public function addAdminMenu() {
        add_menu_page('Future LMS', 'Future LMS', 'manage_options', 'future-lms-settings', [$this, 'showAdminPage'], 'dashicons-schedule', 30);
    }

    public function showAdminPage() {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'admin/admin.php';
    }

    //called when sum is 0, or payment is not by credit card.
    public function addStudentToClass() {
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

        $student = null;
        if (isset($studentId)) { //try by id first
            $student = get_user_by('id', $studentId);
        }
        if (!$student) { //make sure user not already exist by mail
            $student = get_user_by('email', $email);
        }
        if (!$student) { //new email
            $password = wp_generate_password(12, true);
            $studentId = wp_create_user($email, $password, $email);
            $student = new WP_User($studentId);
            $student->remove_role('subscriber');
            $student->add_role('student');
            //TODO:: move to customerio
            wp_new_user_notification($studentId, null, 'both');
        } else {
            $studentId = $student->ID;
        }

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

        $query = new DBManager($studentId);

        $class = $query->getClass($courseId);

        if (!$class) { //currently not listed at all
            $query->subscribeToClass($classId, true);
        } else if ($class["id"] != $classId) { //already registered to another class
            $query->subscribeToClass($class["id"], false);
            $query->subscribeToClass($classId, true);
        }

        //add payment record
        $query = new DBManager(intval($studentId));

        $paymentId = $query->savePayment($courseId, $classId, $sum, $transactionId, $paymentMethod, $comment);

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

        echo json_encode([]);
        die();
    }

    public function removePayment() {
        $paymentId = $_REQUEST["payment_id"];

        $payment = DBManager::getPayment($paymentId);

        DBManager::deletePayment($paymentId);

        do_action('future-lms/payment_removed', [
            "course_id" => $payment["course_id"],
            "student_id" => $payment["student_id"],
            "class_id" => $payment["class_id"],
            "sum" => $payment["sum"],
            "transaction_id" => $payment["transaction_ref"],
            "payment_method" => $payment["method"],
            "comment" => $payment["comment"]
        ]);

        echo json_encode([]);
        die();
    }

    public function removeStudentFromClass() {
        $courseId = $_REQUEST["course_id"];
        $studentId = $_REQUEST["student_id"];
        $classId = $_REQUEST["class_id"];

        $query = new DBManager($studentId);

        $class = $query->getClass($courseId);

        if (!$class) { //currently not listed at all
            echo json_encode([]);
            die();
        }
        $query->subscribeToClass($classId, false);

        echo json_encode([]);
        die();
    }

    public function getClasses() {
        $courseId = $_REQUEST["course_id"];
        $studentId = $_REQUEST["student_id"];

        $query = new DBManager($studentId);

        $classes = PodsWrapper::factory("class", ["where" => "course.id = " . $courseId], -1);

        foreach($classes->results() as $row) {
            $result[] = [
                'id' => $row->ID,
                'title' => $row->post_title,
                'start_date' => strtotime($row->field("start_date")),
                'attending' => $query->isAttendingClass($courseId, $row->ID)
            ];
        }

        //sort by start date
        usort($result, function ($a, $b) {
            return $a["start_date"] < $b["start_date"];
        });

        echo json_encode($result);
        die();
    }

    public function getStudents() {
        try {
            $courseId = intval($_REQUEST["course_id"]);
            $classId = intval($_REQUEST["class_id"]);
            $search = trim($_REQUEST["search"]);
            $month = intval($_REQUEST["month"]);
            $year = intval($_REQUEST["year"]);

            $result = DBManager::getClassStudents($courseId, $classId, $search, $month, $year);

            echo json_encode($result);
            die();

        } catch (Exception $ex) {
            echo json_encode([]);
            die();
        }
    }

    public function setStudentProgress() {
        $courseId = $_POST["course_id"];
        $moduleId = $_POST["module_id"];
        $lessonId = $_POST["lesson_id"];
        $videoId = $_POST["video_id"];
        $percent = intval($_POST["percent"]);
        $seconds = intval($_POST["seconds"]);

        $valueQuery = new DBManager(get_current_user_id());
        $data = $valueQuery->getStudentProgress();

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

            $valueQuery->setStudentProgress($data);
        }

    }

    public function getStudentProgress() {
        try {
            $result = [];
            $studentId = get_current_user_id();

            if (current_user_can('manage_options') && isset($_POST["student_id"])) {
                $studentId = $_POST["student_id"];
            }

            $valueQuery = new DBManager($studentId);
            $courses = $valueQuery->getStudentCourses();
            $courses = array_reduce($courses, function ($carry, $item) {
                //make sure just new students are participating
                if (strtotime($item["registration_date"]) <= strtotime("2023-06-01")) {
                    return $carry;
                }

                $carry[] = $item["course_id"];
                return $carry;
            }, []);

            $result["progress"] = $valueQuery->getStudentProgress();
            $result["course_tree"] = Courses::get_courses_tree($courses);

            echo json_encode($result);
            die();
        } catch (Exception $ex) {
            echo json_encode([]);
            die();
        }
    }

    public function setStudentNotes() {
        try {
            $lessonId = intval($_POST["lesson_id"]);
            $notes = $_POST["notes"];

            $valueQuery = new DBManager(get_current_user_id());

            $valueQuery->setStudentNotes($lessonId, $notes);

            echo json_encode([]);
            die();
        } catch (Exception $ex) {
            echo json_encode([]);
            die();
        }
    }

    public function getLessonContent() {
        try {
            $result = [];

            $courseId = intval($_POST["course_id"]);
            $lessonId = intval($_POST["lesson_id"]);

            //check if course exists
            $course = PodsWrapper::factory("course", $courseId);
            $valueQuery = new DBManager(get_current_user_id());

            //course id not found or student not listed
            if (!$course->exists()
                || !$valueQuery->isAttending($courseId)
                || !$valueQuery->isLessonOpen($courseId, $lessonId)
            ) {
                throw new Exception("Failed to load class");
                return;
            }

            $class = $valueQuery->getClass($courseId);

            $pod = PodsWrapper::factory('lesson', $lessonId);

            $result["presentation"] = $pod->display('presentation');
            $result["homework"] = $pod->display('homework');
            $result["additionalFiles"] = $pod->display('additional_files');
            $result["lessonContent"] = $pod->display('post_content');
            $result["studentNotes"] = $valueQuery->getStudentNotes($lessonId);

            $videos = $pod->raw('video_list');
            $videos = empty($videos) ? [] : json_decode($videos, true);

            if (!empty($videos)) {
                $result["videos"] = $videos;
            }

            echo json_encode($result);
            die();

        } catch (Exception $ex) {
            echo json_encode(["error" => $ex->getMessage()]);
            die();
        }
    }

    public static function notifyAdmins($title, $message) {
        if (wp_get_environment_type() !== "production") {
            return;
        }

        do_action('future-lms/admin_notification', [
            "title" => $title,
            "message" => $message
        ]);
    }

    public function getLessons() {
        try {
            $classId = $_REQUEST["class_id"];

            $class = PodsWrapper::factory("class", $classId);

            $result = [];
            if (!$class) {
                throw new Exception('cannot find class by id');
            }

            $classLessons = $class->raw("lessons");
            if ($classLessons) {
                $classLessons = json_decode($classLessons, true);
            } else {
                $classLessons = [];
            }

            $course = $class->raw("course");

            $modules = PodsWrapper::factory("module", ["where" => "course.id = " . $course["ID"], "orderby" => "cast(order.meta_value  as unsigned int) ASC", "limit" => -1]);

            while ($module = $modules->fetch()) {
                $moduleId = $module->raw('ID');

                $lessons = PodsWrapper::factory("lesson", ["where" => "module.id = " . $moduleId, "orderby" => "cast(lesson_number.meta_value as unsigned int) ASC", "limit" => -1]);

                while ($lesson = $lessons->fetch()) {
                    $open = false;
                    $pos = array_search($lesson->raw("ID"), array_column($classLessons, 'id'));
                    if (false !== $pos) {
                        $open = $classLessons[$pos]["open"];
                    }

                    $result[] = [
                        "module_id" => $moduleId,
                        "module_title" => $module->raw("name", true),
                        "module_order" => $module->raw("order", true),
                        "intro_module" => $module->raw("intro_module", true),
                        "lesson_number" => $lesson->raw("lesson_number", true),
                        "id" => $lesson->raw("ID", true),
                        "title" => stripslashes($lesson->raw("title", true)),
                        "open" => $open
                    ];
                }

            }

            echo json_encode($result);
            die();

        } catch (Exception $ex) {
            echo json_encode([]);
            die();
        }
    }

    public function sendEmail() {
        $courseId = $_REQUEST["course_id"];
        $classId = $_REQUEST["class_id"];
        $subject = $_REQUEST["subject"];
        $content = $_REQUEST["content"];
        $test = $_REQUEST["test"] == "1";

        $users = [];
        if (!$test) {
            $users = DBManager::getClassStudents($courseId, $classId, null);
        } else {
            $admins = get_users('role=administrator');
            foreach ($admins as $user) {
                $users[] = ["user_email" => $user->user_email];
            }
        }

        $headers = [];
        $headers[] = 'From: Yinon Arieli <yinon@bursa4u.com>';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $content = stripslashes($content);

        $addressees = array_reduce($users, function ($result, $user) {
            $result[] = "Bcc: " . $user["user_email"];
            return $result;
        }, []);

        $addresseeChunks = array_chunk($addressees, 100);

        foreach ($addresseeChunks as $addresseeChunk) {
            $heads = array_merge($headers, $addresseeChunk);

            if (wp_get_environment_type() != 'production') {
                continue;
            }

            wp_mail(['yinon@bursa4u.com'], $subject, $content, $heads);
        }

        echo json_encode([]);
        die();
    }

    public function setLesson() {
        $classId = $_REQUEST["class_id"];
        $lessonId = $_REQUEST["lesson_id"];

        $class = PodsWrapper::factory("class", $classId);

        if (!$class) {
            die();
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
        echo json_encode([]);
        die();
    }

    public function getAllPayments() {
        $month = intval($_REQUEST["month"]);
        $year = intval($_REQUEST["year"]);
        $results = DBManager::getPayments($year, $month);
        echo json_encode($results);
        die();
    }

    public function getCourseChargeUrl() {
        $course = PodsWrapper::factory('Course', intval($_REQUEST["course_id"]));
        $chargeUrl = $course->field('charge_url');
        $fullPrice = $course->field('full_price');
        $chargeUrl = add_query_arg(['sum' => $fullPrice], $chargeUrl); //will replace if exists.
        $chargeUrl = add_query_arg(['pdesc' => urlencode($course->field('title'))], $chargeUrl);
        echo json_encode(['charge_url' => $chargeUrl]);
        die();
    }

    public function getAllCourses() {
        echo json_encode(["courses" => Courses::get_courses_tree(null, false)]);
        die();
    }

    public function searchClasses() {
        try {
            $search = isset($_REQUEST['search']) ? $_REQUEST['search'] : false;
            $courseId = $_REQUEST['course_id'];

            $result = DBManager::getCourseClasses($courseId, $search);

            $result = array_map(function ($item) {
                return [
                    'id' => $item["id"],
                    'title' => $item["title"],
                    'value' => $item["id"],
                    'name' => $item["title"]
                ];
            }, $result);

            echo json_encode(['success' => true, "results" => $result]);
            die();
        } catch (Exception $ex) {
            echo json_encode(['success' => false, "results" => []]);
            die();
        }
    }

    public function searchStudents() {
        $search = $_REQUEST['search'];

        if (empty($search)) {
            echo json_encode(["success" => true, "results" => []]);
            die();
        }

        $existing = [];
        if (isset($_REQUEST["class_id"])) {
            $existing = DBManager::getClassStudents(null, $_REQUEST["class_id"]);
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

        echo json_encode(["success" => true, "results" => $result]);

        die();
    }

    public static function get_course_image($course_id, $size = 'thumbnail') {
      $course = PodsWrapper::factory('Course', $course_id);
      $image = $course->field('_thumbnail_id');
      $genericImage = plugin_dir_url(__FILE__) . 'assets/images/generic-course-placeholder.png';
      $found = true;
      //check if image exists
      if(empty($image) || !is_numeric($image)) {
        $found = false;
        $image = $genericImage;
      } else {
        $image = wp_get_attachment_image_src($image, $size);
        if (empty($image) || !is_array($image)) {
          $found = false;
          $image = $genericImage;
        } else {
          $image = $image[0]; //get the URL
        }
      }

      return apply_filters('future-lms/course_image', $image, $found, $course_id, $size);
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