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
    }).then(data => {});
  };

  setSettings = e => {
    e.preventDefault();

    let data = {};

    JSUtils.fetch(__futurelms.ajax_url, {
      action: 'value_set_settings',
      ...data
    }).then(data => {
      notifications.show(data.message, 'success');
    });
  };
}
