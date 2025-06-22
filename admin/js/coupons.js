class CouponsTab {
  tab = null;
  state = window.StateManagerFactory();

  constructor() {
    this.tab = COMMON.getTab(COMMON.TABS.COUPONS);
    this.state.set('current_coupon', null);
    this.state.listen('current_coupon', this.render);

    JSUtils.fetch(__futurelms.ajax_url, { action: 'get_all_courses' }).then(data => {
      const coursesNameValue = Object.keys(data.courses).map(cid => {
        let course = data.courses[cid];
        return { name: course.name, value: cid };
      });

      COMMON.wireDropdown(this.tab.querySelector('.ui.search.courses'), coursesNameValue, () => {}, 'Select course');

      //cleanup errors on focus, must be here
      //because courses turns into an input only after the dropdown is generated.
      let inputs = this.tab.querySelectorAll('input');
      inputs.forEach(input => {
        input.addEventListener('focus', e => e.target.closest('.field').classList.remove('error'));
      });
    });

    this.tab
      .querySelector('.global-coupon input[type=checkbox]')
      .addEventListener('change', e =>
        this.tab.querySelector('[name=email]').closest('.field').classList.toggle('disabled')
      );

    jQuery(this.tab.querySelector('.expiry')).calendar({
      type: 'date',
      formatter: {
        date: date => {
          if (!date) return '';
          var day = date.getDate();
          var month = date.getMonth() + 1;
          var year = date.getFullYear();
          return year + '-' + month.toString().padStart(2, '0') + '-' + day.toString().padStart(2, '0');
        }
      }
    });

    this.tab.querySelector('button.cancel-edit').addEventListener('click', e => {
      e.preventDefault();
      this.state.set('current_coupon', null);
      this.tab.querySelector('.coupon-result').innerText = '';
    });

    //create coupon
    this.tab.querySelector('button.send').addEventListener('click', e => {
      e.preventDefault();
      let coupon = this.state.get('current_coupon');

      let coursesField = this.tab.querySelector('.courses').closest('.field');
      let emailField = this.tab.querySelector('[name=email]').closest('.field');
      let priceField = this.tab.querySelector('[name=price]').closest('.field');
      let expiryField = this.tab.querySelector('.expiry').closest('.field');
      let codeField = this.tab.querySelector('[name=code]').closest('.field');
      let commentField = this.tab.querySelector('[name=comment]').closest('.field');

      //cleanup errors
      coursesField.classList.remove('error');
      emailField.classList.remove('error');
      priceField.classList.remove('error');
      expiryField.classList.remove('error');
      codeField.classList.remove('error');

      let values = {
        course: coursesField.querySelector('.courses').getAttribute('data-id'),
        code: codeField.querySelector('input').value.toUpperCase().trim(),
        email: emailField.querySelector('input').value,
        price: priceField.querySelector('input').value,
        global: this.tab.querySelector('.global-coupon input[type=checkbox]').checked,
        expires: expiryField.querySelector('input').value,
        comment: commentField.querySelector('input').value
      };

      values.email = values.global ? '' : values.email;

      let failed = false;
      if (!values.course) {
        coursesField.classList.add('error');
        failed = true;
      }

      if (!values.code) {
        codeField.classList.add('error');
        failed = true;
      }

      if (!values.expires) {
        expiryField.classList.add('error');
        failed = true;
      }

      if (!values.global && (!values.email || !/.+@.+\..+/.test(values.email))) {
        emailField.classList.add('error');
        failed = true;
      }

      //minimum 10 to pass charging
      if (!values.price || values.price < 10) {
        priceField.classList.add('error');
        failed = true;
      }

      if (failed) return;

      if (coupon) values.coupon_id = coupon.id;

      values.email = encodeURIComponent(values.email);

      JSUtils.fetch(__futurelms.ajax_url, {
        action: 'save_coupon',
        ...values
      }).then(data => {
        let txt = this.tab.querySelector('.coupon-result');
        if (data.error) {
          window.notifications.show(data.message, 'error');
          txt.classList.add('red');
          txt.innerHTML = data.message;
        } else {
          window.notifications.show(data.message, 'success');
          txt.classList.remove('red');
          txt.innerHTML = data.message;
          this.state.set('current_coupon', null);
          this.reloadCoupons();
        }
      });
    });

    this.reloadCoupons();
  }

  deleteCoupon = id => {
    JSUtils.fetch(__futurelms.ajax_url, { action: 'delete_coupon', coupon_id: id }).then(data => {
      this.reloadCoupons();
    });
  };

  render = () => {
    let form = document.querySelector('.coupon-editor');
    let coupon = this.state.get('current_coupon');
    console.dir(coupon);
    let header = form.querySelector('h4.header');
    header.innerText = !coupon ? 'Create Coupon' : 'Edit Coupon';

    let courses = form.querySelector('.dropdown.courses');
    let text = courses.querySelector(':scope > div.text');
    let email = form.querySelector(".field input[name='email']");
    if (coupon) {
      courses.setAttribute('data-id', coupon.course_id);

      text.classList.remove('default');
      text.innerText = courses.querySelector(`.menu .item[data-value='${coupon.course_id}']`).innerText;
      email.value = coupon.email;

      if (coupon.global === '1') email.parentNode.classList.add('disabled');
      else email.parentNode.classList.remove('disabled');
      form.querySelector('button.cancel-edit').classList.remove('hidden');
    } else {
      courses.removeAttribute('data-id');

      text.classList.add('default');
      text.innerText = '';

      email.value = '';
      email.parentNode.classList.remove('disabled');
      form.querySelector('button.cancel-edit').classList.add('hidden');
    }

    form.querySelector(".calendar input[type='text']").value = coupon?.expires || '';
    form.querySelector('.checkbox.global-coupon input').checked = coupon && coupon.global === '1';
    form.querySelector(".field input[name='price']").value = coupon?.price || '';
    form.querySelector(".field input[name='code']").value = coupon?.code || '';
    form.querySelector(".field input[name='comment']").value = coupon?.comment || '';

    form.querySelector('button.send').innerText = !coupon ? 'Create coupon' : 'Update coupon';
  };

  reloadCoupons = () => {
    JSUtils.fetch(__futurelms.ajax_url, { action: 'get_coupons' }).then(data => {
      console.log('rendering coupons ');
      let table = this.tab.querySelector('table.coupons tbody');
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
            <td class='coupon-code'>${coupon.code}</td><td>${coupon.course}</td><td>${
            coupon.global === '1' ? 'YES' : 'NO'
          }</td><td>${coupon.email}</td><td>${coupon.price}</td><td>${coupon.expires}</td>
            <td>
              <span data-inverted='' data-position='top right' data-tooltip='Remove coupon'><i class="minus red circle icon clickable"></i></span>
              <span data-inverted='' data-position='top right' data-tooltip='Edit coupon'><i class="edit blue circle icon clickable"></i></span>
            </td>
          </tr>`
        );

        if (coupon?.comment?.length) {
          table
            .querySelector(`tr[data-id='${coupon.id}'] td.coupon-code`)
            .insertAdjacentHTML(
              'beforeend',
              `<i class="icon info circle jsutils-popover" data-content="${coupon.comment}" data-variation="mini"/>`
            );
        }

        //attach removal event
        let td = table.querySelector(`tr[data-id='${coupon.id}'] td:last-child`);
        let minus = td.querySelector('i.minus');
        let edit = td.querySelector('i.edit');

        const editCouponClick = e => {
          console.log(`editing coupon ${coupon.code}, id ${coupon.id}`);
          this.state.set('current_coupon', coupon);
          this.tab.querySelector('.coupon-result').innerText = '';
          this.render();
        };

        edit.addEventListener('click', editCouponClick);

        const delCouponClick = e => {
          remodaler.show({
            title: `Remove coupon ${coupon.code}`,
            type: remodaler.types.CONFIRM,
            message: 'Are you sure?',
            confirmText: 'Yes, Remove',
            confirm: () => {
              minus.removeEventListener('click', delCouponClick);
              td.innerHTML = "<div class='ui active tiny inline loader'></div>";
              this.deleteCoupon(coupon.id);
            }
          });
        };

        minus.addEventListener('click', delCouponClick);
      });

      //its ok to run more than once, Popover is re-enterent.
      document.querySelectorAll('.jsutils-popover').forEach(el => {
        new Popover(el);
      });
    });
  };
}
