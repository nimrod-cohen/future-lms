<?php
/*
Template Name: School Template
 */

use FutureLMS\classes\Course;
use FutureLMS\classes\Diploma;
use FutureLMS\classes\ProgressManager;
use FutureLMS\classes\Settings;
use FutureLMS\classes\Student;
use FutureLMS\FutureLMS;

$post = get_post();

$user = wp_get_current_user();

$schoolPage = Settings::get('default_lobby_page');

if (isset($_GET["pg"])) {
  $schoolPage = $_GET["pg"];
}

$currentUrl = wp_unslash(esc_url_raw(add_query_arg(null, null)));
$urls = [
  "my_courses" => add_query_arg('pg', 'mycourses', $currentUrl),
  "available_courses" => add_query_arg('pg', 'courses', $currentUrl)
];

?>
<div class="container school-container lobby">
		<div class="row page-content">
			<div class="col-lg-12 main-content">
        <div class="school-header">
          <div class="school-header-greeting">
            <span class="hello"><?php echo sprintf(__('Hey %s','future-lms'), $user->data->display_name); ?>,</span>
            <a class="hello-back" href="/"><?php _e('Back to site &larr;','future-lms');?></a>
          </div>
          <div class="school-header-nav">
            <span class="tabs">
              <a class="<?php echo $schoolPage === "mycourses" ? "selected" : ""; ?>" href="<?php echo $urls["my_courses"]; ?>"><?php _e('My courses','future-lms');?></a>
              <span class="divider">&nbsp;</span>
              <a class="<?php echo $schoolPage === "courses" ? "selected" : ""; ?>" href="<?php echo $urls["available_courses"]; ?>"><?php _e('Course store','future-lms');?></a>
              <span class="divider">&nbsp;</span>
              <a href="/help" title="מרכז העזרה" class="help-link"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> מרכז העזרה</a>
            </span>
          </div>
        </div>
        <div class="school-courses">
        <?php
//get all posts of type course, where post is publised
$courses = new Course();

$student = new Student($user->ID);

$attending_courses = [];
$available_courses = [];

while ($obj = $courses->fetch()) {
  $course = new Course($obj);
  if ($student->is_attending_course($course->raw("ID"))) {
    $attending_courses[] = $course;
  } else {
    //cast BaseObject to Course
    if ($course->has_tag('hidden')) {
      continue;
    }
    $available_courses[] = $course;
  }
}
?>
<?php
// Compute progress for attending courses (used for diploma eligibility)
$courseIds = array_map(function ($c) { return (int) $c->raw("ID"); }, $attending_courses);
$progressData = [];
if (!empty($courseIds)) {
  $courseTree = Course::get_courses_tree($courseIds);
  foreach ($courseIds as $cid) {
    $p = ProgressManager::getCourseProgress($user->ID, $cid, $courseTree);
    $progressData[$cid] = $p['percent'] ?? 0;
  }
}

switch ($schoolPage) {
case "mycourses":
  FutureLMS::get_template_part("my_courses.php", [
    'attending_courses' => $attending_courses,
    'student' => $student,
    'progressData' => $progressData,
  ]);
  break;
case "courses":
  FutureLMS::get_template_part("available_courses.php", [
    'available_courses' => $available_courses
  ]);
  break;
case "course-details":
  $course = new Course($_POST["course_id"] ?? 0);
  FutureLMS::get_template_part("course_details.php", [
    'course' => $course
  ]);
  break;
}
?>
        </div><!-- school courses -->
        <div class="lobby-footer">
          <a href="/help">מרכז העזרה</a>
        </div>
			</div> <!-- main content -->
		</div> <!-- page-content -->
	</div>