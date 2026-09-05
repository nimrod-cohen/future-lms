class SettingsTab {
  tab = null;

  constructor() {
    this.tab = COMMON.getTab(COMMON.TABS.SETTINGS);
    this.getSettings();

    document.querySelector('#save-settings').addEventListener('click', this.setSettings);

    this.initDefaultImagePicker();
  }

  getSettings = () => {
    console.log('getting settings');

    JSUtils.fetch(__futurelms.ajax_url, {
      action: 'future_lms_get_settings'
    }).then(data => {
      this.tab.querySelector('#default_lobby_page').value = data.default_lobby_page;
      this.tab.querySelector('#store_currency').value = data.store_currency;
      this.setDefaultImage(data.default_course_image || 0, data.default_course_image_url || '');
    });
  };

  setSettings = e => {
    e.preventDefault();

    let data = {
      default_lobby_page: this.tab.querySelector('#default_lobby_page').value,
      store_currency: this.tab.querySelector('#store_currency').value,
      default_course_image: this.tab.querySelector('#default_course_image').value
    };

    JSUtils.fetch(__futurelms.ajax_url, {
      action: 'future_lms_set_settings',
      ...data
    }).then(data => {
      notifications.show(data.message, 'success');
    });
  };

  // Shows an attachment in the picker, or clears it when there is none. Courses
  // without an image of their own fall back to whatever sits here; leaving it
  // empty falls back to the image bundled with the plugin.
  setDefaultImage = (id, url) => {
    const input = this.tab.querySelector('#default_course_image');
    const preview = this.tab.querySelector('.default-course-image-preview');
    const removeBtn = this.tab.querySelector('.remove-default-course-image');

    input.value = id || 0;
    preview.innerHTML = url ? `<img src='${url}' />` : '';
    removeBtn.style.display = url ? 'inline-block' : 'none';
  };

  initDefaultImagePicker = () => {
    if (!window.wp || !window.wp.media) return;

    this.tab.querySelector('.select-default-course-image').addEventListener('click', () => {
      const frame = wp.media({
        title: 'Select Default Course Image',
        multiple: false,
        library: { type: 'image' }
      });

      frame.on('select', () => {
        const attachment = frame.state().get('selection').first().toJSON();
        this.setDefaultImage(attachment.id, attachment.url);
      });

      frame.open();
    });

    this.tab.querySelector('.remove-default-course-image').addEventListener('click', () => {
      this.setDefaultImage(0, '');
    });
  };
}
