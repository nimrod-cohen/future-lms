class AdminManager {
  currentClass = null;

  constructor() {
    COMMON.watchIcons();
    COMMON.watchDropdowns();
    COMMON.initTabs();

    this.studentsTab = new StudentsTab();
    this.settingsTab = new SettingsTab();
    this.coursesTab = new CoursesTab();
    this.palette = new CommandPalette({ courses: this.coursesTab });

    this.wireClassesScreen();
    this.wireMailerScreen();
  }

  /** Course + class pickers, as on the Students, Classes and Email tabs. */
  wireCourseClassPickers(tab, { onClass, classPlaceholder = 'Select class', autoPickSingle = false }) {
    const classes = COMMON.select(tab.querySelector('select.classes'), [], id => onClass(id), classPlaceholder);
    const courses = COMMON.select(
      tab.querySelector('select.courses'),
      [],
      async courseId => {
        classes.setValues([]);
        classes.disable(!courseId);
        onClass('');
        if (!courseId) return;

        const res = await JSUtils.fetch(__futurelms.ajax_url, { action: 'search_classes', course_id: courseId });
        if (courses.value() !== courseId) return; // changed again meanwhile
        const list = res?.results || [];
        this.classesById = { ...(this.classesById || {}), ...Object.fromEntries(list.map(c => [String(c.id), c])) };
        classes.setValues(list.map(c => ({ name: c.title, value: String(c.id) })));
        if (!list.length) tab.querySelector('select.classes').innerHTML = '<option value="">This course has no classes</option>';
        if (autoPickSingle && list.length === 1) classes.set(String(list[0].id));
      },
      'Select course'
    );

    JSUtils.fetch(__futurelms.ajax_url, { action: 'get_all_courses' }).then(data => {
      courses.setValues(
        Object.keys(data.courses)
          .map(cid => ({ name: data.courses[cid].name, value: cid }))
          .sort((a, b) => a.name.localeCompare(b.name))
      );
    });
    return { courses, classes };
  }

  /*
   * EMAIL TAB
   */
  wireMailerScreen() {
    const tab = COMMON.getTab(COMMON.TABS.MAILER);
    this.mailer = this.wireCourseClassPickers(tab, { onClass: () => {} });
    tab.querySelector('button.send').addEventListener('click', () => this.sendEmail(false));
    tab.querySelector('button.send-test').addEventListener('click', () => this.sendEmail(true));
  }

  async sendEmail(isTest) {
    const tab = COMMON.getTab(COMMON.TABS.MAILER);
    const courseId = this.mailer.courses.value();
    const classId = this.mailer.classes.value();
    const subject = tab.querySelector('#txtSubject').value.trim();
    let content = jQuery('#mailer-content').trumbowyg('html');

    const missing = [!courseId && 'a course', !classId && 'a class', !subject && 'a subject', !content.trim() && 'a message'].filter(Boolean);
    if (missing.length) {
      notifications.show(`Add ${missing.join(', ')} first`, 'error');
      return;
    }
    if (!isTest) {
      const className = tab.querySelector('select.classes').selectedOptions[0]?.textContent || 'this class';
      if (!window.confirm(`Send "${subject}" to every student in ${className}?`)) return;
    }

    const buttons = tab.querySelectorAll('button.send, button.send-test');
    buttons.forEach(b => (b.disabled = true));
    try {
      await JSUtils.fetch(__futurelms.ajax_url, {
        action: 'send_email',
        course_id: courseId,
        test: isTest ? 1 : 0,
        class_id: classId,
        subject,
        content: encodeURIComponent(COMMON.htmlRTL(content))
      });
      notifications.show(isTest ? 'Test sent to the admins' : 'Email sent to the class', 'success');
    } finally {
      buttons.forEach(b => (b.disabled = false));
    }
  }

  /*
   * CLASSES TAB
   */
  wireClassesScreen() {
    const tab = COMMON.getTab(COMMON.TABS.CLASSES);
    this.classesPickers = this.wireCourseClassPickers(tab, {
      onClass: classId => this.showClass(this.classesById?.[classId] || null),
      autoPickSingle: true
    });

    tab.querySelector('.pacing').addEventListener('click', async e => {
      const button = e.target.closest('[data-paced]');
      if (!button) return;
      const paced = button.dataset.paced === '1';
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
      if (this.classesById?.[this.currentClass.id]) this.classesById[this.currentClass.id].paced = paced;
      this.reloadLessons();
    });

    // A lesson's own date, or a module's "set all lessons" shortcut.
    const body = tab.querySelector('.class-body');
    body.addEventListener('change', e => {
      const input = e.target.closest('input.open-at');
      if (input) this.applyOpenAt(input.dataset.lessons, input.value);
    });
    body.addEventListener('click', e => {
      const clear = e.target.closest('.clear-open-at');
      if (clear) return this.applyOpenAt(clear.dataset.lessons, '');
      const now = e.target.closest('.open-now');
      if (now) this.applyOpenAt(now.dataset.lessons, 'now');
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
    tab.querySelector('.class-body').innerHTML = `<div class="p-5 space-y-3" aria-hidden="true">
      <div class="flms-skeleton h-4 w-1/3"></div><div class="flms-skeleton h-9"></div><div class="flms-skeleton h-9"></div></div>`;
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

  renderLessons(items) {
    this.lastItems = items;
    this.lessonsById = Object.fromEntries(items.map(i => [String(i.id), i]));
    const tab = COMMON.getTab(COMMON.TABS.CLASSES);
    const paced = this.currentClass.paced;
    const body = tab.querySelector('.class-body');
    const esc = COMMON.esc;

    tab.querySelectorAll('.pacing [data-paced]').forEach(b =>
      b.setAttribute('aria-pressed', String((b.dataset.paced === '1') === paced))
    );
    tab.querySelector('.pacing-note').textContent = paced
      ? `Each lesson opens on its date (${__futurelms.timezone} time). A lesson without a date stays closed.`
      : 'Students get every lesson from the day they join.';

    if (items.length === 0) {
      body.innerHTML = `<div class="flms-empty">${COMMON.icon('book-dashed')}<strong>No lessons yet</strong><span>Add lessons to this course in the Courses tab.</span></div>`;
      return;
    }

    if (!paced) {
      body.innerHTML = `
        <div class="flex items-start gap-3 p-5 class-message">
          <span class="flex items-center justify-center size-9 rounded-full bg-emerald-50 text-emerald-600 shrink-0">${COMMON.icon('circle-check')}</span>
          <div>
            <strong class="font-semibold">All ${items.length} lessons are open.</strong>
            <p class="text-slate-500 mt-0.5">Switch to Paced to open them on dates you choose, for a live cohort.</p>
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

    const dateField = (ids, value, caption = '') => `
      ${caption}
      <div class="open-at-field">
        <input type="datetime-local" class="flms-input open-at" data-lessons="${ids}" value="${value ? value.replace(' ', 'T') : ''}" aria-label="Opens" />
        ${value ? `<button type="button" class="flms-iconbtn clear-open-at" data-lessons="${ids}" data-tip="Clear date" aria-label="Clear date">${COMMON.icon('x')}</button>` : ''}
      </div>`;

    // Opens the given lessons right away (their date becomes now).
    const openNow = (ids, label) =>
      ids ? `<button type="button" class="flms-btn flms-btn-sm open-now" data-lessons="${ids}">${COMMON.icon('lock-open')}${label}</button>` : '';

    const status = item =>
      item.released
        ? `<span class="lesson-status is-open">${COMMON.icon('circle-check')}Open</span>`
        : item.open_at
        ? `<span class="lesson-status is-scheduled">${COMMON.icon('clock')}Scheduled</span>`
        : `<span class="lesson-status is-closed">${COMMON.icon('lock')}No date</span>`;

    body.innerHTML = `
      <div class="flex flex-wrap gap-x-6 gap-y-1 px-4 sm:px-5 py-3 text-sm text-slate-500 schedule-summary">
        <span><b class="text-slate-900 tabular-nums">${open}</b> open</span>
        <span><b class="text-slate-900 tabular-nums">${scheduled}</b> scheduled</span>
        <span><b class="${undated ? 'text-amber-700' : 'text-slate-900'} tabular-nums">${undated}</b> without a date</span>
      </div>
      <table class="flms-table flms-schedule is-responsive schedule">
        <thead>
          <tr><th>Lesson</th><th class="col-status">Status</th><th class="col-date">Opens</th><th class="col-action"><span class="sr-only">Actions</span></th></tr>
        </thead>
        <tbody>
          ${modules
            .map(m => {
              const ids = m.lessons.map(l => l.id).join(',');
              const dates = [...new Set(m.lessons.map(l => l.open_at || ''))];
              const shared = dates.length === 1 ? dates[0] : '';
              const count = `${m.lessons.length} ${m.lessons.length === 1 ? 'lesson' : 'lessons'}`;
              const caption = `<label class="set-all-label">${dates.length > 1 ? `Set all ${count} · their dates differ` : `Set all ${count}`}</label>`;
              const closedIds = m.lessons.filter(l => !l.released).map(l => l.id).join(',');
              return `
                <tr class="module-row">
                  <td colspan="2"><span class="font-semibold flms-bidi module-name">${esc(m.title)}</span></td>
                  <td class="col-date">${dateField(ids, shared, caption)}</td>
                  <td class="col-action text-right">${openNow(closedIds, 'Open all now')}</td>
                </tr>
                ${m.lessons
                  .map(
                    l => `
                  <tr class="lesson-row">
                    <td class="lesson-title flms-bidi">${esc(l.title)}</td>
                    <td class="col-status" data-label="Status">${status(l)}</td>
                    <td class="col-date">${dateField(l.id, l.open_at)}</td>
                    <td class="col-action text-right">${l.released ? '' : openNow(l.id, 'Open now')}</td>
                  </tr>`
                  )
                  .join('')}`;
            })
            .join('')}
        </tbody>
      </table>`;
  }
}

jQuery(document).ready(() => {
  window.adminManager = new AdminManager();
});
