<?php

use FutureLMS\FutureLMS;

$courseId = $_POST["course_id"];
$genericCourseIcon = FutureLMS::get_media_url_by_tag('generic-course-icon');

// Get course details and present them as a sales page
$course = get_post($courseId);
$courseTitle = $course->post_title;
$courseDescription = get_post_meta($courseId, "short_description", true);
$coursePrice = get_post_meta($courseId, "full_price", true);
$chargeUrl = get_post_meta($courseId, "charge_url", true);
$chargeUrl = add_query_arg(['sum' => $coursePrice], $chargeUrl); //will replace if exists.

$fmt = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
$coursePrice = $fmt->formatCurrency($coursePrice, "ILS");

$author = get_the_author_meta('display_name', $course->post_author);
$courseIcon = get_post_meta($courseId, 'course_icon', true);
$courseIconUrl = !empty($courseIcon) ? $courseIcon["guid"] : $genericCourseIcon;
$user = wp_get_current_user();
?>
<div class="course-details">
  <div class="course-details">
    <img class='course-icon' src='<?php echo $courseIconUrl; ?>'></img>
    <span class='course-name'><?php echo $courseTitle; ?></span>
    <span class='course-author'><?php echo $author; ?></span>
    <span class='course-short-desc'>
      <?php echo apply_filters('future-lms/pre_course_description', '', [$courseId]); ?>
      <?php echo $courseDescription; ?>
      <?php echo apply_filters('future-lms/post_course_description', '', [$courseId]); ?>
    </span>
    <span class='course-price'>מחיר: <?php echo $coursePrice; ?></span>
    <form id="buy-course-form" method="POST" action=#">
      <input type='hidden' id="course-description" value="<?php echo $courseTitle; ?>">
      <input type="hidden" name="charge-url" id="charge-url" value="<?php echo $chargeUrl; ?>">
      <button class='buy-now'>קנה עכשיו</button>
    </form>
  </div>
  <div class="course-content">
    <?php echo $course->post_content; ?>
  </div>
  <script>
    JSUtils.domReady(()=>{
      document.querySelector('#buy-course-form .buy-now').addEventListener('click', (e) => {
        e.preventDefault();

        const form = document.getElementById('buy-course-form');

        window.doCharge({
          email: '<?php echo $user->user_email; ?>',
          name: '<?php echo $user->display_name; ?>',
          phone: '<?php echo get_user_meta($user->ID, 'user_phone', true); ?>',
          course_id:  '<?php echo $courseId; ?>',
        }, form);
      });
    });
  </script>