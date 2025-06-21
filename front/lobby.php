<?php
/*
Template Name: School Template
 */

use FutureLMS\classes\BaseObject;
use FutureLMS\classes\Course;
use FutureLMS\classes\Student;

$post = get_post();

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
$courses = new Course();

$student = new Student($user->ID);

$attendingCourses = [];
$availableCourses = [];

while ($obj = $courses->fetch()) {
  $course = new Course($obj);
  if ($student->is_attending_course($course->raw("ID"))) {
    $attendingCourses[] = $course;
  } else {
    //cast BaseObject to Course
    if ($course->has_tag('hidden')) {
      continue;
    }
    $availableCourses[] = $course;
  }
}
?>
<?php
switch ($schoolPage) {
case "mycourses":
  get_template_part("webparts/my_courses.php", null, [
    'attendingCourses' => $attendingCourses
  ]);
  break;
case "courses":
  get_template_part("webparts/available_courses.php", null, [
    'attendingCourses' => $availableCourses
  ]);
  break;
case "course-details":
  $course = new Course($_POST["course_id"] ?? 0);
  get_template_part("webparts/course_details.php", null, [
    'course' => $course
  ]);
  break;
}
?>
        </div><!-- school courses -->
			</div> <!-- main content -->
		</div> <!-- page-content -->
	</div>