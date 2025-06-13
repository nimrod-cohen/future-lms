<?php
$shopDesc = get_post_meta($post->ID, "shop_description", true);
?>
<?php if (!empty($shopDesc)) {?>
  <span class='course-short-desc'>
    <?php echo $shopDesc; ?>
    <span class='course-read-more'>
      <?php echo $shopDesc; ?>
    </span>
    <span class='read-more-arrow'></span>
  </span>
  <span class='read-more-indicator'>...</span>
  <?php }?>
