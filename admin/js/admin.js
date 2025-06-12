class AdminManager {
  currentTab = COMMON.TABS.STUDENTS;
  currentClassName = null;
  state = window.StateManagerFactory();
  studentsTab = new StudentsTab();
  couponsTab = new CouponsTab();
  partnerCouponsTab = new PartnerCouponsTab();
  settingsTab = new SettingsTab();
  coursesTab = new CoursesTab();

  constructor() {
    console.log(this.currentTab);
  console.log('constructor');
    //binding
    this.wireTabs = this.wireTabs.bind(this);
    this.wireEvents = this.wireEvents.bind(this);

    this.renderLessons = this.renderLessons.bind(this);

    //tabs
    this.wireMailerScreen = this.wireMailerScreen.bind(this);
    this.wireBillingScreen = this.wireBillingScreen.bind(this);
    this.wireClassesScreen = this.wireClassesScreen.bind(this);

    //wire events
    this.wireTabs();
    this.wireEvents();
  }

  //set up semantic ui tabs
  wireTabs() {
    jQuery('.menu .item').tab({

      onVisible: function (tab) {
        this.currentTab = tab;
        console.log(this.currentTab);
      }
    });
  }

  /*
   * BILLING TAB
   */
  wireBillingScreen() {
    const billingTab = COMMON.getTab(COMMON.TABS.BILLING);

    jQuery(billingTab.querySelector('#bill_month')).calendar({
      type: 'month',
      onChange: date => {
        date = new Date(date);
        this.renderPayments(date);
      }
    });
  }

  renderPayments(date) {
    JSUtils.fetch(__valueSchool.ajax_url, {
      action: 'get_all_payments',
      month: date.getMonth() + 1,
      year: date.getFullYear()
    }).then(payments => {
      let paymentsTable = COMMON.getTab(COMMON.TABS.BILLING).querySelector('table.payments tbody');
      paymentsTable.innerHTML = '';

      if (payments.length === 0) {
        paymentsTable.insertAdjacentHTML('beforeend', "<tr><td colspan='10'>No results</td></tr>");
        COMMON.getTab(COMMON.TABS.BILLING).querySelector('.result-count').innerText = '0 payments';
      }

      let totalPayments = payments.reduce((agg, curr) => (agg += parseFloat(curr.sum)), 0);
      totalPayments = new Intl.NumberFormat('he-IL', {
        style: 'currency',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
        currency: 'ILS'
      }).format(totalPayments);

      COMMON.getTab(COMMON.TABS.BILLING).querySelector(
        '.result-count'
      ).innerText = `${payments.length} payments, total: ${totalPayments}`;

      payments.forEach(payment => {
        //add row
        let aff = parseInt(payment.affiliate_id);
        if (aff && !isNaN(aff) && aff !== -1) {
          aff = `<a href='/wp-admin/admin.php?page=affiliates-management&subpage=edit-affiliate&id=${payment.affiliate_id}'>${payment.affiliate_name}</a>`;
        } else {
          aff = '';
        }

        paymentsTable.insertAdjacentHTML(
          'beforeend',
          `<tr data-id=${payment.id}>
            <td>${payment.id}</td>
            <td><a href='/wp-admin/user-edit.php?user_id=${payment.student_id}'>${payment.user_email}</a></td>
            <td><nobr>${aff}</nobr></td>
            <td data-course-id='${payment.course_id}'>${payment.course_name}</td>
            <td>${payment.payment_date}</td>
            <td>${payment.sum}</td>
            <td>${payment.transaction_ref}</td>
            <td>${payment.processor}</td>
            <td>${payment.comment}</td>
            <td><span data-inverted='' data-position='top right' data-tooltip='Remove payment'><i class="minus red circle icon clickable"></i></span></td>
          </tr>`
        );

        //attach removal event
        let td = paymentsTable.querySelector(`tr[data-id='${payment.id}'] td:last-child`);
        let minus = td.querySelector('i.minus');

        const removePaymentClick = e => {
          remodaler.show({
            type: remodaler.types.CONFIRM,
            title: `Delete payment ${payment.id}`,
            message: 'Are you sure?',
            confirmText: 'Yes, Delete',
            confirm: () => {
              minus.removeEventListener('click', removePaymentClick);
              td.innerHTML = "<div class='ui active tiny inline loader'></div>";
              this.removePayment(payment.id);
            }
          });
        };

        minus.addEventListener('click', removePaymentClick);
      });
    });
  }

  // the course id is required to ensure the student is not related to another class of this course.
  removePayment(paymentId) {
    JSUtils.fetch(__valueSchool.ajax_url, {
      action: 'remove_payment',
      payment_id: paymentId
    }).then(data => {
      console.log('removed');
      const billingTab = COMMON.getTab(COMMON.TABS.BILLING);
      const month = jQuery('#bill_month').calendar('get date');
      this.renderPayments(month);
    });
  }

  /*
   * MAILER TAB
   */
  wireMailerScreen() {
    const mailerTab = COMMON.getTab(COMMON.TABS.MAILER);
    JSUtils.fetch(__valueSchool.ajax_url, { action: 'get_all_courses' }).then(data => {
      const coursesNameValue = Object.keys(data.courses).map(cid => {
        let course = data.courses[cid];
        return { name: course.name, value: course.id };
      });

      COMMON.wireDropdown(
        mailerTab.querySelector('.ui.search.courses'),
        coursesNameValue,
        item => {
          let classes = jQuery(`${COMMON.getTabSelector(COMMON.TABS.MAILER)} .ui.search.classes`);

          if (!item || item == '') {
            classes.dropdown('clear');
            classes.dropdown('change values', []);
            return;
          }

          JSUtils.fetch(__valueSchool.ajax_url, {
            action: 'search_classes',
            course_id: item
          }).then(data => {
            COMMON.wireDropdown(classes, data.results, () => {}, 'Select class');
          });
        },
        'Select course'
      );
    });

    COMMON.wireSearch(
      `${COMMON.getTabSelector(COMMON.TABS.MAILER)} .ui.search.classes`,
      'search_classes',
      val => {
        console.log(val);
      },
      () => {
        return {
          course_id: mailerTab.querySelector('.ui.search.courses').getAttribute('data-id')
        };
      },
      () => {
        //check if i need to cancel
        let courseId = mailerTab.querySelector('.ui.search.courses').getAttribute('data-id');
        return !courseId || courseId.length === 0;
      }
    );

    mailerTab.querySelector('button.send').addEventListener('click', () => {
      this.sendEmail(false);
    });
    mailerTab.querySelector('button.send-test').addEventListener('click', () => {
      this.sendEmail(true);
    });
  }

  sendEmail(isTest) {
    const mailerTab = COMMON.getTab(COMMON.TABS.MAILER);

    const courseId = mailerTab.querySelector('.ui.search.courses').getAttribute('data-id') || '';
    const classId = mailerTab.querySelector('.ui.search.classes').getAttribute('data-id') || '';

    const subject = mailerTab.querySelector('#txtSubject').value;
    let content = jQuery('#mailer-content').trumbowyg('html');

    if (!courseId.length || !classId.length || !subject.length || !content.length) return;

    content = encodeURIComponent(COMMON.htmlRTL(content));

    mailerTab.querySelector('button.send').classList.add('disabled');

    JSUtils.fetch(__valueSchool.ajax_url, {
      action: 'send_email',
      course_id: courseId,
      test: isTest ? 1 : 0,
      class_id: classId,
      subject: subject,
      content: content
    }).then(data => {
      mailerTab.querySelector('button.send').classList.remove('disabled');
      alert('sent');
    });
  }

  /*
   * CLASSES TAB
   */
  wireClassesScreen() {
    let classesTab = COMMON.getTab(COMMON.TABS.CLASSES);
    JSUtils.fetch(__valueSchool.ajax_url, { action: 'get_all_courses' }).then(data => {
      const coursesNameValue = Object.keys(data.courses).map(cid => {
        let course = data.courses[cid];
        return { name: course.name, value: course.id };
      });

      COMMON.wireDropdown(
        classesTab.querySelector('.ui.search.courses'),
        coursesNameValue,
        item => {
          let classes = jQuery(`${COMMON.getTabSelector(COMMON.TABS.CLASSES)} .ui.search.classes`);
          classes.search('set value', '');
        },
        'Select course'
      );
    });

    COMMON.wireSearch(
      `${COMMON.getTabSelector(COMMON.TABS.CLASSES)} .ui.search.classes`,
      'search_classes',
      oClass => {
        this.currentClassName = oClass.title;
        JSUtils.fetch(__valueSchool.ajax_url, { action: 'get_lessons', class_id: oClass.id }).then(data => {
          this.renderLessons(data);
        });
      },
      () => {
        return {
          course_id: classesTab.querySelector('.ui.search.courses').getAttribute('data-id')
        };
      },
      () => {
        //check if i need to cancel
        let courseId = classesTab.querySelector('.ui.search.courses').getAttribute('data-id');
        return !courseId || courseId.length === 0;
      }
    );

    jQuery(document).on('click', `${COMMON.getTabSelector(COMMON.TABS.CLASSES)} .item.lesson`, e => {
      const lessonId = e.currentTarget.getAttribute('data-id');
      let classId = COMMON.getTab(COMMON.TABS.CLASSES).querySelector('.search.classes').getAttribute('data-id');

      e.currentTarget.removeChild(e.currentTarget.querySelector('i'));
      e.currentTarget.insertAdjacentHTML('afterbegin', "<span class='ui active tiny inline loader'></span>");

      JSUtils.fetch(__valueSchool.ajax_url, {
        action: 'set_lesson',
        lesson_id: lessonId,
        class_id: classId
      }).then(() => {
        JSUtils.fetch(__valueSchool.ajax_url, { action: 'get_lessons', class_id: classId }).then(data => {
          this.renderLessons(data);
        });
      });
    });
  }

  renderLessons(data) {
    let lessons = COMMON.getTab(COMMON.TABS.CLASSES).querySelector('.ui.list.lessons');
    lessons.innerHTML = '';
    let items = Object.values(data);
    if (items.length === 0) {
      lessons.insertAdjacentHTML('beforeend', '<p>No results</p>');
    }

    items.forEach(item => {
      lessons.insertAdjacentHTML(
        'beforeend',
        `<div class='item lesson clickable' data-id=${item.id}><i class='${
          item.open ? 'check ' : ' '
        }circle outline icon'></i><div class='middle aligned content'><div><b>${item.title}</b></div></div></div>`
      );
    });
  }

  wireEvents() {
    this.wireClassesScreen();
    this.wireMailerScreen();
    this.wireBillingScreen();
  }
}

jQuery(document).ready(() => {
  window.adminManager = new AdminManager();
});
