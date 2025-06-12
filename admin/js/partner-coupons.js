class PartnerCouponsTab {
  tab = null;
  state = window.StateManagerFactory();

  constructor() {
    this.tab = COMMON.getTab(COMMON.TABS.PARTNER_COUPONS);
    this.reloadCoupons();
  }

  reloadCoupons = () => {
    JSUtils.fetch(__valueSchool.ajax_url, { action: 'get_partner_coupons' }).then(data => {
      console.log('rendering partner coupons ');
      let table = this.tab.querySelector('table.partner-coupons tbody');
      table.innerHTML = '';

      let coupons = Object.values(data);
      if (coupons.length === 0) {
        table.insertAdjacentHTML('beforeend', "<tr><td colspan='7'>No results</td></tr>");
      }

      coupons.forEach(coupon => {
        //add row
        table.insertAdjacentHTML(
          'beforeend',
          `<tr data-id=${coupon.id}>
            <td>${coupon.creation_date.split(' ')[0]}</td>
            <td class='coupon-code'>${coupon.code}</td>
            <td>${coupon.partner}</td>
            <td data-tooltip='${coupon.deal_data}'>${coupon.deal_id}</td>
            <td>${coupon.name}</td>
            <td>${coupon.email}</td>
            <td>${coupon.phone}</td>
            <td>${coupon.tpid}</td>
            <td>${coupon.used}</td>
          </tr>`
        );
      });
    });
  };
}
