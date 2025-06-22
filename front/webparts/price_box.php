<?php 
  use FutureLMS\classes\Settings;

  $fmt = $course->get_formatter();
  $full_price = $course->field("full_price") ?? 0;
  $currency = Settings::get('store_currency'); 
  $full_price_txt = $fmt->formatCurrency($full_price, $currency);

  $discount_price = $course->field("discount_price") ?? 0;
  if(!empty($discount_price)) {
    $discount_price = floatval($discount_price);
  }

  $final_price = $discount_price ?? $full_price;

  $discount_price_txt = '';
  $installment_txt = '';
  $payments = 1;
  if(!empty($discount_price)) {
    $discount_price_txt = $fmt->formatCurrency($discount_price, $currency);
    $payments = isset($args["payments"]) ? intval($args["payments"]) : 1;
    $payments_installment = $final_price / $payments;
    $installment_txt = $fmt->formatCurrency($payments_installment, $currency);
  }
?>
<span class='course-price' style="display: grid; grid-template-areas:'line-1' 'line-2'; gap:8px;align-items:center; width:100%;">
  <div style='grid-area:line-1; display:flex; align-items:center; gap:8px; width:100%'>
    <?php if(!empty($discount_price_txt)) { ?>
      <span style='font-weight:bold;font-weight:bold;font-size:1.6rem'>
        <?php echo $discount_price_txt; ?>
      </span>
      <span style='text-decoration:line-through;font-weight:lighter;color:#333;'><?php echo $full_price_txt; ?></span>
      <span style='flex-grow:1; display:flex; align-items:center; justify-content:flex-end'>
        <span style='font-size:0.8rem; font-weight:normal; background-color:#fee2e2;padding:3px 6px;color:#991b1b;border-radius:4px;'>
        <?php echo sprintf(__("%s discount", "future-lms"), (round((1 - ($discount_price / $full_price)) * 100))."%"); ?>
        </span>
      </span>
    <?php } else { ?>
      <span style='font-weight:bold;font-size:1.2rem'><?php echo $full_price_txt; ?></span>
    <?php } ?>
  </div>
    <div style='grid-area:line-2'>
    <?php if($payments > 1) { ?>
      <span style="font-size:0.9rem"><?php echo sprintf(__("%d payments of %s", "future-lms"), $payments, $installment_txt); ?></span>
    <?php } ?>
    </div>
</span>