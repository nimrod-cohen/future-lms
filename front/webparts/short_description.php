<?php
$short_desc = get_post_meta($course->raw("ID"), "short_description", true);
?>
<?php if (!empty($short_desc)) {?>
  <span class='course-short-desc'>
    <?php echo $short_desc; ?>
    <span class='course-read-more'>
      <?php echo $short_desc; ?>
    </span>
    <span class='read-more-arrow'></span>
  </span>
  <span class='read-more-indicator'>...</span>
  <?php }?>
