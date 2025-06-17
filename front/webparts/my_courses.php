<h2 class="courses-header">הקורסים שלך</h2>
<div class="my-courses course-list">
<?php

use FutureLMS\FutureLMS;

if (count($attendingCourses) == 0) {
  echo "<div class='no-courses'>".__("You are not registered to any course", "future-lms")."</div>";
}

foreach ($attendingCourses as $post) {
  $image = FutureLMS::get_course_image($post->ID, 'full');

  $courseUrl = $courses->meta("course_page_url");
  $author = get_the_author_meta('display_name', $post->post_author);

  $class = $valueQuery->getClass($post->ID);

  $lessons = $valueQuery->getClassLessonsByCourse($post->ID);
  $nextLessson = null;
  if (is_array($lessons) && count($lessons) > 0) {
    $nextLessson = $lessons[0]["id"];
  }
  ?>
    <div class="course-card" data-course-id='<?php echo $post->ID; ?>'>
          <img class='course-image' src='<?php echo $image ?>'></img>
          <div class="course-details">
            <span class='course-name'><?php echo $post->post_title; ?></span>
            <span class='course-author'><?php echo $author; ?></span>
            <?php include "short_description.php";?>
            <span class='course-progress'>
              <span class='course-progress-bar'></span>
            </span>
            <span class='course-cta'>
              <form method="POST" class="course-entry-form" action="<?php echo $courseWorkspacePage ?>">
                <input type="hidden" name="course_id" value="<?php echo $post->ID; ?>">
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