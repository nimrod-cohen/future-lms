class SettingsTab {
  tab = null;

  constructor() {
    this.tab = COMMON.getTab(COMMON.TABS.SETTINGS);
    this.getSettings();

    document.querySelector('#save-settings').addEventListener('click', this.setSettings);
    
    // Add event listener for create products button
    const createProductsBtn = document.querySelector('#create-products-existing');
    if (createProductsBtn) {
      createProductsBtn.addEventListener('click', this.createProductsForExisting);
    }
  }

  getSettings = () => {
    console.log('getting settings');

    JSUtils.fetch(__futurelms.ajax_url, {
      action: 'future_lms_get_settings'
    }).then(data => {
      this.tab.querySelector('#default_lobby_page').value = data.default_lobby_page;
      this.tab.querySelector('#store_currency').value = data.store_currency;
      
      // WooCommerce settings
      if (data.auto_create_woocommerce_products) {
        this.tab.querySelector('#auto_create_woocommerce_products').checked = data.auto_create_woocommerce_products === 'yes';
      }
      if (data.auto_create_products_for_drafts) {
        this.tab.querySelector('#auto_create_products_for_drafts').checked = data.auto_create_products_for_drafts === 'yes';
      }
    });
  };

  setSettings = e => {
    e.preventDefault();

    let data = {
      default_lobby_page: this.tab.querySelector('#default_lobby_page').value,
      store_currency: this.tab.querySelector('#store_currency').value,
      auto_create_woocommerce_products: this.tab.querySelector('#auto_create_woocommerce_products').checked ? 'yes' : 'no',
      auto_create_products_for_drafts: this.tab.querySelector('#auto_create_products_for_drafts').checked ? 'yes' : 'no'
    };

    JSUtils.fetch(__futurelms.ajax_url, {
      action: 'future_lms_set_settings',
      ...data
    }).then(data => {
      notifications.show(data.message, 'success');
    });
  };

  createProductsForExisting = e => {
    e.preventDefault();
    
    const button = e.target;
    const resultDiv = document.querySelector('#create-products-result');
    
    // Disable button and show loading
    button.disabled = true;
    button.innerHTML = '<i class="spinner loading icon"></i> Creating Products...';
    
    JSUtils.fetch(__futurelms.ajax_url, {
      action: 'future_lms_create_products_existing'
    }).then(data => {
      if (data.success) {
        resultDiv.className = 'ui message success';
        resultDiv.innerHTML = `<i class="check circle icon"></i> ${data.data}`;
      } else {
        resultDiv.className = 'ui message error';
        resultDiv.innerHTML = `<i class="exclamation triangle icon"></i> ${data.data}`;
      }
      resultDiv.style.display = 'block';
    }).catch(error => {
      resultDiv.className = 'ui message error';
      resultDiv.innerHTML = `<i class="exclamation triangle icon"></i> Error: ${error.message}`;
      resultDiv.style.display = 'block';
    }).finally(() => {
      // Re-enable button
      button.disabled = false;
      button.innerHTML = '<i class="sync icon"></i> Create Products for Existing Courses';
    });
  };
}
