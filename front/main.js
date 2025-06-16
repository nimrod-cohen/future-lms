console.log('Future LMS main.js loaded');

class FutureLMS {
  //listen to javascript event FutureLMS/do_charge
  constructor() {
    document.addEventListener('FutureLMS:do_charge', e => {
      console.log('FutureLMS/do_charge event received', e.detail);
      this.doCharge(e.detail);
    });
  }

  doCharge = (atts, form = null) => {
    try {
      form = form || document;

      if (!atts || !atts.charge_url || !atts.email || !atts.phone) {
        console.error('Invalid parameters for doCharge:', atts);
        window.notifications.show('Invalid payment parameters. Please try again.', 'error');
        return;
      }

      if (!document.querySelector('.payment-popup-backdrop')) {
        //add popup background
        document.body.insertAdjacentHTML(
          'beforeend',
          "<div class='payment-popup-backdrop hidden'><div class='payment-popup'><div id='iframe-container'></div><div class='payment-popup-footer'><a href='#' class='close-payment-popup'>סגור</a></div></div>"
        );
        //add hide button event
        document.querySelector('.close-payment-popup')?.addEventListener('click', () => {
          document.querySelector('.payment-popup-backdrop')?.classList.add('hidden');
        });
      }

      let container = document.querySelector('#iframe-container');

      var url = atts.charge_url;
      var pdesc = atts.product_description || '';

      var userData = JSON.stringify({
        email: atts.email ?? '',
        name: atts.name ?? '',
        phone: atts.phone ?? '',
        promocode: atts.coupon.code ?? ''
      });

      userData = btoa(encodeURIComponent(userData));

      if (atts.coupon && atts.coupon.price) {
        url = url.replace(/sum=[\d\.]+/, `sum=${atts.coupon.price}`);
      }

      url += `${url.indexOf('?') === -1 ? '?' : '&'}user_id=${userData}&email=${encodeURIComponent(
        atts.email
      )}&product_id=${atts.course_id}&pdesc=${encodeURIComponent(pdesc)}&contact=${encodeURIComponent(atts.name)}`;

      //this is to circumvent lazy loading plugins which damage the result.
      let iframeTag = 'iframe';

      let html = '<' + iframeTag + " src='" + url + "'></" + iframeTag + '>';

      container.innerHTML = '';
      container.insertAdjacentHTML('beforeend', html);

      //show popup
      document.querySelector('.payment-popup-backdrop').classList.remove('hidden');
    } catch (error) {
      console.error('Error in FutureLMS.doCharge:', error);
      window.notifications.show('An error occurred while processing the payment. Please try again.', 'error');
    }
  };
}

JSUtils.domReady(() => {
  window.futureLMS = new FutureLMS();
});
