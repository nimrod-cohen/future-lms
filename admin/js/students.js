class StudentsTab {
  state = window.StateManagerFactory();
  LAST_STEP = 2;

  constructor() {
    const tab = (this.tab = COMMON.getTab(COMMON.TABS.STUDENTS));
    const modal = (this.modal = document.querySelector('#add-student-modal'));

    this.state.listen('students', this.render);
    this.state.listen('step', this.showStep);
    this.state.set('step', 1);
    this.state.set('existing', true);

    this.courseSelect = COMMON.select(tab.querySelector('select.courses'), [], this.onCourse, 'All courses');
    this.classSelect = COMMON.select(tab.querySelector('select.classes'), [], this.onClass, 'All classes');

    JSUtils.fetch(__futurelms.ajax_url, { action: 'get_all_courses' }).then(data => {
      const courses = Object.keys(data.courses)
        .map(cid => ({ name: data.courses[cid].name, value: cid }))
        .sort((a, b) => a.name.localeCompare(b.name));
      this.courseSelect.setValues(courses);
    });

    tab.querySelector('.students-search-form').addEventListener('submit', e => {
      e.preventDefault();
      this.fetchStudents();
    });

    // Add-student dialog
    this.studentSearch = COMMON.autocomplete(modal.querySelector('.flms-combo.student'), {
      action: 'search_students',
      extra: () => ({ class_id: this.classSelect.value() })
    });

    tab.querySelector('.add-student').addEventListener('click', this.showAddStudent);
    modal.querySelectorAll('.modal-close').forEach(b => b.addEventListener('click', () => modal.close()));
    modal.addEventListener('close', () => this.resetDialog());
    modal.querySelector('.next').addEventListener('click', this.clickNext);
    modal.querySelector('.prev').addEventListener('click', () => this.state.set('step', this.state.get('step') - 1));

    modal.querySelectorAll('.flms-segmented [data-mode]').forEach(button =>
      button.addEventListener('click', () => this.setMode(button.dataset.mode === 'existing'))
    );
    // Move-to-another-class dialog
    const moveModal = (this.moveModal = document.querySelector('#move-class-modal'));
    this.moveTo = COMMON.select(moveModal.querySelector('select.move-to'), [], v => {
      moveModal.querySelector('.move-confirm').disabled = !v;
    }, 'Pick a class');
    moveModal.querySelectorAll('.modal-close').forEach(b => b.addEventListener('click', () => moveModal.close()));
    moveModal.querySelector('.move-confirm').addEventListener('click', this.confirmMoveClass);

    modal.querySelectorAll('.flms-input').forEach(input =>
      input.addEventListener('input', () => input.classList.remove('is-invalid'))
    );
  }

  onCourse = async courseId => {
    this.classSelect.setValues([]);
    this.classSelect.disable(!courseId);
    this.onClass('');
    if (!courseId) return;

    const data = await JSUtils.fetch(__futurelms.ajax_url, { action: 'search_classes', course_id: courseId });
    if (this.courseSelect.value() !== courseId) return; // changed again meanwhile
    this.classSelect.setValues((data.results || []).map(c => ({ name: c.title, value: String(c.id) })));
  };

  onClass = classId => {
    this.tab.querySelector('.add-student').disabled = !classId;
  };

  setMode = existing => {
    this.state.set('existing', existing);
    this.modal.querySelectorAll('.flms-segmented [data-mode]').forEach(b =>
      b.setAttribute('aria-pressed', String((b.dataset.mode === 'existing') === existing))
    );
    this.modal.querySelector('#add-existing-student').hidden = !existing;
    this.modal.querySelector('#add-new-student').hidden = existing;
    (existing ? this.modal.querySelector('#existing-student-search') : this.modal.querySelector('#new-student-name')).focus();
  };

  showAddStudent = () => {
    const className = this.classSelect.value()
      ? this.tab.querySelector('select.classes').selectedOptions[0]?.textContent
      : '';
    this.modal.querySelector('.header').textContent = className ? `Add student to ${className}` : 'Add student';
    this.state.set('step', 1);
    this.modal.showModal();
    this.setMode(true);
  };

  resetDialog = () => {
    this.studentSearch.reset();
    this.modal.querySelectorAll('#add-new-student input, #comment').forEach(i => {
      i.value = '';
      i.classList.remove('is-invalid');
    });
    this.modal.querySelector('.next').disabled = false;
    this.setPendingMove(null);
    this.state.set('step', 1);
  };

  showStep = step => {
    this.modal.querySelectorAll('[data-step]').forEach(s => (s.hidden = String(step) !== s.dataset.step));
    this.modal.querySelector('.prev').disabled = step === 1;
    this.modal.querySelector('.next').innerHTML = step === this.LAST_STEP ? 'Add student' : 'Next';
  };

  clickNext = e => {
    e.preventDefault();
    const step = this.state.get('step');
    if (!this.validateStep(step)) return;
    if (step === this.LAST_STEP) {
      if (this.alreadyInClass) return notifications.show('The student is already in this class', 'error');
      this.submitAddStudent();
    } else {
      this.state.set('step', step + 1);
      if (step + 1 === this.LAST_STEP) this.checkCurrentClass();
    }
  };

  validateStep(step) {
    if (step !== 1) return true;
    const invalid = el => {
      el.classList.add('is-invalid');
      return true;
    };
    let failed = false;

    if (this.state.get('existing')) {
      const combo = this.modal.querySelector('.flms-combo.student');
      if (!combo.getAttribute('data-id')) failed = invalid(combo.querySelector('input'));
    } else {
      ['full-name', 'student-email', 'phone'].forEach(name => {
        const input = this.modal.querySelector(`input[name='${name}']`);
        if (!input.value.trim()) failed = invalid(input);
      });
    }
    if (failed) notifications.show(this.state.get('existing') ? 'Pick a student from the list' : 'Fill in name, email and phone', 'error');
    return !failed;
  }

  submitAddStudent() {
    let userData;
    if (this.state.get('existing')) {
      const combo = this.modal.querySelector('.flms-combo.student');
      userData = { id: combo.getAttribute('data-id'), name: combo.querySelector('input').value };
    } else {
      userData = {
        email: COMMON.removeNonAsciiChars(this.modal.querySelector("input[name='student-email']").value.trim()),
        name: this.modal.querySelector("input[name='full-name']").value.trim(),
        phone: this.modal.querySelector("input[name='phone']").value.trim()
      };
    }
    userData.class_id = this.classSelect.value();
    userData.comment = this.modal.querySelector('#comment').value;
    // This form enrolls a student without a sale - there is no payment step.
    // Pass the payment fields explicitly: every value goes through
    // encodeURIComponent, so an omitted one would reach the server as the
    // string "undefined" and end up in the payment record and in Customer.io.
    userData.method = 'manual';

    this.modal.querySelector('.next').disabled = true;
    if (this.pendingMove) this.moveStudentClass(this.courseSelect.value(), userData);
    else this.addStudentToClass(this.courseSelect.value(), userData, 0);
  }

  /**
   * A student already in another class of this course can't be added again.
   * Checked on entering step 2, which then offers to move them instead.
   */
  async checkCurrentClass() {
    this.setPendingMove(null);
    if (!this.state.get('existing')) return;
    const studentId = this.modal.querySelector('.flms-combo.student').getAttribute('data-id');
    const next = this.modal.querySelector('.next');
    next.disabled = true;
    try {
      const classes = await JSUtils.fetch(__futurelms.ajax_url, {
        action: 'get_classes',
        course_id: this.courseSelect.value(),
        student_id: studentId
      });
      this.setPendingMove((classes || []).find(c => c.attending) || null);
    } catch (e) {
      // the add itself reports any problem
    } finally {
      next.disabled = false;
    }
  }

  setPendingMove(current) {
    const notice = this.modal.querySelector('.move-notice');
    const next = this.modal.querySelector('.next');
    const name = this.modal.querySelector('.flms-combo.student input').value || 'The student';
    const same = current && String(current.id) === String(this.classSelect.value());
    this.pendingMove = current && !same ? current : null;

    notice.hidden = !current;
    if (same) notice.textContent = `${name} is already in this class.`;
    else if (current) notice.textContent = `${name} is already in ${current.title}. Move them to this class instead? Their progress is kept.`;
    this.modal.querySelector('.add-hint').hidden = !!current;
    next.textContent = this.pendingMove ? 'Move student' : 'Add student';
    this.alreadyInClass = !!same;
  }

  showMoveClass = async student => {
    const modal = this.moveModal;
    this.moving = student;
    const name = student.display_name || 'the student';
    modal.querySelector('.header').textContent = `Move ${name} to another class`;
    modal.querySelector('.current-class').textContent = `Now in ${student.class_name}.`;
    modal.querySelector('#move-comment').value = '';
    modal.querySelector('.move-confirm').disabled = true;
    this.moveTo.setValues([]);
    this.moveTo.disable(true);
    modal.showModal();

    const classes = await JSUtils.fetch(__futurelms.ajax_url, {
      action: 'get_classes',
      course_id: student.course_id,
      student_id: student.id
    });
    if (this.moving !== student) return; // closed and reopened meanwhile
    const others = (classes || []).filter(c => String(c.id) !== String(student.class_id));
    this.moveTo.setValues(others.map(c => ({ name: c.title, value: String(c.id) })));
    this.moveTo.disable(!others.length);
    if (!others.length) modal.querySelector('select.move-to').innerHTML = '<option value="">This course has no other class</option>';
  };

  confirmMoveClass = async () => {
    const modal = this.moveModal;
    const student = this.moving;
    const button = modal.querySelector('.move-confirm');
    button.disabled = true;
    try {
      const res = await JSUtils.fetch(__futurelms.ajax_url, {
        action: 'move_student_class',
        student_id: student.id,
        course_id: student.course_id,
        class_id: this.moveTo.value(),
        comment: modal.querySelector('#move-comment').value
      });
      if (!res?.success) throw new Error(res?.data?.message || 'server error');
      modal.close();
      notifications.show('Student moved', 'success');
      this.fetchStudents();
    } catch (err) {
      button.disabled = false;
      notifications.show(`Could not move the student: ${err.message}`, 'error');
    }
  };

  async moveStudentClass(courseId, userData) {
    const next = this.modal.querySelector('.next');
    next.disabled = true;
    try {
      const res = await JSUtils.fetch(__futurelms.ajax_url, {
        action: 'move_student_class',
        student_id: userData.id,
        course_id: courseId,
        class_id: userData.class_id,
        comment: userData.comment
      });
      if (!res?.success) throw new Error(res?.data?.message || 'server error');
      this.modal.close();
      notifications.show('Student moved', 'success');
      this.fetchStudents();
    } catch (err) {
      next.disabled = false;
      notifications.show(`Could not move the student: ${err.message}`, 'error');
    }
  }

  addStudentToClass(courseId, userData, sum) {
    JSUtils.fetch(__futurelms.ajax_url, {
      action: 'add_student_to_class',
      student_id: userData.id,
      name: userData.name,
      phone: userData.phone,
      email: userData.email,
      method: userData.method,
      course_id: courseId,
      class_id: userData.class_id,
      sum: sum,
      comment: userData.comment
    }).then(() => {
      this.modal.close();
      notifications.show('Student added', 'success');
      this.fetchStudents();
    }).catch(err => {
      // e.g. the student is already in another class of this course
      this.modal.querySelector('.next').disabled = false;
      notifications.show(`Could not add the student: ${err?.message || 'server error'}`, 'error');
    });
  }

  fetchStudents = async () => {
    const month = this.tab.querySelector('.reg-month').value;
    const year = month ? this.tab.querySelector('.reg-year').value : '';
    const button = this.tab.querySelector('.search-students');

    button.disabled = true;
    this.tab.querySelector('table.students tbody').innerHTML = `<tr><td colspan="9"><div class="flms-empty"><span class="flms-spinner"></span>Searching…</div></td></tr>`;

    try {
      const data = await JSUtils.fetch(__futurelms.ajax_url, {
        action: 'get_students',
        course_id: this.courseSelect.value(),
        class_id: this.classSelect.value(),
        search: this.tab.querySelector("[name='name_or_email']").value,
        month,
        year
      });
      this.state.set('students', data || []);
    } finally {
      button.disabled = false;
    }
  };

  render = () => {
    const body = this.tab.querySelector('table.students tbody');
    const students = Object.values(this.state.get('students') || {});
    const esc = COMMON.esc;

    this.tab.querySelector('.result-count').textContent = `${students.length} ${students.length === 1 ? 'student' : 'students'}`;

    if (students.length === 0) {
      body.innerHTML = `<tr><td colspan="9"><div class="flms-empty">${COMMON.icon('search-x')}<strong>No students found</strong><span>Try another course, class or name.</span></div></td></tr>`;
      return;
    }

    const blank = v => [false, null, undefined, '', 'undefined'].includes(v);
    body.innerHTML = students
      .map(s => {
        const progress =
          s.progress != null
            ? `<button type="button" class="flms-btn flms-btn-sm progress-link" data-tip="Lesson by lesson">
                 <span class="tabular-nums">${esc(s.progress)}%</span>${COMMON.icon('panel-right-open')}
               </button>`
            : '<span class="text-slate-400">—</span>';
        return `<tr data-id="${esc(s.id)}" data-class-id="${esc(s.class_id)}" data-course-id="${esc(s.course_id)}">
          <td data-label="ID" class="flms-id">${esc(s.id)}</td>
          <td data-label="Name" class="font-medium flms-bidi">${blank(s.display_name) ? '' : esc(s.display_name)}</td>
          <td data-label="Email" class="md:whitespace-nowrap break-all md:break-normal">${esc(s.user_email)}</td>
          <td data-label="Phone" class="tabular-nums whitespace-nowrap">${blank(s.phone) ? '' : esc(s.phone)}</td>
          <td data-label="Course" class="flms-bidi">${esc(s.course_name)}</td>
          <td data-label="Class">
            <div class="flex items-center gap-1.5">
              <span class="flms-bidi">${esc(s.class_name)}</span>
              <button type="button" class="flms-iconbtn move-class" data-tip="Move to another class" aria-label="Move to another class">${COMMON.icon('arrow-left-right')}</button>
            </div>
          </td>
          <td data-label="Joined" class="tabular-nums whitespace-nowrap">${esc(String(s.registration_date || '').substring(0, 10))}</td>
          <td data-label="Progress">${progress}</td>
          <td class="text-right"><button type="button" class="flms-iconbtn is-danger remove-class" data-tip="Remove from class" aria-label="Remove from class">${COMMON.icon('user-minus')}</button></td>
        </tr>`;
      })
      .join('');

    body.querySelectorAll('tr[data-id]').forEach(row => {
      const student = students.find(s => String(s.id) === row.dataset.id && String(s.class_id) === row.dataset.classId);
      row.querySelector('.remove-class').addEventListener('click', e => {
        remodaler.show({
          title: `Remove ${student.display_name || 'student'}`,
          type: remodaler.types.CONFIRM,
          message: `${student.display_name || 'The student'} loses access to ${student.course_name}.`,
          confirmText: 'Remove',
          confirm: () => {
            e.target.closest('button').classList.add('is-busy');
            this.removeStudentFromClass(student.id, row.dataset.classId, row.dataset.courseId);
          }
        });
      });
      row.querySelector('.move-class').addEventListener('click', () => this.showMoveClass(student));
      row.querySelector('.progress-link')?.addEventListener('click', () =>
        this.showProgressDetail(student.id, row.dataset.courseId, student.display_name, student.course_name)
      );
    });
  };

  /**
   * The module/lesson tree with each lesson's completion, in the same slide-out
   * the course editors use. The content is written into the panel rather than
   * passed as `message`: the slide-out keeps only `.slideout-form-line`
   * children of a message, and nothing here is a form field.
   */
  async showProgressDetail(studentId, courseId, studentName, courseName) {
    slideout.show({ title: `${studentName || 'Student'} · ${courseName || 'Course'}`, message: '', cancelText: 'Close' });

    const panel = document.querySelector('.slideout-bg:last-of-type');
    if (!panel) return;
    panel.querySelector("button[data-panel-action='confirm']")?.remove(); // read-only
    const msgEl = panel.querySelector('[data-panel-message]');
    if (!msgEl) return;
    msgEl.innerHTML = '<div class="flms-pd-summary"><span class="flms-spinner"></span> Loading…</div>';

    const esc = COMMON.esc;
    try {
      const data = await JSUtils.fetch(__futurelms.ajax_url, {
        action: 'get_student_progress_detail',
        student_id: studentId,
        course_id: courseId
      });
      if (!msgEl.isConnected) return; // closed meanwhile

      if (!data || !data.success) {
        msgEl.innerHTML = `<p style="color:#b91c1c">${esc(data?.data?.message || 'Could not load the progress.')}</p>`;
        return;
      }

      const { progress, modules } = data.data;
      const pct = progress.percent;
      const color = pct >= 80 ? '#059669' : pct >= 40 ? '#d97706' : '#3646a8';
      let html = `<div class="flms-pd-summary">
        <div class="flms-pd-bar-wrap"><div class="flms-pd-bar" style="width:${pct}%;background:${color}"></div></div>
        <span class="flms-pd-stats">${progress.completed} / ${progress.total} lessons · ${pct}%</span>
      </div>`;

      for (const mod of modules) {
        const done = mod.lessons.filter(l => l.completed).length;
        const all = done === mod.lessons.length;
        html += `<div class="flms-pd-module ${all ? 'is-complete' : ''}">
          <div class="flms-pd-module-header">
            ${COMMON.icon(all ? 'circle-check' : 'folder-open')}
            <span class="flms-pd-module-name">${mod.intro ? 'מבוא' : `מודול ${mod.order}`}: ${esc(mod.name)}</span>
            <span class="flms-pd-module-count">${done}/${mod.lessons.length}</span>
          </div>
          ${mod.lessons
            .map(
              les => `<div class="flms-pd-lesson ${les.completed ? 'is-done' : ''}">
                ${COMMON.icon(les.completed ? 'circle-check' : 'circle')}
                <span class="flms-pd-lesson-name">שיעור ${esc(les.number)}: ${esc(les.name)}</span>
                ${les.completed && les.seconds > 0 ? `<span class="flms-pd-lesson-dur">${this.fmtDuration(les.seconds)}</span>` : ''}
              </div>`
            )
            .join('')}
        </div>`;
      }
      msgEl.innerHTML = html;
    } catch (ex) {
      if (msgEl.isConnected) msgEl.innerHTML = `<p style="color:#b91c1c">${esc(ex.message || 'Unknown error')}</p>`;
    }
  }

  fmtDuration(secs) {
    if (secs < 60) return `${secs}s`;
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    if (m < 60) return `${m}:${String(s).padStart(2, '0')}`;
    return `${Math.floor(m / 60)}:${String(m % 60).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
  }

  // The course id makes sure the student isn't tied to another class of this course.
  removeStudentFromClass(studentId, classId, courseId) {
    JSUtils.fetch(__futurelms.ajax_url, {
      action: 'remove_class',
      student_id: studentId,
      course_id: courseId,
      class_id: classId
    }).then(() => {
      notifications.show('Student removed from the class', 'success');
      this.fetchStudents();
    });
  }
}
