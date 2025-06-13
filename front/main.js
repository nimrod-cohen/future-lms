window['doCharge'] = (atts, form) => {
  form = form || document;

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

  var url = form.querySelector('#charge-url').value;
  var pdesc = form.querySelector('#course-description').value;

  var userData = JSON.stringify({
    email: atts.email,
    name: atts.name,
    phone: atts.phone,
    promocode: atts.coupon && atts.coupon.code
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
};

JSUtils.domReady(() => {
  const emailValidation = async e => {
    let email = e.target.value;

    let field = e.target.closest('.forminator-field');
    let submitButton = document.querySelector('.forminator-button.forminator-button-submit');
    submitButton.removeAttribute('disabled');

    if (await JSUtils.validateEmailAddress(email)) {
      console.log('legal');
      submitButton.removeAttribute('disabled');
      field.classList.remove('forminator-has_error');
      let msg = field.querySelector('.forminator-error-message');
      if (msg) field.removeChild(msg);
    } else {
      console.log('illegal');
      submitButton.setAttribute('disabled', true);
      field.classList.add('forminator-has_error');
      let msg = field.querySelector('.forminator-error-message');
      if (!msg) {
        field.insertAdjacentHTML(
          'beforeend',
          '<span class="forminator-error-message" aria-hidden="true">כתובת האימייל אינה חוקית</span>'
        );
      }
    }
  };

  let emailField = document.querySelector('.forminator-field input[type=email]');
  if (emailField) {
    emailField.addEventListener('focusout', emailValidation);
    emailField.addEventListener('change', emailValidation);
  }
});

//keep the user's traffic source
var trafficSource = sessionStorage.getItem('traffic_source');
if (!trafficSource) {
  const searchParams = new URLSearchParams(window.location.search);
  trafficSource = {
    source: 'direct',
    medium: 'direct'
  };
  if (searchParams.has('utm_source')) {
    trafficSource.source = 'paid';
    trafficSource.medium = searchParams.get('utm_source');
  } else if (document.referrer) {
    const referrer = new URL(document.referrer);
    trafficSource.source = 'organic';
    trafficSource.medium = referrer.host;
  }
  sessionStorage.setItem('traffic_source', JSON.stringify(trafficSource));
} else {
  trafficSource = JSON.parse(trafficSource);
}

//add source and medium to forminator custom forms
JSUtils.domReady(() => {
  let sourceField = document.querySelector(
    'form.forminator-ui.forminator-custom-form .forminator-row .utm_source input'
  );
  if (!sourceField) return;
  sourceField.value = trafficSource.source;
  //find parent dom element .forminator-row
  sourceField.closest('.forminator-row').style.display = 'none';

  let mediumField = document.querySelector(
    'form.forminator-ui.forminator-custom-form .forminator-row .utm_medium input'
  );
  mediumField.value = trafficSource.medium;
  mediumField.closest('.forminator-row').style.display = 'none';
});
