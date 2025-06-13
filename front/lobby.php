<?php
/*
Template Name: School Template
 */

use FutureLMS\classes\Courses;
use FutureLMS\classes\DBManager;

$post = get_post();

$courseWorkspacePage = get_pages(['child_of' => $post->ID, 'meta_key' => '_wp_page_template', 'meta_value' => 'course.php']);
$courseWorkspacePage = get_page_link($courseWorkspacePage[0]);

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
          <span class="hello">היי <?php echo $user->data->display_name; ?></span>
          <span class="tabs">
            <a href="<?php echo $urls["my_courses"]; ?>">הקורסים שלי</a>
            <span class="divider">&nbsp;</span>
            <a href="<?php echo $urls["available_courses"]; ?>">חנות הקורסים</a>
          </span>
        </div>
        <div class="school-courses">
        <?php
//get all posts of type course, where post is publised
$courses = new WP_Query(["post_type" => 'course', 'posts_per_page' => -1, 'post_status' => 'publish']);

$valueQuery = new DBManager($user->ID);

$attendingCourses = [];
$availableCourses = [];

while ($courses->have_posts()) {
  //get post object of the current pod
  $post = $courses->next_post();

  if ($valueQuery->isAttending($post->ID)) {
    $attendingCourses[] = $post;
  } else {
    if (Courses::course_has_tag($post->ID, 'hidden')) {
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