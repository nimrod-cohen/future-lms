<?php
/*
Template Name: School Template
 */

use FutureLMS\classes\BaseObject;
use FutureLMS\classes\Course;
use FutureLMS\classes\Student;

$post = get_post();

$courseWorkspacePage = get_pages(['child_of' => $post->ID, 'meta_key' => '_wp_page_template', 'meta_value' => 'course.php']);
$courseWorkspacePage = get_page_link($courseWorkspacePage[0] ?? null);

$user = wp_get_current_user();

$schoolPage = "courses";
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
          <span class="hello"><?php echo _e('Hey ','future-lms'). $user->data->display_name; ?>, 
            <a class="text-blue-600 underline" href="/"><?php _e('Back to site &larr;','future-lms');?></a>
          </span>
          <span class="tabs">
            <a href="<?php echo $urls["my_courses"]; ?>"><?php _e('My courses','future-lms');?></a>
            <span class="divider">&nbsp;</span>
            <a href="<?php echo $urls["available_courses"]; ?>"><?php _e('Course store','future-lms');?></a>
          </span>
        </div>
        <div class="school-courses">
        <?php
//get all posts of type course, where post is publised
$courses = new BaseObject('course');

$student = new Student($user->ID);

$attendingCourses = [];
$availableCourses = [];

while ($post = $courses->fetch()) {
  //get post object of the current pod
  if ($student->is_attending_course($courses->raw("ID"))) {
    $attendingCourses[] = $post;
  } else {
    if (Course::course_has_tag($courses->raw("ID"), 'hidden')) {
      continue;
    }
    $availableCourses[] = $post;
  }
}
?>
<?php
switch ($schoolPage) {
case "mycourses":
  include_once "webparts/my_courses.php";
  break;
case "courses":
  include_once "webparts/available_courses.php";
  break;
case "course-details":
  include_once "webparts/course_details.php";
  break;
}
?>
        </div><!-- school courses -->
			</div> <!-- main content -->
		</div> <!-- page-content -->
	</div>