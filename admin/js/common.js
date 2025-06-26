const COMMON = {
  TABS: {
    CLASSES: 'classes',
    COURSES: 'courses',
    STUDENTS: 'students',
    MAILER: 'mailer',
    BILLING: 'billing',
    COUPONS: 'coupons',
    SETTNGS: 'settings'
  },

  htmlRTL: str => {
    return str.replace(
      /<([a-z][a-z0-9]*)(?:\s+(.*?)(?:style=[\"\'](.*?)[\"\'])(.*?))?>/g,
      "<$1 align='right' dir='rtl' $2 style='text-align:right; direction:rtl;$3' $4>"
    );
  },

  removeNonAsciiChars(str) {
    return str.replace(/[^\x20-\x7E]/g, '');
  },

  //get a query selector of current tab
  getTab: tab => {
    tab = tab || this.currentTab;
    return document.querySelector(COMMON.getTabSelector(tab));
  },

  getTabSelector: tab => {
    return `.tab[data-tab='${tab}']`;
  },

  wireDropdown: (selector, values, onChange, placeholder = '', defaultValue, forceReload = false) => {
    if (forceReload) {
      jQuery(selector).dropdown('destroy');
    }

    jQuery(selector).dropdown({
      clearable: true,
      placeholder: placeholder,
      onChange: function (value) {
        jQuery(this).attr('data-id', value);
        onChange(value);
      },
      values: values
    });

    if (defaultValue !== undefined) {

      console.log(defaultValue)
      jQuery(selector).dropdown('set selected', defaultValue);
    }
  },

  //selector - css selector of input to wire
  //onSelect - extends what we do during item selection
  //addData - extends what being sent to server query
  //action - server query name
  wireSearch: (selector, action, onSelect, addData, cbCancel) => {
    jQuery(selector).search({
      minCharacters: 0,
      cache: false, //careful, if you cache you sometimes nedd to call .search('clear cache')
      onSelect: function (item) {
        jQuery(this).attr('data-id', item.id);
        onSelect(item);
      },
      apiSettings: {
        url: __futurelms.ajax_url,
        method: 'POST',
        beforeSend: function (settings) {
          if (cbCancel && cbCancel()) return false;
          settings.data.search = jQuery(this).find('input').val();
          settings.data = { ...settings.data, ...addData() };
          return settings;
        },
        data: {
          action: action
        }
      }
    });
  },

  showLoader: () => {
    const loader = document.querySelector('.action-spinner');

    if (!loader) {
      console.error('Loader element not found');
      return;
    }

    loader.style.position = 'fixed';
    loader.style.top = '50%';
    loader.style.left = '50%';
    loader.style.transform = 'translate(-50%, -50%)';

    loader.style.display = 'block';
    document.body.style.pointerEvents = 'none';
  },

  hideLoader: () => {
    const loader = document.querySelector('.action-spinner');

    if (loader) {
      loader.style.display = 'none';
      document.body.style.pointerEvents = 'auto';
    }
  }
};
