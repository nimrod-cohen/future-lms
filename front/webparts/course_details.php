<?php

// Get course details and present them as a sales page

use FutureLMS\FutureLMS;
use FutureLMS\woocommerce\WCIntegration;

$courseId = intval($course->raw('ID'));
$courseTitle = $course->display("name");
$courseDescription = $course->display("short_description");
$coursePrice = $course->display("full_price");

$author = get_the_author_meta('display_name', $course->post_author);
$courseImage = $course->get_featured_image('full');

$user = wp_get_current_user();

$wcProductId = WCIntegration::get_linked_product_for_course($courseId);
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
    <?php FutureLMS::get_template_part('price_box.php', ['course' => $course]); ?>
    <?php if (!empty($wcProductId)) { ?>
      <form class="cart" action="<?php echo esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', get_permalink() ) ); ?>" method="post" enctype="multipart/form-data">
        <button type="submit" name="add-to-cart" value="<?php echo esc_attr($wcProductId); ?>" class='buy-now button'>
          <?php _e('קנה עכשיו','future-lms'); ?>
        </button>
      </form>
    <?php } else { ?>
      <button class='buy-now' disabled><?php _e('Not available','future-lms'); ?></button>
    <?php } ?>
  </div>
  <div class="course-content">
    <?php echo $course->post_content; ?>
  </div>
  