<?php

use FutureLMS\classes\BaseObject;

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
  || !isset($_POST["course_id"])
  || !isset($_POST["class_id"])
  || !isset($_POST["lesson_id"])) {
  wp_redirect(home_url());
  exit;
}

$courseId = $_POST["course_id"];
$lessonId = $_POST["lesson_id"];
$course = BaseObject::factory("course", $courseId);
$lesson = BaseObject::factory("lesson", $lessonId);

$images_dir_url = plugin_dir_url(__DIR__) . "assets/images";

?>
<div
  class="school-container classroom"
  course-id="<?php echo $courseId; ?>"
  class-id="<?php echo $_POST["class_id"]; ?>"
  lesson-id="<?php echo $_POST["lesson_id"]; ?>">
  <div class="school-sidebar">
    <div class="sidebar-header">
      <label>
        <?php echo $course->raw("name"); ?>
      </label>
      <a href="#" class="exit-to-lobby show-popover pop-right" data-content="<?php _e("Exit to lobby","future-lms"); ?>">
        <img alt="<?php _e("Exit to lobby","future-lms"); ?>" src="<?php echo $images_dir_url; ?>/exit.svg" />
      </a>
      <img class="close-sidebar" alt="<?php _e("Close index","future-lms"); ?>" src="<?php echo $images_dir_url; ?>/close.svg" />
    </div>
  </div>
  <div class="lesson">
    <div class="lesson-videos">
      <div class="lesson-videos-nav">
        <a class="prev-video" href="#"><?php _e("&rarr; Previous video", "future-lms"); ?></a>
        <span class="current-lesson-title">
          <span class="lesson-title"><?php echo $lesson->raw("title"); ?></span>
          <span class="multiple-video-indication hidden"></span>
          <button class="nav-lessons show-popover" type="button" aria-label="Choose Lesson" data-content="<?php _e("Choose lesson", "future-lms"); ?>">
            <img src="<?php echo $images_dir_url; ?>/index.svg" />
          </button>
        </span>
        <a class="next-video" href="#"><?php _e("&larr; Next video", "future-lms"); ?></a>
      </div>
      <div class="video-container"></div>
    </div>
    <div class="lesson-materials">
      <span class="current-lesson-title show-no-videos">
        <span class="lesson-header">
          <span class="lesson-title"><?php echo $lesson->raw("title"); ?></span>
        </span>
        <button class="nav-lessons show-popover pop-right" type="button" aria-label="Choose Lesson" data-content="<?php _e("Choose lesson", "future-lms"); ?>">
          <img src="<?php echo $images_dir_url; ?>/index.svg" alt="<?php _e("Navigate lessons","future-lms"); ?>" />
        </button>
      </span>
      <ul class="lesson-materials-nav">
        <li class="selected" tab-id="content">מה נלמד בשיעור</li>
        <li tab-id="additional">חומרים ועזרים נלווים</li>
        <li tab-id="homework">משימות</li>
        <li tab-id="student-notes">הערות תלמיד</li>
        <li class="toggle-videos show-popover pop-right" data-content="<?php _e("Toggle videos", "future-lms"); ?>" ><img src="<?php echo $images_dir_url; ?>/toggle-up.svg" /></li>
      </ul>
      <div class="lesson-content-viewer"></div>
    </div>
  </div>
</div>
<script src="https://player.vimeo.com/api/player.js"></script>