class AdminManager {
  currentTab = COMMON.TABS.STUDENTS;
  currentClass = null;
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
    const tab = COMMON.getTab(COMMON.TABS.CLASSES);
    const sel = `${COMMON.getTabSelector(COMMON.TABS.CLASSES)}`;
    const classesDropdown = tab.querySelector('.ui.dropdown.classes');
    this.classesById = {};

    JSUtils.fetch(__futurelms.ajax_url, { action: 'get_all_courses' }).then(data => {
      const courses = Object.keys(data.courses).map(cid => ({ name: data.courses[cid].name, value: cid }));

      COMMON.wireDropdown(
        tab.querySelector('.ui.dropdown.courses'),
        courses,
        async courseId => {
          this.showClass(null);
          jQuery(classesDropdown).dropdown('clear');
          classesDropdown.classList.toggle('disabled', !courseId);
          if (!courseId) return;

          const res = await JSUtils.fetch(__futurelms.ajax_url, { action: 'search_classes', course_id: courseId });
          const classes = res?.results || [];
          this.classesById = Object.fromEntries(classes.map(c => [String(c.id), c]));
          COMMON.wireDropdown(
            classesDropdown,
            classes.map(c => ({ name: c.title, value: String(c.id) })),
            classId => this.showClass(this.classesById[classId] || null),
            classes.length ? 'Select class' : 'This course has no classes'
          );
          if (classes.length === 1) jQuery(classesDropdown).dropdown('set selected', String(classes[0].id));
        },
        'Select course'
      );
      COMMON.wireDropdown(classesDropdown, [], () => {}, 'Select class');
    });

    jQuery(document).on('click', `${sel} .pacing .button`, async e => {
      const paced = e.currentTarget.getAttribute('data-paced') === '1';
      if (!this.currentClass || this.currentClass.paced === paced) return;

      this.setBusy(true);
      const res = await JSUtils.fetch(__futurelms.ajax_url, {
        action: 'set_class_pacing',
        class_id: this.currentClass.id,
        paced: paced ? 1 : 0
      });
      if (!res?.success) {
        this.setBusy(false);
        return notifications.show(res?.data?.message || 'Could not change the pacing. Try again.', 'error');
      }
      this.currentClass.paced = paced;
      this.reloadLessons();
    });

