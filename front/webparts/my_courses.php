<h2 class="courses-header"><?php echo _e("My courses","future-lms"); ?></h2>
<div class="my-courses course-list">
<?php

if (count($attending_courses) == 0) {
  echo "<div class='no-courses'>".__("You are not registered to any course", "future-lms")."</div>";
}

foreach ($attending_courses as $course) {
  $image = $course->get_featured_image('full');

  $courseUrl = $course->raw("course_page_url");
  $author = get_the_author_meta('display_name', $course->raw("post_author"));

  $class = $student->get_class($course->raw("ID"));

  $lessons = $student->get_class_lessons_by_course_id($course->raw("ID"));
  $nextLessson = null;
  if (is_array($lessons) && count($lessons) > 0) {
    $nextLessson = $lessons[0]["id"];
  }
  ?>
    <div class="course-card" data-course-id='<?php echo $course->raw("ID"); ?>'>
          <img class='course-image' src='<?php echo $image ?>'></img>
          <div class="course-details">
            <span class='course-name'><?php echo $course->raw("name"); ?></span>
            <span class='course-author'><?php echo $author; ?></span>
            <?php include "short_description.php";?>
            <span class='course-progress'>
              <span class='course-progress-bar'></span>
            </span>
            <span class='course-cta'>
              <form method="POST" class="course-entry-form">
                <input type="hidden" name="course_id" value="<?php echo $course->raw("ID"); ?>">
                <input type="hidden" name="class_id" value="<?php echo $class["id"]; ?>">
                <input type="hidden" name="lesson_id" value="<?php echo $nextLessson; ?>">
                <button type='submit' class='enter-course'><?php _e("Enter course", "future-lms"); ?></button>
              </form>
            </span>
          </div>
    </div>
    <?php
}?>
</div><!-- my courses -->