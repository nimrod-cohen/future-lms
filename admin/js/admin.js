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

    jQuery(document).on('click', `${COMMON.getTabSelector(COMMON.TABS.CLASSES)} .item.lesson`, e => {
      const lessonId = e.currentTarget.getAttribute('data-id');
      let classId = COMMON.getTab(COMMON.TABS.CLASSES).querySelector('.search.classes').getAttribute('data-id');

      e.currentTarget.removeChild(e.currentTarget.querySelector('i'));
      e.currentTarget.insertAdjacentHTML('afterbegin', "<span class='ui active tiny inline loader'></span>");

      JSUtils.fetch(__futurelms.ajax_url, {
        action: 'set_lesson',
        lesson_id: lessonId,
        class_id: classId
      }).then(() => {
        JSUtils.fetch(__futurelms.ajax_url, { action: 'get_lessons', class_id: classId }).then(data => {
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
  }
}

jQuery(document).ready(() => {
  window.adminManager = new AdminManager();
});
