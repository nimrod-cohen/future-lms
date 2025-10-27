<?php
  use FutureLMS\classes\Settings;
?>
<form class="ui form">
  <h4 class="ui dividing header">TBD</h4>
  <div class="fields">
    <div class="four wide field">
      <label>Default lobby page</label>
      <select id="default_lobby_page" name="default_lobby_page">
        <option value="mycourses" default="default">My courses</option>
        <option value="courses">Course store</option>
      </select>
    </div>
    <div class="four wide field">
      <label>Store currency</label>
      <select id="store_currency" name="store_currency">
        <?php
        $currencies = Settings::CURRENCIES;
        $default_currency = Settings::get('store_currency');
        foreach ($currencies as $code => $symbol) { ?>
          <option value="<?= $code ?>" <?= $default_currency === $code ? 'selected' : '' ?>>
            <?= $symbol . ' ' . $code ?>
          </option>
        <?php } ?>
      </select>
    </div>
  </div>
  
  <h4 class="ui dividing header">WooCommerce Integration</h4>
  <div class="fields">
    <div class="field">
      <div class="ui checkbox">
        <input type="checkbox" id="auto_create_woocommerce_products" name="auto_create_woocommerce_products" value="yes" <?= Settings::get('auto_create_woocommerce_products') === 'yes' ? 'checked' : '' ?>>
        <label for="auto_create_woocommerce_products">Automatically create WooCommerce products when courses are created</label>
      </div>
      <p class="description">When enabled, a new WooCommerce product will be automatically created and linked to each new course. The product will inherit the course title, description, and pricing.</p>
    </div>
  </div>
  <div class="fields">
    <div class="field">
      <div class="ui checkbox">
        <input type="checkbox" id="auto_create_products_for_drafts" name="auto_create_products_for_drafts" value="yes" <?= Settings::get('auto_create_products_for_drafts') === 'yes' ? 'checked' : '' ?>>
        <label for="auto_create_products_for_drafts">Also create products for draft courses</label>
      </div>
      <p class="description">When enabled, products will also be created for courses that are saved as drafts.</p>
    </div>
  </div>
  
  <div class="ui divider"></div>
  <div class="fields">
    <div class="field">
      <h5>Create Products for Existing Courses</h5>
      <p class="description">Use this button to create WooCommerce products for courses that were created before enabling auto-creation.</p>
      <button id="create-products-existing" class="ui button secondary" type="button">
        <i class="sync icon"></i> Create Products for Existing Courses
      </button>
      <div id="create-products-result" class="ui message" style="display: none;"></div>
    </div>
  </div>
  
  <button id="save-settings" class="ui button primary" type="submit">Save</button>
</form>