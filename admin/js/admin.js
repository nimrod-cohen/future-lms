class AdminManager {
  currentTab = COMMON.TABS.STUDENTS;
  currentClassName = null;
  state = window.StateManagerFactory();
  studentsTab = new StudentsTab();
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
   * MAILER TAB
   */
  wireMailerScreen() {
    const mailerTab = COMMON.getTab(COMMON.TABS.MAILER);
    JSUtils.fetch(__futurelms.ajax_url, { action: 'get_all_courses' }).then(data => {
      const coursesNameValue = Object.keys(data.courses).map(cid => {
        let course = data.courses[cid];
        return { name: course.name, value: cid };
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

          JSUtils.fetch(__futurelms.ajax_url, {
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

    JSUtils.fetch(__futurelms.ajax_url, {
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
    JSUtils.fetch(__futurelms.ajax_url, { action: 'get_all_courses' }).then(data => {
      const coursesNameValue = Object.keys(data.courses).map(cid => {
        let course = data.courses[cid];
        return { name: course.name, value: cid };
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
        JSUtils.fetch(__futurelms.ajax_url, { action: 'get_lessons', class_id: oClass.id }).then(data => {
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

    // Each lesson's open date & hour. Saving (or clearing) re-renders the list.
    jQuery(document).on('change', `${COMMON.getTabSelector(COMMON.TABS.CLASSES)} .lesson input.open-at`, e => {
      this.saveOpenAt(e.currentTarget.closest('.item.lesson').getAttribute('data-id'), e.currentTarget.value);
    });
    jQuery(document).on('click', `${COMMON.getTabSelector(COMMON.TABS.CLASSES)} .lesson .clear-open-at`, e => {
      this.saveOpenAt(e.currentTarget.closest('.item.lesson').getAttribute('data-id'), '');
    });
  }

  async saveOpenAt(lessonId, openAt) {
    const classId = COMMON.getTab(COMMON.TABS.CLASSES).querySelector('.search.classes').getAttribute('data-id');
    if (!classId) return;

    const row = COMMON.getTab(COMMON.TABS.CLASSES).querySelector(`.item.lesson[data-id='${lessonId}']`);
    row?.classList.add('saving');

    const res = await JSUtils.fetch(__futurelms.ajax_url, {
      action: 'set_lesson_open_at',
      class_id: classId,
      lesson_id: lessonId,
      open_at: openAt
    });
    if (!res?.success) notifications.show(res?.data?.message || 'Failed to save the date', 'error');

    const data = await JSUtils.fetch(__futurelms.ajax_url, { action: 'get_lessons', class_id: classId });
    this.renderLessons(data);
  }

  renderLessons(data) {
    let lessons = COMMON.getTab(COMMON.TABS.CLASSES).querySelector('.ui.list.lessons');
    lessons.innerHTML = '';
    let items = Array.isArray(data) ? data : [];
    if (items.length === 0) {
      lessons.insertAdjacentHTML('beforeend', '<p>No results</p>');
      return;
    }

    lessons.insertAdjacentHTML(
      'beforeend',
      `<p class='open-at-help'>Set the date & hour each lesson opens (site time). A lesson without a date stays as it is.</p>`
    );

    let moduleId = null;
    items.forEach(item => {
      if (item.module_id !== moduleId) {
        moduleId = item.module_id;
        lessons.insertAdjacentHTML('beforeend', `<h5 class='ui header module-title'>${item.module_title}</h5>`);
      }

      // released = the class's schedule only (not the admin's own progress gates).
      const icon = item.released ? 'green check circle' : item.open_at ? 'orange clock outline' : 'grey lock';
      const status = item.released ? 'Open' : item.open_at ? 'Scheduled' : 'Closed';
      const value = item.open_at ? item.open_at.replace(' ', 'T') : '';

      lessons.insertAdjacentHTML(
        'beforeend',
        `<div class='item lesson' data-id='${item.id}'>
          <i class='${icon} icon' title='${status}'></i>
          <div class='content lesson-title'>${item.title}</div>
          <div class='open-at-field'>
            <input type='datetime-local' class='open-at' value='${value}' />
            ${value ? `<i class='clear-open-at close link icon' title='Clear date'></i>` : ''}
          </div>
        </div>`
      );
    });
  }

  wireEvents() {
    this.wireClassesScreen();
    this.wireMailerScreen();
  }
}

jQuery(document).ready(() => {
  window.adminManager = new AdminManager();
});
