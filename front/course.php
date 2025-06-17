<?php

use FutureLMS\classes\PodsWrapper;

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
  || !isset($_POST["course_id"])
  || !isset($_POST["class_id"])
  || !isset($_POST["lesson_id"])) {
  wp_redirect(home_url());
  exit;
}

$courseId = $_POST["course_id"];
$lessonId = $_POST["lesson_id"];
$course = PodsWrapper::factory("course", $courseId);
$lesson = PodsWrapper::factory("lesson", $lessonId);

$images_dir_url = plugin_dir_url(__DIR__) . "assets/images";

?>
<div
  class="school-container classroom"
  course-id="<?php echo $courseId; ?>"
  class-id="<?php echo $_POST["class_id"]; ?>"
  lesson-id="<?php echo $_POST["lesson_id"]; ?>">
  <div class="school-sidebar">
    <div class="sidebar-header">
      <button class="nav-lessons" type="button" aria-label="Choose Lesson">
        <img src="<?php echo $images_dir_url; ?>/index.svg" />
      </button>
      <?php echo $course->field("name"); ?>
      <img class="close-sidebar" src="<?php echo $images_dir_url; ?>/close.svg" />
    </div>
  </div>
  <div class="lesson">
    <div class="lesson-videos">
      <div class="lesson-videos-nav">
        <a class="prev-video" href="#">&rarr; לסרטון הקודם</a>
        <span id="current-lesson-title">
          <span class="lesson-title"><?php echo $lesson->raw("title"); ?></span>
          <span class="multiple-video-indication hidden"></span>
        </span>
        <a class="next-video" href="#">לסרטון הבא &larr;</a>
      </div>
      <div class="video-container"></div>
    </div>
    <div class="lesson-materials">
      <span class="current-lesson-title show-no-videos">
        <span class="lesson-title"><?php echo $lesson->raw("title"); ?></span>
      </span>
      <ul class="lesson-materials-nav">
        <li class="selected" tab-id="content">מה נלמד בשיעור</li>
        <li tab-id="additional">חומרים ועזרים נלווים</li>
        <li tab-id="homework">משימות</li>
        <li tab-id="student-notes">הערות תלמיד</li>
        <li class="toggle-videos"><img src="<?php echo $images_dir_url; ?>/toggle-up.svg" /></li>
      </ul>
      <div class="lesson-content-viewer"></div>
    </div>
  </div>
</div>
<script src="https://player.vimeo.com/api/player.js"></script>