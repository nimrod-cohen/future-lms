<?php
  use FutureLMS\classes\Settings;
?>
<div class="flms-page-head">
  <div>
    <h2 class="flms-page-title">Settings</h2>
    <p class="flms-page-desc">Defaults for the school's student area and store.</p>
  </div>
</div>

<form class="flms-card max-w-3xl settings-form" onsubmit="return false">
  <div class="p-4 sm:p-5 grid gap-5 sm:grid-cols-2">
    <div>
      <label class="flms-label" for="default_lobby_page">Default lobby page</label>
      <select id="default_lobby_page" name="default_lobby_page" class="flms-select">
        <option value="mycourses">My courses</option>
        <option value="courses">Course store</option>
      </select>
      <p class="flms-hint">What students see first in the student area.</p>
    </div>
    <div>
      <label class="flms-label" for="store_currency">Store currency</label>
      <select id="store_currency" name="store_currency" class="flms-select">
        <?php
        $default_currency = Settings::get('store_currency');
        foreach (Settings::CURRENCIES as $code => $symbol) { ?>
          <option value="<?php echo esc_attr($code); ?>" <?php selected($default_currency, $code); ?>><?php echo esc_html($symbol . ' ' . $code); ?></option>
        <?php } ?>
      </select>
    </div>
    <div class="sm:col-span-2">
      <span class="flms-label">Default course image</span>
      <div class="flms-image-picker settings-image-picker">
        <input type="hidden" id="default_course_image" name="default_course_image" value="0" />
        <div class="flms-image-picker-filled">
          <img class="flms-image-picker-preview" alt="" />
          <div class="flms-image-picker-actions">
            <button type="button" class="flms-btn flms-btn-sm select-default-course-image">Replace</button>
            <button type="button" class="flms-btn flms-btn-sm flms-btn-danger remove-default-course-image">Remove</button>
          </div>
        </div>
        <div class="flms-image-picker-empty">
          <i data-lucide="image" aria-hidden="true"></i>
          <button type="button" class="flms-btn flms-btn-sm select-default-course-image">Select image</button>
          <span class="flms-image-picker-hint">Courses without their own image use this. Empty: the bundled image.</span>
        </div>
      </div>
    </div>
  </div>
  <div class="flex justify-end px-4 sm:px-5 py-3 border-t border-slate-200 bg-slate-50 rounded-b-xl">
    <button id="save-settings" class="flms-btn flms-btn-primary" type="submit">
      <i data-lucide="check" aria-hidden="true"></i>Save settings
    </button>
  </div>
</form>