    // A lesson's own date, or a module's "set all lessons" shortcut.
    jQuery(document).on('change', `${sel} input.open-at`, e => {
      this.applyOpenAt(e.currentTarget.getAttribute('data-lessons'), e.currentTarget.value);
    });
    jQuery(document).on('click', `${sel} .clear-open-at`, e => {
      this.applyOpenAt(e.currentTarget.getAttribute('data-lessons'), '');
    });
    jQuery(document).on('click', `${sel} .open-now`, e => {
      this.applyOpenAt(e.currentTarget.getAttribute('data-lessons'), 'now');
    });
  }

  // A module-wide change replaces every lesson's own date, so ask first when
  // it would change a date a lesson already has.
  applyOpenAt(lessonIds, openAt) {
    const ids = (lessonIds || '').split(',').filter(Boolean);
    if (ids.length > 1) {
      const target = openAt.replace('T', ' ');
      const lessons = ids.map(id => this.lessonsById?.[id]).filter(Boolean);
      // Lessons that all share one date were set together - nothing of
      // their own to lose. Only differing dates are worth a question.
      const uniform = new Set(lessons.map(l => l.open_at || '')).size === 1;
      const dated = uniform ? [] : lessons.filter(l => l.open_at && l.open_at !== target);
      let question = '';

      if (openAt === 'now') {
        const later = lessons.filter(l => !l.released && l.open_at);
        if (later.length) question = `${later.length} of these lessons are scheduled for later. Open them now anyway?`;
      } else if (openAt === '') {
        if (dated.length) question = `Clear the dates of ${dated.length} ${dated.length === 1 ? 'lesson' : 'lessons'} in this module? Students won't see them until you set a new date.`;
      } else if (dated.length) {
        question = `${dated.length} ${dated.length === 1 ? 'lesson in this module has its own date' : 'lessons in this module have their own dates'}. Replace ${dated.length === 1 ? 'it' : 'them'} with ${this.formatDate(target)}?`;
      }

      if (question && !window.confirm(question)) {
        this.renderLessons(this.lastItems || []); // put the field back
        return;
      }
    }
    this.saveOpenAt(lessonIds, openAt);
  }

  showClass(oClass) {
    const tab = COMMON.getTab(COMMON.TABS.CLASSES);
    this.currentClass = oClass ? { ...oClass, paced: !!oClass.paced } : null;
    tab.querySelector('.classes-empty').classList.toggle('hidden', !!oClass);
    tab.querySelector('.class-panel').classList.toggle('hidden', !oClass);
    if (!oClass) return;

    tab.querySelector('.class-name').textContent = oClass.title;
    const meta = [];
    if (oClass.start_date) meta.push(`Starts ${this.formatDate(oClass.start_date.replace('T', ' ').slice(0, 16))}`);
    meta.push(`${oClass.students} ${oClass.students === 1 ? 'student' : 'students'}`);
    tab.querySelector('.class-meta').textContent = meta.join(' · ');
    tab.querySelector('.class-body').innerHTML = '';
    this.reloadLessons();
  }

  setBusy(busy) {
    COMMON.getTab(COMMON.TABS.CLASSES).querySelector('.class-panel').classList.toggle('is-busy', busy);
  }

  async reloadLessons() {
    if (!this.currentClass) return;
    const classId = this.currentClass.id;
    this.setBusy(true);
    const data = await JSUtils.fetch(__futurelms.ajax_url, { action: 'get_lessons', class_id: classId });
    if (this.currentClass?.id !== classId) return; // switched class meanwhile
    this.setBusy(false);
    this.renderLessons(Array.isArray(data) ? data : []);
  }

  async saveOpenAt(lessonIds, openAt) {
    if (!this.currentClass || !lessonIds) return;
    this.setBusy(true);
    const res = await JSUtils.fetch(__futurelms.ajax_url, {
      action: 'set_lesson_open_at',
      class_id: this.currentClass.id,
      lesson_ids: lessonIds,
      open_at: openAt
    });
    if (!res?.success) notifications.show(res?.data?.message || 'Could not save the date. Try again.', 'error');
    this.reloadLessons();
  }

  // "2026-11-03 20:00" -> "3 Nov 2026, 20:00"
  formatDate(value) {
    const [date, time] = value.split(' ');
    const [y, m, d] = date.split('-').map(Number);
    const month = new Date(y, m - 1, 1).toLocaleString('en', { month: 'short' });
    return `${d} ${month} ${y}${time ? `, ${time}` : ''}`;
  }

  escape(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
  }

  renderLessons(items) {
    this.lastItems = items;
    this.lessonsById = Object.fromEntries(items.map(i => [String(i.id), i]));
    const tab = COMMON.getTab(COMMON.TABS.CLASSES);
    const paced = this.currentClass.paced;
    const body = tab.querySelector('.class-body');

    tab.querySelectorAll('.pacing .button').forEach(b =>
      b.classList.toggle('active', (b.getAttribute('data-paced') === '1') === paced)
    );
    tab.querySelector('.pacing-note').textContent = paced
      ? `Each lesson opens on its date (${__futurelms.timezone} time). A lesson without a date stays closed.`
      : 'Students get every lesson from the day they join.';

    if (items.length === 0) {
      body.innerHTML = `<p class='class-message'>This course has no lessons yet.</p>`;
      return;
    }

    if (!paced) {
      body.innerHTML = `
        <div class='class-message'>
          <i class='green check circle icon'></i>
          <div>
            <strong>All ${items.length} lessons are open.</strong>
            <p>Switch to Paced to open them on dates you choose, for a live cohort.</p>
          </div>
        </div>`;
      return;
    }

    const open = items.filter(i => i.released).length;
    const scheduled = items.filter(i => !i.released && i.open_at).length;
    const undated = items.length - open - scheduled;

    // Group lessons by module, keeping the server's order.
    const modules = [];
    items.forEach(item => {
      let m = modules[modules.length - 1];
      if (!m || m.id !== item.module_id) {
        m = { id: item.module_id, title: item.module_title, lessons: [] };
        modules.push(m);
      }
      m.lessons.push(item);
    });

    const dateField = (ids, value, extra = '', caption = '') => `
      ${caption}
      <div class='open-at-field'>
        <div class='ui small input'>
          <input type='datetime-local' class='open-at' data-lessons='${ids}' value='${value ? value.replace(' ', 'T') : ''}' />
        </div>
        ${extra}
        ${value ? `<button type='button' class='clear-open-at' data-lessons='${ids}' title='Clear date' aria-label='Clear date'><i class='close icon'></i></button>` : ''}
      </div>`;

    // Opens the given lessons right away (their date becomes now).
    const openNow = (ids, label) =>
      ids ? `<button type='button' class='ui mini basic button open-now' data-lessons='${ids}'>${label}</button>` : '';

    const status = item =>
      item.released
        ? `<span class='lesson-status is-open'><i class='check circle icon'></i>Open</span>`
        : item.open_at
        ? `<span class='lesson-status is-scheduled'><i class='clock outline icon'></i>Scheduled</span>`
        : `<span class='lesson-status is-closed'><i class='lock icon'></i>No date</span>`;

    body.innerHTML = `
      <div class='schedule-summary'>
        <span><b>${open}</b> open</span>
        <span><b>${scheduled}</b> scheduled</span>
        <span class='${undated ? 'has-undated' : ''}'><b>${undated}</b> without a date</span>
      </div>
      <table class='ui very basic table schedule'>
        <thead>
          <tr><th>Lesson</th><th class='col-status'>Status</th><th class='col-date'>Opens</th><th class='col-action'></th></tr>
        </thead>
        <tbody>
          ${modules
            .map(m => {
              const ids = m.lessons.map(l => l.id).join(',');
              const dates = [...new Set(m.lessons.map(l => l.open_at || ''))];
              const shared = dates.length === 1 ? dates[0] : '';
              const count = `${m.lessons.length} ${m.lessons.length === 1 ? 'lesson' : 'lessons'}`;
              const caption = `<label class='set-all-label'>${
                dates.length > 1 ? `Set all ${count} · their dates differ` : `Set all ${count}`
              }</label>`;
              const closedIds = m.lessons.filter(l => !l.released).map(l => l.id).join(',');
              return `
                <tr class='module-row'>
                  <td colspan='2'>
                    <span class='module-name'>${this.escape(m.title)}</span>
                  </td>
                  <td class='col-date'>${dateField(ids, shared, '', caption)}</td>
                  <td class='col-action'>${openNow(closedIds, 'Open all now')}</td>
                </tr>
                ${m.lessons
                  .map(
                    l => `
                  <tr class='lesson-row'>
                    <td class='lesson-title'>${this.escape(l.title)}</td>
                    <td class='col-status'>${status(l)}</td>
                    <td class='col-date'>${dateField(l.id, l.open_at)}</td>
                    <td class='col-action'>${l.released ? '' : openNow(l.id, 'Open now')}</td>
                  </tr>`
                  )
                  .join('')}`;
            })
            .join('')}
        </tbody>
      </table>`;
  }

  wireEvents() {
    this.wireClassesScreen();
    this.wireMailerScreen();
  }
}

jQuery(document).ready(() => {
  window.adminManager = new AdminManager();
});
