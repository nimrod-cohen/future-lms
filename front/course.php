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

$seconds = (int) get_post_meta($courseId, 'course_counted_duration', true);
$minutes = floor($seconds / 60);
$remaining_seconds = $seconds % 60;
$hours = floor($minutes / 60);
$minutes = $minutes % 60;

$formatted_duration = '';
if ($hours > 0) {
  $formatted_duration = ($minutes > 0 || $remaining_seconds > 0)
  ? sprintf('%d ש׳ %d דק׳ %d שנ׳', $hours, $minutes, $remaining_seconds)
  : sprintf('%d ש׳', $hours);
} else if ($minutes > 0) {
  $formatted_duration = ($remaining_seconds > 0)
  ? sprintf('%d דק׳ %d שנ׳', $minutes, $remaining_seconds)
  : sprintf('%d דק׳', $minutes);
} else if ($seconds > 0) {
  $formatted_duration = sprintf('%d שנ׳', $seconds);
}

?>
<div
  class="school-container classroom"
  course-id="<?php echo $courseId; ?>"
  class-id="<?php echo $_POST["class_id"]; ?>"
  lesson-id="<?php echo $_POST["lesson_id"]; ?>">
  <div class="school-sidebar">
    <div class="sidebar-header">
      <span class="course-name">
        <?php echo esc_html($course->raw("name")); ?>
        <?php if (!empty($formatted_duration)) { ?>
          &nbsp;
          <span dir="rtl" class="course-duration">
            <?php echo esc_html($formatted_duration); ?>
          </span>
        <?php } ?>
      </span>
      <img class="course-options" src="<?php echo $images_dir_url; ?>/settings.svg" />
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