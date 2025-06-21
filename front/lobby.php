<?php
/*
Template Name: School Template
 */

use FutureLMS\classes\Course;
use FutureLMS\classes\Student;
use FutureLMS\FutureLMS;

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
          <span class="hello"><?php echo sprintf(__('Hey %s','future-lms'), $user->data->display_name); ?>, 
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
switch ($schoolPage) {
case "mycourses":
  FutureLMS::get_template_part("my_courses.php", [
    'attending_courses' => $attending_courses,
    'student' => $student
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
			</div> <!-- main content -->
		</div> <!-- page-content -->
	</div>