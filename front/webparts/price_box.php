<?php 
  $full_price = $course->field("full_price") ?? 0;
  $full_price_txt = $course->formatter->formatCurrency($full_price, get_option('future-lms_currency', 'ILS'));

  $discount_price = $course->field("discount_price") ?? 0;
  if(!empty($discount_price)) {
    $discount_price = floatval($discount_price);
  }

  $discount_price_txt = '';
  if(!empty($discount_price)) {
    $discount_price_txt = $course->formatter->formatCurrency($discount_price, get_option('future-lms_currency', 'ILS'));
  }
  $payments = isset($args["payments"]) ? intval($args["payments"]) : 1;
?>
<span class='course-price'>
  <span style='text-decoration:line-through; margin:0 8px;'><?php echo $full_price_txt; ?></span>
  <span style='font-weight:bold'><?php echo $discount_price_txt; ?></span>
  <span style=''><?php echo _x("%d payments", "future-lms",$payments); ?></span>
</span>