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
      action: 'value_get_settings'
    }).then(data => {
      this.tab.querySelector('#zoom_app_client_id').value = data.zoom_app_client_id;
      this.tab.querySelector('#zoom_app_client_secret').value = data.zoom_app_client_secret;
      this.tab.querySelector('#zoom_account_id').value = data.zoom_account_id;
      this.tab.querySelector('#shorturls_token').value = data.shorturls_token;
      this.tab.querySelector('#shorturls_username').value = data.shorturls_username;
      this.tab.querySelector('#whatsapp_019_username').value = data.whatsapp_019_username;
      this.tab.querySelector('#whatsapp_019_token').value = data.whatsapp_019_token;
      this.tab.querySelector('#whatsapp_019_phone').value = data.whatsapp_019_phone;
    });
  };

  setSettings = e => {
    e.preventDefault();

    let data = {
      zoom_app_client_id: this.tab.querySelector('#zoom_app_client_id').value,
      zoom_app_client_secret: this.tab.querySelector('#zoom_app_client_secret').value,
      zoom_account_id: this.tab.querySelector('#zoom_account_id').value,
      shorturls_token: this.tab.querySelector('#shorturls_token').value,
      shorturls_username: this.tab.querySelector('#shorturls_username').value,
      whatsapp_019_username: this.tab.querySelector('#whatsapp_019_username').value,
      whatsapp_019_token: this.tab.querySelector('#whatsapp_019_token').value,
      whatsapp_019_phone: this.tab.querySelector('#whatsapp_019_phone').value
    };

    JSUtils.fetch(__futurelms.ajax_url, {
      action: 'value_set_settings',
      ...data
    }).then(data => {
      notifications.show(data.message, 'success');
    });
  };
}
