<?php
/**
 * Plugin Name: Future LMS
 * Plugin URI: https://valueinvesting.co.il/
 * Description: Custom plugin for value investing school
 * Version: 3.0.13
 * Author: nimrod-cohen
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

// Read the version once from the plugin header so it's the single source of
// truth (kept in sync with the GitHub releases). Every wp_enqueue_* below
// passes this as the $ver argument — WP then appends `?ver=X.Y.Z` to each
// asset URL, so a plugin-version bump (which happens on every deploy)
// automatically busts the browser cache for every student on their next
// visit. No need for the old per-hour `?time=` trick that left people on
// stale code for up to 59 minutes after a release.
if (!defined('FUTURE_LMS_VERSION')) {
  $flms_header = \get_file_data(__FILE__, ['Version' => 'Version']);
  define('FUTURE_LMS_VERSION', $flms_header['Version'] ?: '0.0.0');
  unset($flms_header);
}

// Asset URLs have to be built from the plugin root, not from whichever file
// happens to need one: plugin_dir_url(__FILE__) inside classes/ resolves to
// future-lms/classes/ and silently yields a URL that 404s.
if (!defined('FUTURE_LMS_URL')) {
  define('FUTURE_LMS_URL', plugin_dir_url(__FILE__));
}

use Exception;
use FutureLMS\classes\BaseObject;
use FutureLMS\classes\Course;
use FutureLMS\classes\Diploma;
use FutureLMS\classes\Lesson;
use FutureLMS\classes\ProgressManager;
use FutureLMS\classes\Quiz;
use FutureLMS\classes\SchoolClass;
use FutureLMS\classes\Settings;
use FutureLMS\classes\Student;
use FutureLMS\classes\VersionManager;
use WP_User_Query;

class FutureLMS {
  function __construct() {
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/includes/github-updater.php';
    new GitHubPluginUpdater(__FILE__);

    VersionManager::install_version();

    add_action('init', [$this, 'init_hooks']);

    add_shortcode('flms_course_price', ['FutureLMS\classes\Course', 'get_course_price_box']);
    add_shortcode('flms_school_lobby', [$this, 'show_school_lobby']);
    add_filter('body_class', [$this, 'add_school_class_to_body']);

    add_filter('manage_lesson_posts_columns', [$this, 'addLessonsColumns']);
    add_action('manage_lesson_posts_custom_column', [$this, 'fillLessonsColumns'], 10, 2);
    add_action('plugins_loaded', [$this, 'future_lms_load_textdomain']);

    // Hooks/actions contract for cross-plugin communication
    $this->register_hooks();
  }

  private function register_hooks() {
    // Create a student user, returns user ID
    add_filter('future-lms/create_student', function ($userId, $email, $name, $phone) {
      $student = Student::create($email, '', $email);
      $newId = $student->get_id();
      if (!empty($name)) {
        wp_update_user(["ID" => $newId, "display_name" => $name]);
      }
      if (!empty($phone)) {
        $phone = apply_filters('future-lms/student_phone', $phone);
        update_user_meta($newId, 'user_phone', $phone);
      }
      return $newId;
    }, 10, 4);

    // Check if student is attending a course
    add_filter('future-lms/is_attending_course', function ($result, $studentId, $courseId) {
      $student = new Student($studentId);
      return $student->is_attending_course($courseId);
    }, 10, 3);

    // Get student's class for a course
    add_filter('future-lms/get_student_class', function ($result, $studentId, $courseId) {
      $student = new Student($studentId);
      return $student->get_class($courseId);
    }, 10, 3);

    // Subscribe/unsubscribe a student to/from a class
    add_action('future-lms/subscribe_to_class', function ($studentId, $classId, $subscribe) {
      $student = new Student($studentId);
      $student->subscribe_to_class($classId, $subscribe);
    }, 10, 3);

    // Get course tree
    add_filter('future-lms/get_course_tree', function ($result, $courseIds) {
      return Course::get_courses_tree($courseIds);
    }, 10, 2);

    // Get student progress for a course (returns array with percent, watched, duration)
    add_filter('future-lms/get_student_progress', function ($result, $studentId, $courseId) {
      $tree = Course::get_courses_tree([$courseId]);
      return ProgressManager::getCourseProgress($studentId, $courseId, $tree);
    }, 10, 3);

    // Get student count for a course (or total)
    add_filter('future-lms/student_count', function ($count, $courseId) {
      global $wpdb;
      $prefix = self::TABLE_PREFIX();

      if ($courseId) {
        $db_count = (int) $wpdb->get_var($wpdb->prepare(
          "SELECT COUNT(DISTINCT cs.student_id)
           FROM {$prefix}class_to_students cs
           INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = cs.class_id AND pm.meta_key = 'course' AND pm.meta_value = %d",
          $courseId
        ));
        $initial = (int) get_post_meta($courseId, 'initial_student_count', true);
        return $db_count + $initial;
      }

      return (int) $wpdb->get_var("SELECT COUNT(DISTINCT student_id) FROM {$prefix}class_to_students");
    }, 10, 2);

    // Get course price
    add_filter('future-lms/course_price', function ($price, $courseId) {
      $course = new Course($courseId);
      $discount = $course->field('discount_price');
      $maxpay = $course->field('maxpay');
      return [
        'full_price' => floatval($course->field('full_price')),
        'discount_price' => !empty($discount) ? floatval($discount) : null,
        'maxpay' => !empty($maxpay) ? intval($maxpay) : 1,
      ];
    }, 10, 2);

    // Get course code (short name)
    add_filter('future-lms/course_code', function ($code, $courseId) {
      return get_post_meta($courseId, 'course_code', true) ?: $code;
    }, 10, 2);
  }

  function future_lms_load_textdomain() {
    load_plugin_textdomain('future-lms', false, dirname(plugin_basename(__FILE__)) . '/languages/');
  }

  public static function TABLE_PREFIX() {
    global $wpdb;
    return $wpdb->prefix . 'flms_';
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
    if (is_admin() || !is_user_logged_in() || !current_user_can('student')) {
      return;
    }
    //if request is POST and course_id is set, redirect to course page
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['course_id'], $_POST['class_id'], $_POST['lesson_id'])) {
      require_once plugin_dir_path(__FILE__) . 'front/course.php';
    } else {
      require_once plugin_dir_path(__FILE__) . 'front/lobby.php';
    }
  }

  public function init_hooks() {
    add_action('admin_menu', [$this, 'add_admin_menu']);
    add_action('load-toplevel_page_future-lms-settings', [$this, 'make_admin_page_fullscreen']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    add_action("wp_enqueue_scripts", [$this, 'enqueueSchoolScripts']);
    add_action("wp_ajax_search_students", [$this, "search_students"]);
    add_action("wp_ajax_get_all_courses", [$this, "get_all_Courses"]);
    add_action("wp_ajax_search_classes", [$this, "search_classes"]);
    add_action("wp_ajax_search_catalog", [$this, "search_catalog"]);
    add_action("wp_ajax_get_classes", [$this, "get_student_classes"]);
    add_action("wp_ajax_get_lessons", [$this, "get_class_lessons"]);
    add_action("wp_ajax_get_lesson_content", [$this, "get_lesson_content"]);
    add_action("wp_ajax_skip_lesson", [$this, "skip_lesson"]);
    add_action("wp_ajax_set_student_notes", [$this, "set_student_notes"]);
    add_action("wp_ajax_get_students", [$this, "get_students"]);
    add_action("wp_ajax_get_student_progress_detail", [$this, "get_student_progress_detail"]);
    add_action("wp_ajax_remove_class", [$this, "remove_student_from_class"]);
    add_action("wp_ajax_set_lesson_open_at", [$this, "set_lesson_open_at"]);
    add_action("wp_ajax_set_class_pacing", [$this, "set_class_pacing"]);
    add_action("wp_ajax_send_email", [$this, "sendEmail"]);
    add_action("wp_ajax_future_lms_get_settings", [$this, "get_settings"]);
    add_action("wp_ajax_future_lms_set_settings", [$this, "set_settings"]);
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
    add_action('template_redirect', [$this, 'restrict_school_access']);

    //add student role if not exists
    add_role('student', 'Student', [
      'read' => true,
      'edit_posts' => false,
      'delete_posts' => false
    ]);

    // Initialize ProgressManager (registers AJAX handlers)
    ProgressManager::get_instance();

    // Initialize Quiz (registers its student + admin AJAX handlers)
    Quiz::get_instance();

    // Diploma clean URL: /school/diploma/{course_id}
    add_rewrite_rule(
      '^school/diploma/([0-9]+)/?$',
      'index.php?flms_diploma=1&flms_course_id=$matches[1]',
      'top'
    );
    add_filter('query_vars', function ($vars) {
      $vars[] = 'flms_diploma';
      $vars[] = 'flms_course_id';
      return $vars;
    });
    add_action('template_redirect', [$this, 'handleDiplomaRoute']);
  }

  public function restrict_school_access() {
    if (is_single()) {
      global $post;
      // Check if the post type is lesson, module, class or course
      if (in_array($post->post_type, ['lesson', 'module', 'class']) && !current_user_can('student')) {
        wp_redirect(wp_login_url());
      }
    }
  }

  public function handleDiplomaRoute() {
    if (!get_query_var('flms_diploma')) {
      return;
    }
    $courseId = intval(get_query_var('flms_course_id'));

    // Temporary debug: save PDF to file and show what's going on
    if (isset($_GET['debug_diploma'])) {
      while (ob_get_level()) { ob_end_clean(); }
      header('Content-Type: text/html; charset=utf-8');
      echo '<pre>';
      try {
        $studentId = get_current_user_id();
        echo "Student: $studentId, Course: $courseId\n";
        echo "OB levels before generate: " . ob_get_level() . "\n";
        echo "Headers sent: " . (headers_sent($f, $l) ? "YES $f:$l" : "NO") . "\n";

        $data = Diploma::getDefaultData($studentId, $courseId);
        $data = apply_filters('future-lms/diploma/data', $data, $studentId, $courseId);
        echo "Data keys: " . implode(', ', array_keys($data)) . "\n";
        echo "Logo URL length: " . strlen($data['logo_url']) . "\n";

        $html = Diploma::buildHtmlPublic($data, $studentId, $courseId);
        echo "HTML length: " . strlen($html) . "\n";

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isFontSubsettingEnabled', true);
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $pdf = $dompdf->output();
        echo "PDF size: " . strlen($pdf) . " bytes\n";
        echo "PDF starts with: " . substr($pdf, 0, 20) . "\n";
        echo "\nSUCCESS\n";
      } catch (\Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        echo $e->getFile() . ":" . $e->getLine() . "\n";
        echo $e->getTraceAsString() . "\n";
      }
      echo '</pre>';
      exit;
    }

    $this->downloadDiploma($courseId);
  }

  public function downloadDiploma(int $courseId = 0) {
    if (!$courseId) {
      $courseId = intval($_REQUEST['course_id'] ?? 0);
    }

    if (!$courseId || !is_user_logged_in()) {
      wp_die(__('Invalid request', 'future-lms'), 403);
    }

    $studentId = get_current_user_id();
    $student = new Student($studentId);

    if (!$student->is_attending_course($courseId)) {
      wp_die(__('Access denied', 'future-lms'), 403);
    }

    if (!Diploma::isEnabled($courseId)) {
      wp_die(__('Diploma not available', 'future-lms'), 404);
    }

    if (!Diploma::isEligible($studentId, $courseId)) {
      wp_die(__('Not eligible for diploma', 'future-lms'), 403);
    }

    Diploma::generate($studentId, $courseId);
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

  // wp_ajax_* runs for any logged-in user - students included - so every
  // admin-screen handler has to check for itself.
  private function guard_admin() {
    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
  }

  public function get_settings() {
    $this->guard_admin();

    $result = Settings::all();

    // The option holds an attachment id; the picker needs a URL to preview it.
    $image_id = (int) ($result['default_course_image'] ?? 0);
    $result['default_course_image_url'] = $image_id > 0
    ? (wp_get_attachment_image_url($image_id, 'medium') ?: '')
    : '';

    wp_send_json($result);
  }

  public function set_settings() {
    $this->guard_admin();

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
      // SITE_LOGS_DIR (wp-config.php) keeps logs out of the web root; without it,
      // or when it cannot be written, they stay in the site's own logs folder.
      (defined('SITE_LOGS_DIR') && is_writable(SITE_LOGS_DIR) ? SITE_LOGS_DIR : ABSPATH . DIRECTORY_SEPARATOR . "logs") . DIRECTORY_SEPARATOR . "debug-$date.log",
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
      $module_id = $wpdb->get_var("select meta_value from " . $wpdb->prefix . "postmeta where post_id = $lesson_id and meta_key = 'module'");
      $module_order = $wpdb->get_var("select meta_value from " . $wpdb->prefix . "postmeta where post_id = $module_id and meta_key = 'order'");
      $module_name = $wpdb->get_var("select post_title from " . $wpdb->prefix . "posts where id = $module_id");
      echo "<a href='" . site_url("/wp-admin/post.php?post=" . $module_id . "&action=edit") . "'>" . $module_name . "(" . $module_order . ")</a>";
    } else if ('lesson_number' == $column_name) {
      $lesson_number = $wpdb->get_var("select meta_value from " . $wpdb->prefix . "postmeta where post_id = $lesson_id and meta_key = 'lesson_number'");
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

  public function saveExtraUserFields($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
      return false;
    }
    $phone = apply_filters('future-lms/student_phone', $_POST["phone"] ?? '');
    update_user_meta($user_id, 'user_phone', $phone);
  }

  public function enqueueSchoolScripts() {
    wp_enqueue_script('future-lms_main_script', plugin_dir_url(__FILE__) . 'front/main.js', ['wpjsutils'], FUTURE_LMS_VERSION);
    wp_enqueue_style('future-lms_style', plugin_dir_url(__FILE__) . 'front/school.css', [], FUTURE_LMS_VERSION);
    wp_enqueue_script('future-lms_school_script', plugin_dir_url(__FILE__) . 'front/school.js', ['wpjsutils'], FUTURE_LMS_VERSION);

    wp_localize_script('future-lms_school_script', 'school_info', [
      'ajax_url' => admin_url('admin-ajax.php'),
      'theme_url' => plugin_dir_url(__FILE__),
      // The sidebar builds <img> URLs in JS, so those assets miss the ?ver=
      // that wp_enqueue_* adds to scripts and styles. Hand the version over so
      // an icon redraw actually reaches people instead of sitting behind the
      // browser cache.
      'version' => FUTURE_LMS_VERSION,
      // Lessons open with captions in this language when the video has them.
      // Taken from the site's own language, so each school gets its own
      // without configuration; the filter is there for a site that wants
      // something else, or '' to turn default captions off.
      'caption_language' => apply_filters(
        'future-lms/caption_language',
        strtolower(strtok(determine_locale(), '_-'))
      ),
    ]);
  }

  public function enqueue_admin_assets($hook) {
    if ("toplevel_page_future-lms-settings" != $hook) {
      return;
    }

    if (function_exists('wp_enqueue_media')) {
      wp_enqueue_media();
    }

    // Enqueue WP Pointer to prevent "pointer is not a function" errors from WP core
    wp_enqueue_style('wp-pointer');
    wp_enqueue_script('wp-pointer');

    wp_enqueue_script('future-lms-admin-icons-js', plugin_dir_url(__FILE__) . 'admin/js/icons.js', [], FUTURE_LMS_VERSION);
    wp_enqueue_script('future-lms-admin-common-js', plugin_dir_url(__FILE__) . 'admin/js/common.js', ['future-lms-admin-icons-js', 'wpjsutils'], FUTURE_LMS_VERSION);
    wp_enqueue_script('future-lms-admin-students-js', plugin_dir_url(__FILE__) . 'admin/js/students.js', ['wpjsutils', 'jquery'], FUTURE_LMS_VERSION);
    wp_enqueue_script('future-lms-admin-courses-js', plugin_dir_url(__FILE__) . 'admin/js/courses.js', ['wpjsutils', 'jquery'], FUTURE_LMS_VERSION);
    wp_enqueue_script('future-lms-admin-settings-js', plugin_dir_url(__FILE__) . 'admin/js/settings.js', ['wpjsutils', 'jquery', 'future-lms-admin-common-js'], FUTURE_LMS_VERSION);
    wp_enqueue_script('future-lms-admin-palette-js', plugin_dir_url(__FILE__) . 'admin/js/palette.js', ['future-lms-admin-common-js'], FUTURE_LMS_VERSION);
    wp_enqueue_script('future-lms-admin-js', plugin_dir_url(__FILE__) . 'admin/js/admin.js', ['future-lms-admin-students-js', 'future-lms-admin-palette-js'], FUTURE_LMS_VERSION);
    wp_enqueue_style('future-lms-admin-css', plugin_dir_url(__FILE__) . 'admin/css/admin.css', ['trumbowyg-css'], FUTURE_LMS_VERSION);

    wp_localize_script('future-lms-admin-js', '__futurelms', [
      'ajax_url' => admin_url('admin-ajax.php'),
      'store_currency' => Settings::get("store_currency"),
      'timezone' => wp_timezone_string()
    ]);

    wp_enqueue_script('trumbowyg-js', plugin_dir_url(__FILE__) . 'assets/trumbowyg/trumbowyg.min.js');
    wp_enqueue_script('trumbowyg-base64-js', plugin_dir_url(__FILE__) . 'assets/trumbowyg/plugins/base64/trumbowyg.base64.min.js', ['trumbowyg-js']);
    wp_enqueue_script('trumbowyg-HEB-js', plugin_dir_url(__FILE__) . 'assets/trumbowyg/langs/he.min.js', ['trumbowyg-js']);
    wp_enqueue_style('trumbowyg-css', plugin_dir_url(__FILE__) . 'assets/trumbowyg/ui/trumbowyg.min.css');
  }

  /**
   * The graduation cap from the app's logo (Lucide "graduation-cap") as a menu
   * icon. WordPress recolors menu SVGs by rewriting `fill` only, and Lucide
   * draws with strokes: so the strokes are a mask and a filled square shows
   * through it - that fill is what WordPress recolors per color scheme and on
   * hover. The mask paths carry no fill attribute for it to rewrite.
   */
  public static function menu_icon() {
    $paths = '<path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/>'
      . '<path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>';
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
      . '<mask id="c"><g stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</g></mask>'
      . '<rect width="24" height="24" fill="#a7aaad" mask="url(#c)"/></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
  }

  public function add_admin_menu() {
    add_menu_page('Future LMS', 'Future LMS', 'manage_options', 'future-lms-settings', [$this, 'show_admin_page'], self::menu_icon(), 30);
  }

  // The Future LMS screen is a full-window app: no WP admin bar, menu,
  // footer, Screen Options or other plugins' notices (admin.css hides the
  // chrome; it only loads on this page). The app bar links back to WP.
  public function make_admin_page_fullscreen() {
    add_filter('admin_body_class', function ($classes) {
      return $classes . ' flms-fullscreen';
    });
    add_filter('screen_options_show_screen', '__return_false');
    // WordPress's own ⌘K command palette would open underneath ours.
    remove_action('admin_enqueue_scripts', 'wp_enqueue_command_palette_assets');
    add_action('in_admin_header', function () {
      remove_all_actions('admin_notices');
      remove_all_actions('all_admin_notices');
    }, 1000);
  }

  public function show_admin_page() {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'admin/admin.php';
  }

  public function remove_student_from_class() {
    $this->guard_admin();

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
    $this->guard_admin();

    $courseId = $_REQUEST["course_id"];
    $studentId = $_REQUEST["student_id"];

    $student = new Student($studentId);

    $classes = BaseObject::factory("class", ["where" => "course.id = " . $courseId], -1);

    foreach ($classes->results() as $row) {
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
    $this->guard_admin();

    try {
      $courseId = intval($_REQUEST["course_id"]);
      $classId = intval($_REQUEST["class_id"]);
      $search = trim($_REQUEST["search"]);
      $month = intval($_REQUEST["month"]);
      $year = intval($_REQUEST["year"]);

      $students = SchoolClass::students($courseId, $classId, $search, $month, $year);

      // Calculate progress for all students in one pass
      if (!empty($students)) {
        $courseIds = array_unique(array_column($students, 'course_id'));
        $courseTree = Course::get_courses_tree($courseIds);

        foreach ($students as &$student) {
          $cid = (int) $student['course_id'];
          $sid = (int) $student['id'];
          $progressData = ProgressManager::getCourseProgress($sid, $cid, $courseTree);
          $student['progress'] = floor($progressData['percent']);
        }
        unset($student);
      }

      wp_send_json($students);
    } catch (Exception $ex) {
      wp_send_json(["error" => $ex->getMessage()]);
    }
  }

  public function get_student_progress_detail() {
    $this->guard_admin();

    try {
      $studentId = intval($_REQUEST["student_id"]);
      $courseId   = intval($_REQUEST["course_id"]);

      if (!$studentId || !$courseId) {
        wp_send_json_error(["message" => "Missing student_id or course_id"]);
      }

      $user = get_user_by("id", $studentId);
      if (!$user) { wp_send_json_error(["message" => "Student not found"]); }

      $courseTree = Course::get_courses_tree([$courseId], false);
      if (empty($courseTree[$courseId])) {
        wp_send_json_error(["message" => "Course not found"]);
      }

      $ct = $courseTree[$courseId];

      // Get detailed per-lesson progress.
      $detailedProgress = ProgressManager::getDetailedLessonsProgress($studentId, $courseId, $courseTree);

      // Build a flat module → lessons structure for the frontend.
      $modules = [];
      $totalLessons = 0;
      $completedLessons = 0;

      foreach ($ct["modules"] as $moduleId => $mod) {
        $modData = [
          "id"      => (int) $moduleId,
          "name"    => $mod["name"] ?? '',
          "order"   => (int) ($mod["order"] ?? 0),
          "intro"   => !empty($mod["intro_module"]),
          "lessons" => [],
        ];

        foreach ($mod["lessons"] as $lessonId => $lesson) {
          $lessonId = (int) $lessonId;
          $totalLessons++;

          $lessonProgress = $detailedProgress[$lessonId] ?? [];
          $isCompleted = false;
          $watchedSeconds = 0;
          foreach ($lessonProgress as $vid => $vp) {
            if ((int) $vp["percent"] >= 100) $isCompleted = true;
            $watchedSeconds += (int) $vp["seconds"];
          }
          if ($isCompleted) $completedLessons++;

          $modData["lessons"][] = [
            "id"        => $lessonId,
            "name"      => $lesson["name"] ?? get_the_title($lessonId),
            "number"    => (int) ($lesson["order"] ?? 0),
            "completed" => $isCompleted,
            "seconds"   => $watchedSeconds,
            "duration"  => (int) ($lesson["duration"] ?? 0),
          ];
        }

        usort($modData["lessons"], function ($a, $b) {
          return $a["number"] <=> $b["number"];
        });

        $modules[] = $modData;
      }

      usort($modules, function ($a, $b) {
        if ($a["intro"] && !$b["intro"]) return -1;
        if (!$a["intro"] && $b["intro"]) return 1;
        return $a["order"] <=> $b["order"];
      });

      $percent = $totalLessons > 0 ? round($completedLessons / $totalLessons * 100) : 0;

      wp_send_json_success([
        "student"  => [
          "id"    => $studentId,
          "name"  => $user->display_name,
          "email" => $user->user_email,
        ],
        "course"   => [
          "id"   => $courseId,
          "name" => $ct["name"],
        ],
        "progress" => [
          "percent"   => $percent,
          "completed" => $completedLessons,
          "total"     => $totalLessons,
        ],
        "modules"  => $modules,
      ]);
    } catch (\Exception $ex) {
      wp_send_json_error(["message" => $ex->getMessage()]);
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

      // `presentation` holds a media attachment id, but the front end uses this
      // value directly as the download link's href. Resolve it to a real URL —
      // passing the bare id through produced a relative href like "1731", and a
      // detached presentation (id 0) produced href="0". Both rendered a
      // "download the presentation" link that downloaded nothing.
      $presentationId = intval($lesson->raw('presentation'));
      $result["presentation"] = $presentationId ? (wp_get_attachment_url($presentationId) ?: '') : '';
      $result["homework"] = $lesson->display('homework');
      $result["additionalFiles"] = $lesson->display('additional_files');
      $result["lessonContent"] = do_shortcode(wpautop($lesson->display('post_content')));
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

      $paced = SchoolClass::is_paced($class_id);

      $class_lessons = json_decode($class->raw("lessons") ?: '[]', true);
      $class_lessons = is_array($class_lessons) ? $class_lessons : [];

      $course_id = $class->raw("course");
      $sequential = get_post_meta($course_id, 'sequential_progress', true) == '1';
      $sequentialLockMap = [];
      if ($sequential) {
        $student = new Student(get_current_user_id());
        $sequentialLockMap = $student->sequential_lock_map($course_id);
      }

      // Lessons shut behind an unpassed blocking quiz. Cheap no-op for courses
      // without one — see Quiz::has_blocking_quiz.
      $quizLockMap = Quiz::locked_lessons(get_current_user_id(), (int) $course_id);

      $modules = BaseObject::factory("module", ["where" => "course.id = " . $course_id, "orderby" => "cast(order.meta_value  as unsigned int) ASC", "limit" => -1]);

      while ($module = $modules->fetch()) {
        $module_id = $module->raw('ID');
        $module_count_progress = $module->raw("count_progress", true) == "1";

        $lessons = BaseObject::factory("lesson", ["where" => "module.id = " . $module_id, "orderby" => "cast(lesson_number.meta_value as unsigned int) ASC", "limit" => -1]);

        while ($lesson = $lessons->fetch()) {
          $lesson_id = $lesson->raw("ID");
          // The class's schedule (open date/hour per lesson).
          $released = SchoolClass::is_lesson_released($class_lessons, $lesson_id, $paced);
          $entry = SchoolClass::find_lesson_entry($class_lessons, $lesson_id);
          $open = $released;
          // Sequential gate: only narrows `open`, never opens what drip closed.
          if ($sequential && array_key_exists((int) $lesson_id, $sequentialLockMap)) {
            $open = $open && $sequentialLockMap[(int) $lesson_id];
          }
          // Quiz gate: likewise only narrows.
          if (array_key_exists((int) $lesson_id, $quizLockMap)) {
            $open = false;
          }

          $result[] = [
            "module_id" => $module_id,
            "module_title" => $module->raw("name", true),
            "module_order" => $module->raw("order", true),
            "intro_module" => $module->raw("intro_module", true),
            "lesson_number" => $lesson->raw("lesson_number", true),
            "id" => $lesson_id,
            "title" => stripslashes($lesson->raw("title", true)),
            "open" => $open,
            "released" => $released,
            "open_at" => $entry['open_at'] ?? null,
            "count_progress" => $module_count_progress,
            "sequential" => $sequential
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

  /**
   * Escape hatch for sequential-progress courses: marks a lesson's videos
   * all as 100% complete so the next lesson unlocks. Each video gets
   * (lesson_duration / video_count) seconds, so course/module/lesson percent
   * calculations all reflect the skip.
   *
   * Fires vi_course_progress_updated with skipped=true so downstream CRMs
   * can distinguish skipped vs. genuinely-watched.
   */
  public function skip_lesson() {
    try {
      $studentId = get_current_user_id();
      $lessonId = intval($_POST["lesson_id"] ?? 0);
      if (!$studentId || !$lessonId) {
        throw new Exception("Invalid request");
      }

      $lesson = BaseObject::factory("lesson", $lessonId);
      if (!$lesson->exists()) {
        throw new Exception("Lesson not found");
      }
      $moduleId = intval($lesson->raw("module"));
      $courseId = intval(get_post_meta($moduleId, 'course', true));

      $student = new Student($studentId);
      if (!$student->is_attending_course($courseId)) {
        throw new Exception("Not enrolled in course");
      }

      $videos = $lesson->raw('video_list');
      $videos = empty($videos) ? [] : json_decode($videos, true);
      $videoIds = !empty($videos)
        ? array_map(function ($v) { return $v['video_id']; }, $videos)
        : ['text'];

      $lessonDuration = (int) get_post_meta($lessonId, 'lesson_duration', true);
      $perVideoSeconds = max(1, (int) ceil($lessonDuration / max(1, count($videoIds))));

      foreach ($videoIds as $vid) {
        ProgressManager::saveProgress([
          'user_id'   => $studentId,
          'course_id' => $courseId,
          'module_id' => $moduleId,
          'lesson_id' => $lessonId,
          'video_id'  => $vid,
          'percent'   => 100,
          'seconds'   => $vid === 'text' ? 0 : $perVideoSeconds,
        ]);
      }

      $tree = Course::get_courses_tree([$courseId]);
      $coursePercent = (int) floor(ProgressManager::getCourseProgress($studentId, $courseId, $tree)['percent']);
      $modulePercent = (int) floor(ProgressManager::getModuleProgress($studentId, $courseId, $moduleId, $tree)['percent']);

      do_action('vi_course_progress_updated', [
        'user_id'        => $studentId,
        'course_id'      => $courseId,
        'module_id'      => $moduleId,
        'lesson_id'      => $lessonId,
        'video_id'       => $videoIds[0],
        'percent'        => 100,
        'seconds'        => 0,
        'course_percent' => $coursePercent,
        'module_percent' => $modulePercent,
        'lesson_percent' => 100,
        'skipped'        => true,
      ]);

      wp_send_json(["ok" => true]);
    } catch (Exception $ex) {
      wp_send_json(["error" => $ex->getMessage()]);
    }
  }

  public function sendEmail() {
    $this->guard_admin();

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

  // Sets (or clears) the date & hour lessons open for a paced class.
  // lesson_ids is a comma list (one lesson, or a whole module at once);
  // open_at arrives as "Y-m-dTH:i" from a datetime-local input, in site time.
  public function set_lesson_open_at() {
    $this->guard_admin();

    $classId = intval($_REQUEST["class_id"] ?? 0);
    $lessonIds = array_filter(array_map('intval', explode(',', $_REQUEST["lesson_ids"] ?? '')));
    $openAt = trim(str_replace('T', ' ', sanitize_text_field($_REQUEST["open_at"] ?? '')));
    if ($openAt === 'now') {
      $openAt = current_time('Y-m-d H:i');
    }

    if (get_post_type($classId) !== 'class' || empty($lessonIds)) {
      wp_send_json_error(["message" => "Class or lessons not found"], 404);
    }
    foreach ($lessonIds as $lessonId) {
      if (get_post_type($lessonId) !== 'lesson') {
        wp_send_json_error(["message" => "Lesson not found"], 404);
      }
    }
    if ($openAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $openAt)) {
      wp_send_json_error(["message" => "Bad date"], 400);
    }

    $lessons = json_decode(get_post_meta($classId, 'lessons', true) ?: '[]', true);
    $lessons = is_array($lessons) ? $lessons : [];

    // Drop the lessons' old entries; a date replaces any manual open flag.
    $lessons = array_values(array_filter($lessons, function ($l) use ($lessonIds) {
      return !in_array((int) ($l['id'] ?? 0), $lessonIds, true);
    }));
    if ($openAt !== '') {
      foreach ($lessonIds as $lessonId) {
        $lessons[] = ["id" => $lessonId, "open_at" => $openAt];
      }
    }

    update_post_meta($classId, 'lessons', wp_slash(json_encode($lessons)));
    wp_send_json_success();
  }

  public function set_class_pacing() {
    $this->guard_admin();

    $classId = intval($_REQUEST["class_id"] ?? 0);
    if (get_post_type($classId) !== 'class') {
      wp_send_json_error(["message" => "Class not found"], 404);
    }
    $paced = ($_REQUEST["paced"] ?? '') == '1';

    // Going paced: older manual open flags become an opening date of "now",
    // so a lesson that is open stays open and shows why. Closed flags go;
    // an undated lesson in a paced class is closed anyway.
    if ($paced) {
      $lessons = json_decode(get_post_meta($classId, 'lessons', true) ?: '[]', true);
      $now = current_time('Y-m-d H:i');
      $converted = [];
      foreach (is_array($lessons) ? $lessons : [] as $entry) {
        if (!empty($entry['open_at'])) {
          $converted[] = ["id" => (int) $entry['id'], "open_at" => $entry['open_at']];
        } elseif (!empty($entry['open'])) {
          $converted[] = ["id" => (int) $entry['id'], "open_at" => $now];
        }
      }
      update_post_meta($classId, 'lessons', wp_slash(json_encode($converted)));
    }

    update_post_meta($classId, 'paced', $paced ? '1' : '0');
    wp_send_json_success();
  }

  public function get_all_Courses() {
    // Other back-office screens list courses too (a sales panel, say); they
    // opt their users in through the filter.
    if (!apply_filters('future-lms/can_list_courses', current_user_can('manage_options'))) {
      wp_send_json_error(['message' => 'Unauthorized'], 403);
    }

    wp_send_json(["courses" => Course::get_courses_tree(null, false)]);
  }

  /**
   * The Vimeo id in a bare id or any Vimeo link
   * ("https://vimeo.com/813709147", ".../video/813709147?h=..", "player.vimeo.com/video/813709147"),
   * or '' when there is none.
   */
  public static function vimeo_id($value) {
    $value = trim((string) $value);
    if (preg_match('/^\d+$/', $value)) return $value;
    if (preg_match('~vimeo\.com/(?:[^?#]*/)?(\d+)~i', $value, $m)) return $m[1];
    return '';
  }

  /**
   * Lessons whose video list includes a Vimeo video:
   * [lesson_id => ['position' => n (1-based), 'video_count' => n]].
   */
  public static function lessons_using_video($videoId) {
    global $wpdb;
    // The LIKE only narrows the rows; the JSON check below decides.
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
       INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'lesson' AND p.post_status <> 'trash'
       WHERE pm.meta_key = 'video_list' AND pm.meta_value LIKE %s",
      '%' . $wpdb->esc_like($videoId) . '%'
    ));

    $found = [];
    foreach ($rows as $row) {
      $videos = json_decode($row->meta_value, true);
      if (!is_array($videos)) continue;
      foreach (array_values($videos) as $i => $video) {
        $stored = is_array($video) ? ($video['video_id'] ?? '') : $video;
        if (self::vimeo_id($stored) === $videoId) {
          $found[(int) $row->post_id] = ['position' => $i + 1, 'video_count' => count($videos)];
          break;
        }
      }
    }
    return $found;
  }

  /**
   * A course, module or lesson and where it sits in the tree, or null when it
   * isn't one, or it or a parent is in the trash.
   */
  public static function catalog_entry($postId) {
    $post = get_post((int) $postId);
    if (!$post || $post->post_status === 'trash' || !in_array($post->post_type, ['course', 'module', 'lesson'], true)) {
      return null;
    }

    $type = $post->post_type;
    $moduleId = $type === 'module' ? $post->ID : ($type === 'lesson' ? (int) get_post_meta($post->ID, 'module', true) : 0);
    $courseId = $type === 'course' ? $post->ID : ($moduleId ? (int) get_post_meta($moduleId, 'course', true) : 0);
    foreach ([$moduleId, $courseId] as $parent) {
      if ($parent && $parent !== $post->ID && get_post_status($parent) === 'trash') return null;
    }

    // Raw titles: WordPress's display title comes back texturized, with
    // entities like &#8221; that the screen would then escape a second time.
    $title = function ($id) { return $id ? get_post_field('post_title', $id, 'raw') : ''; };

    return [
      'type' => $type,
      'id' => $post->ID,
      'name' => $title($post->ID),
      'enabled' => $post->post_status === 'publish',
      'course_id' => $courseId,
      'course_name' => $title($courseId),
      'module_id' => $moduleId,
      'module_name' => $type === 'lesson' ? $title($moduleId) : '',
      'module_order' => $moduleId ? (int) get_post_meta($moduleId, 'order', true) : 0,
      'lesson_number' => $type === 'lesson' ? (int) get_post_meta($post->ID, 'lesson_number', true) : 0,
    ];
  }

  /**
   * One search over the catalog. q can be a title (or part of one), a
   * course/module/lesson id, a Vimeo video id, or a Vimeo link. Matches come
   * back as id first, then video, then title; each item once.
   */
  public function search_catalog() {
    $this->guard_admin();
    global $wpdb;

    $q = trim(wp_unslash($_REQUEST['q'] ?? ''));
    $isNumber = ctype_digit($q);
    if ($q === '' || (!$isNumber && mb_strlen($q) < 2)) {
      wp_send_json_error(['message' => 'Type at least 2 characters'], 400);
    }

    $limit = 100;
    $results = [];
    $add = function ($entry, $match, array $extra = []) use (&$results) {
      if (!$entry) return;
      $key = $entry['type'] . ':' . $entry['id'];
      if (isset($results[$key])) return; // already listed under a stronger match
      $results[$key] = $entry + ['match' => $match] + $extra;
    };

    if ($isNumber) {
      $add(self::catalog_entry((int) $q), 'id');
    }

    $videoId = self::vimeo_id($q);
    if ($videoId !== '') {
      foreach (self::lessons_using_video($videoId) as $lessonId => $info) {
        $add(self::catalog_entry($lessonId), 'video', ['video_id' => $videoId] + $info);
      }
    }

    if (!preg_match('~vimeo\.com~i', $q)) {
      $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type IN ('course', 'module', 'lesson') AND post_status <> 'trash' AND post_title LIKE %s
         LIMIT %d",
        '%' . $wpdb->esc_like($q) . '%',
        $limit + 1
      ));
      foreach ($ids as $id) $add(self::catalog_entry($id), 'title');
    }

    $results = array_values($results);
    $rank = ['id' => 0, 'video' => 1, 'title' => 2];
    $typeRank = ['course' => 0, 'module' => 1, 'lesson' => 2];
    usort($results, function ($a, $b) use ($rank, $typeRank) {
      return [$rank[$a['match']], $typeRank[$a['type']], $a['course_name'], $a['module_order'], $a['lesson_number'], $a['name']]
        <=> [$rank[$b['match']], $typeRank[$b['type']], $b['course_name'], $b['module_order'], $b['lesson_number'], $b['name']];
    });

    wp_send_json_success([
      'query' => $q,
      'video_id' => $videoId,
      'results' => array_slice($results, 0, $limit),
      'truncated' => count($results) > $limit,
    ]);
  }

  public function search_classes() {
    $this->guard_admin();

    try {
      $search = isset($_REQUEST['search']) ? $_REQUEST['search'] : false;
      $courseId = $_REQUEST['course_id'];

      $result = Course::get_classes($courseId, $search);

      $result = array_map(function ($item) {
        return [
          'id' => $item["id"],
          'title' => $item["title"],
          'value' => $item["id"],
          'name' => $item["title"],
          'paced' => SchoolClass::is_paced($item["id"]),
          'start_date' => get_post_meta($item["id"], 'start_date', true),
          'students' => SchoolClass::student_count($item["id"])
        ];
      }, $result);

      wp_send_json(['success' => true, "results" => $result]);
    } catch (Exception $ex) {
      wp_send_json(['success' => false, "results" => []]);
    }
  }

  public function search_students() {
    $this->guard_admin();

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
        'user_url',
        'display_name'
      ]]);

    $users = $query->get_results();

    // Also search by phone meta
    $phoneQuery = new WP_User_Query([
      'role' => 'student',
      'meta_query' => [[
        'key' => 'user_phone',
        'value' => esc_attr($search),
        'compare' => 'LIKE'
      ]]
    ]);
    $phoneUsers = $phoneQuery->get_results();

    // Merge results, dedup by ID
    $allUsers = array_merge($users, $phoneUsers);
    $seen = [];
    $users = [];
    foreach ($allUsers as $item) {
      if (isset($seen[$item->ID])) continue;
      $seen[$item->ID] = true;
      $phone = get_user_meta($item->ID, 'user_phone', true);
      $subtitle = $item->data->user_email;
      if ($phone) $subtitle .= ' | ' . $phone;
      $users[] = [
        'id' => $item->ID,
        'title' => $item->data->display_name,
        'description' => $subtitle
      ];
    }

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