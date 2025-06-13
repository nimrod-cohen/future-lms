<h2 class="courses-header">הקורסים שלך</h2>
<div class="my-courses course-list">
<?php

use FutureLMS\FutureLMS;

if (count($attendingCourses) == 0) {
  echo "<div class='no-courses'>אינך רשום לאף קורס</div>";
}

$genericCourseIcon = FutureLMS::get_media_url_by_tag('generic-course-icon');

foreach ($attendingCourses as $post) {
  $icon = get_post_meta($post->ID, 'course_icon', true);
  if (!empty($icon)) {
    $icon = $icon;
  } else {
    $icon = $genericCourseIcon;
  }

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
          <img class='course-icon' src='<?php echo $icon ?>'></img>
          <div class="course-details">
            <span class='course-name'><?php echo $post->post_title; ?></span>
            <span class='course-author'><?php echo $author; ?></span>
            <?php include "shop_description.php";?>
            <span class='course-progress'>
              <span class='course-progress-bar'></span>
            </span>
            <span class='course-cta'>
              <form method="POST" class="course-entry-form" action="<?php echo $courseWorkspacePage ?>">
                <input type="hidden" name="course_id" value="<?php echo $post->ID; ?>">
                <input type="hidden" name="class_id" value="<?php echo $class["id"]; ?>">
                <input type="hidden" name="lesson_id" value="<?php echo $nextLessson; ?>">
                <button type='submit' class='enter-course'>המשך לקורס</button>
              </form>
            </span>
          </div>
    </div>
    <?php
}?>
</div><!-- my courses -->