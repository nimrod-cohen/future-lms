<?php

// Get course details and present them as a sales page
$courseTitle = $course->display("name");
$courseDescription = $course->display("short_description");
$coursePrice = $course->display("full_price");
$chargeUrl = $course->field("charge_url");
$chargeUrl = add_query_arg(['sum' => $coursePrice], $chargeUrl); //will replace if exists.

$author = get_the_author_meta('display_name', $course->post_author);
$courseImage = $course->get_featured_image('full');

$user = wp_get_current_user();
?>
<div class="course-details">
  <div class="course-details">
    <img class='course-image' src='<?php echo $courseImage; ?>'></img>
    <span class='course-name'><?php echo $courseTitle; ?></span>
    <span class='course-author'><?php echo $author; ?></span>
    <span class='course-short-desc'>
      <?php echo apply_filters('future-lms/pre_course_description', '', [$courseId]); ?>
      <?php echo $courseDescription; ?>
      <?php echo apply_filters('future-lms/post_course_description', '', [$courseId]); ?>
    </span>
    <?php get_template_part('webparts/price_box', null, [
      'course' => $course
    ]); ?>
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