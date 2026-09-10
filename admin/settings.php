<?php
  use FutureLMS\classes\Settings;
?>
<form class="ui form">
  <h4 class="ui dividing header">General</h4>
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
    <div class="four wide field">
      <label>Default course image</label>
      <div class="flms-image-picker">
        <input type="hidden" id="default_course_image" name="default_course_image" value="0" />
        <div class="flms-image-picker-filled">
          <img class="flms-image-picker-preview" alt="" />
          <div class="flms-image-picker-actions">
            <button type="button" class="select-default-course-image">Replace</button>
            <button type="button" class="remove-default-course-image">Remove</button>
          </div>
        </div>
        <div class="flms-image-picker-empty">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="3" y="4" width="18" height="16" rx="2"></rect>
            <circle cx="8.5" cy="9.5" r="1.5"></circle>
            <path d="M21 15l-5-5L5 20"></path>
          </svg>
          <button type="button" class="ui tiny button select-default-course-image">Select image</button>
          <span class="flms-image-picker-hint">Falls back to the bundled image</span>
        </div>
      </div>
    </div>
  </div>

  <button id="save-settings" class="ui button primary" type="submit">Save</button>
</form>