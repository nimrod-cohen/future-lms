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
      <div class="default-course-image-picker">
        <input type="hidden" id="default_course_image" name="default_course_image" value="0" />
        <div class="ui small image default-course-image-preview"></div>
        <button type="button" class="ui tiny button select-default-course-image">Select image</button>
        <button type="button" class="ui tiny button remove-default-course-image" style="display: none;">Remove</button>
      </div>
    </div>
  </div>

  <button id="save-settings" class="ui button primary" type="submit">Save</button>
</form>