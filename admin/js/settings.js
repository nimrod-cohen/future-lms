class SettingsTab {
  tab = null;

  constructor() {
    this.tab = COMMON.getTab(COMMON.TABS.SETTNGS);
    this.getSettings();

    document.querySelector('#save-settings').addEventListener('click', this.setSettings);
  }

  getSettings = () => {
    console.log('getting settings');

    JSUtils.fetch(__futurelms.ajax_url, {
      action: 'future_lms_get_settings'
    }).then(data => {
      this.tab.querySelector('#default_lobby_page').value = data.default_lobby_page;
      this.tab.querySelector('#store_currency').value = data.store_currency;
    });
  };

  setSettings = e => {
    e.preventDefault();

    let data = {
      default_lobby_page: this.tab.querySelector('#default_lobby_page').value,
      store_currency: this.tab.querySelector('#store_currency').value
    };

    JSUtils.fetch(__futurelms.ajax_url, {
      action: 'future_lms_set_settings',
      ...data
    }).then(data => {
      notifications.show(data.message, 'success');
    });
  };
}
