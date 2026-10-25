class CoursesTab {
  state = window.StateManagerFactory();

  constructor() {
    this.openCourses = new Set();
    this.openModules = new Set();
    this.state.listen('courses', this.render);

    this.getCourses();
    this.loadVimeoSDK();

    const tab = (this.tab = COMMON.getTab(COMMON.TABS.COURSES));
    this.list = tab.querySelector('#courses-list');

    // One delegated handler for the whole tree: it is re-rendered on every change.
    this.list.addEventListener('click', this.onTreeClick);

    tab.querySelector('[data-action="add-course"]').addEventListener('click', this.addCourse);
    document.querySelector('[data-action="add-class"]')?.addEventListener('click', this.addClass);


  }

  onTreeClick = e => {
    const target = e.target.closest('[data-action]');
    if (!target || !this.list.contains(target)) return;
    e.preventDefault();

    const handlers = {
      'toggle-course': () => this.toggle(this.openCourses, target.closest('.course'), 'courseId'),
      'toggle-module': () => this.toggle(this.openModules, target.closest('.course-module'), 'moduleId'),
      'edit-course': () => this.updateCourse(e),
      'pause-course': () => this.changeCourseStatus(e, 'draft'),
      'resume-course': () => this.changeCourseStatus(e, 'publish'),
      'delete-course': () => this.changeCourseStatus(e, 'trash'),
      'refresh-course-durations': () => this.refreshAllLessonDurations(e),
      'add-module': () => this.editModule(e, null),
      'edit-module': () => this.editModule(e, target.closest('.course-module').dataset.moduleId),
      'edit-quiz': () => this.editQuiz(target.closest('.course-module').dataset.moduleId),
      'pause-module': () => this.changeModuleStatus(e, 'draft'),
      'resume-module': () => this.changeModuleStatus(e, 'publish'),
      'delete-module': () => this.changeModuleStatus(e, 'trash'),
      'move-up-module': () => this.reoderModule(e, -1),
      'move-down-module': () => this.reoderModule(e, 1),
      'add-lesson': () => this.addLesson(e),
      'edit-lesson': () => this.editLesson(e),
      'pause-lesson': () => this.changeLessonStatus(e, 'draft'),
      'resume-lesson': () => this.changeLessonStatus(e, 'publish'),
      'delete-lesson': () => this.changeLessonStatus(e, 'trash'),
      'move-up-lesson': () => this.reoderLesson(e, -1),
      'move-down-lesson': () => this.reoderLesson(e, 1),
      'refresh-lesson-durations': () => this.refreshLessonDuration(target, true)
    };
    handlers[target.dataset.action]?.();
  };

  toggle = (set, el, key) => {
    const id = el.dataset[key];
    const open = !set.has(id);
    open ? set.add(id) : set.delete(id);
    el.classList.toggle('is-open', open);
    el.querySelector('[aria-expanded]')?.setAttribute('aria-expanded', String(open));
  };

  getCourses = async () => {
    const data = await JSUtils.fetch(__futurelms.ajax_url, { action: 'get_all_courses' });
    this.state.set('courses', data.courses);
  };

  actionButton = (action, icon, tip, extra = '', attrs = '') =>
    `<button type="button" class="flms-iconbtn ${extra}" data-action="${action}" data-tip="${tip}" aria-label="${tip}" ${attrs}>${COMMON.icon(icon)}</button>`;

  render = () => {
    const courses = this.state.get('courses');
    const esc = COMMON.esc;
    const btn = this.actionButton;

    if (!courses || Object.keys(courses).length === 0) {
      this.list.innerHTML = `<div class="flms-card"><div class="flms-empty">${COMMON.icon('book-open')}<strong>No courses yet</strong><span>Add the first course to get started.</span></div></div>`;
      return;
    }

    const courseIds = Object.keys(courses).sort((a, b) => courses[a].name.localeCompare(courses[b].name));

    this.list.innerHTML = courseIds
      .map(cid => {
        const course = courses[cid];
        const open = this.openCourses.has(cid);
        const moduleIds = Object.keys(course.modules).sort((a, b) => course.modules[a].order - course.modules[b].order);
        const introCount = Object.values(course.modules).filter(m => m.intro_module).length;
        const durations = course.total_duration
          ? `Total ${this.formatDuration(course.total_duration)} · Counted ${course.counted_duration ? this.formatDuration(course.counted_duration) : '0'}`
          : '';

        return `<article class="flms-card flms-course course ${open ? 'is-open' : ''} ${course.enabled ? '' : 'is-draft'}" data-course-id="${cid}">
          <div class="flms-course-head">
            <button type="button" class="flms-toggle" data-action="toggle-course" aria-expanded="${open}">
              ${COMMON.icon('chevron-right', 'flms-chevron')}
              <span class="min-w-0">
                <span class="flex items-center gap-2 min-w-0">
                  <span class="flms-course-name">${esc(course.name)}</span>
                  ${course.enabled ? '' : '<span class="flms-badge">Draft</span>'}
                </span>
                <span class="flex flex-wrap items-center gap-x-3 text-xs text-slate-500 mt-0.5">
                  <span class="flms-id">#${cid}</span>
                  <span>${moduleIds.length} ${moduleIds.length === 1 ? 'module' : 'modules'}</span>
                  <span id="course-duration-${cid}" class="tabular-nums">${durations}</span>
                </span>
              </span>
            </button>
            <div class="flms-actions">
              ${btn('refresh-course-durations', 'refresh-cw', 'Recalculate durations', '', `data-course-id="${cid}"`)}
              ${btn('edit-course', 'pencil', 'Edit course')}
              ${course.enabled ? btn('pause-course', 'pause', 'Unpublish course') : btn('resume-course', 'play', 'Publish course')}
              ${btn('add-module', 'folder-plus', 'Add module')}
              ${btn('delete-course', 'trash-2', 'Delete course', 'is-danger')}
            </div>
          </div>
          <div class="flms-course-body">
            ${moduleIds.length === 0 ? '<p class="text-sm text-slate-500 px-1 py-2">No modules yet.</p>' : ''}
            ${moduleIds.map((mid, idx) => this.renderModule(cid, mid, course.modules[mid], idx + 1 - introCount)).join('')}
          </div>
        </article>`;
      })
      .join('');
  };

  renderModule = (cid, mid, module, number) => {
    if (typeof module !== 'object') return '';
    const esc = COMMON.esc;
    const btn = this.actionButton;
    const open = this.openModules.has(mid);
    const lessonIds = Object.keys(module.lessons).sort((a, b) => module.lessons[a].order - module.lessons[b].order);
    const quiz = module.quiz?.enabled
      ? `<span class="flms-badge flms-badge-violet" data-tip="${esc(
          `${module.quiz.question_count} question(s)${module.quiz.pass_score > 0 ? `, pass ${module.quiz.pass_score}%` : ''}${
            module.quiz.pass_score > 0 && module.quiz.block_progress ? ', blocks progress' : ''
          }`
        )}">${COMMON.icon('list-checks')}Quiz</span>`
      : '';

    return `<div class="flms-module course-module ${open ? 'is-open' : ''} ${module.enabled ? '' : 'is-draft'}" data-module-id="${mid}">
      <div class="flms-module-head">
        <button type="button" class="flms-toggle" data-action="toggle-module" aria-expanded="${open}">
          ${COMMON.icon('chevron-right', 'flms-chevron')}
          <span class="flms-module-order">${module.intro_module ? 'Intro' : number}</span>
          <span class="flms-module-name">${esc(module.name)}</span>
        </button>
        <span class="flex items-center gap-1.5 shrink-0">
          ${module.count_progress ? `<span class="flms-badge flms-badge-amber" data-tip="Counts towards progress">${COMMON.icon('clock')}<span class="flms-hide-mobile">Counted</span></span>` : ''}
          ${quiz}
          ${module.enabled ? '' : '<span class="flms-badge">Draft</span>'}
          <span class="flms-id flms-hide-mobile" title="Module ID">#${mid}</span>
        </span>
        <div class="flms-actions">
          ${btn('edit-module', 'pencil', 'Edit module')}
          ${btn('edit-quiz', 'list-checks', 'Edit quiz')}
          ${module.enabled ? btn('pause-module', 'pause', 'Unpublish module') : btn('resume-module', 'play', 'Publish module')}
          ${btn('move-up-module', 'arrow-up', 'Move up')}
          ${btn('move-down-module', 'arrow-down', 'Move down')}
          ${btn('add-lesson', 'plus', 'Add lesson')}
          ${btn('delete-module', 'trash-2', 'Delete module', 'is-danger')}
        </div>
      </div>
      <div class="flms-module-lessons">
        ${lessonIds.length === 0 ? '<p class="text-sm text-slate-500 px-2 py-1.5">No lessons yet.</p>' : ''}
        ${lessonIds
          .map((lid, i) => {
            const lesson = module.lessons[lid];
            if (typeof lesson !== 'object') return '';
            const videos = lesson.videos.filter(v => v !== 'text');
            return `<div class="flms-lesson module-lesson ${lesson.enabled ? '' : 'is-draft'}" data-lesson-id="${lid}" data-course-id="${cid}">
              <span class="flms-lesson-num">${i + 1}</span>
              <span class="flms-lesson-main">
                <span class="flms-lesson-name">${esc(lesson.name)}</span>
                ${lesson.duration ? `<span class="flms-duration">${this.formatDuration(lesson.duration)}</span>` : ''}
                ${videos.length ? `<span class="flms-badge flms-badge-brand" data-tip="Vimeo ${esc(videos.join(', '))}">${COMMON.icon('video')}${videos.length}</span>` : ''}
                ${lesson.enabled ? '' : '<span class="flms-badge">Draft</span>'}
              </span>
              <span class="flms-id flms-hide-mobile" title="Lesson ID">#${lid}</span>
              <div class="flms-actions">
                ${videos.length ? btn('refresh-lesson-durations', 'refresh-cw', 'Refresh durations', 'refresh-durations', `data-content="${esc(videos.join(','))}"`) : ''}
                ${btn('edit-lesson', 'pencil', 'Edit lesson')}
                ${lesson.enabled ? btn('pause-lesson', 'pause', 'Unpublish lesson') : btn('resume-lesson', 'play', 'Publish lesson')}
                ${btn('move-up-lesson', 'arrow-up', 'Move up')}
                ${btn('move-down-lesson', 'arrow-down', 'Move down')}
                ${btn('delete-lesson', 'trash-2', 'Delete lesson', 'is-danger')}
              </div>
            </div>`;
          })
          .join('')}
      </div>
    </div>`;
  };

  /* ------------------------------------------------------------- search */

  /** Shows a search hit in the tree and, for mode 'edit', opens its editor. */
  openHit = (hit, mode = 'show') => {
    const el = this.reveal(hit);
    if (!el || mode !== 'edit') return;
    if (hit.type === 'course') this.editCourse(String(hit.id));
    else if (hit.type === 'module') this.editModule({ target: el }, String(hit.id));
    else this.editLesson({ target: el });
  };

  /** Opens an item's course (and module) in the tree, scrolls to it and flashes it. */
  reveal = hit => {
    this.openCourses.add(String(hit.course_id));
    if (hit.module_id) this.openModules.add(String(hit.module_id));
    this.render();

    const selector = {
      course: `.course[data-course-id='${hit.id}']`,
      module: `.course-module[data-module-id='${hit.id}']`,
      lesson: `.module-lesson[data-lesson-id='${hit.id}']`
    }[hit.type];
    const el = this.list.querySelector(selector);
    if (!el) {
      notifications.show(`That ${hit.type} is not in the course list`, 'error');
      return null;
    }
    // Flash the row itself, not a whole open course or module.
    const row = el.querySelector(':scope > .flms-course-head, :scope > .flms-module-head') || el;
    row.scrollIntoView({ block: 'center', behavior: 'smooth' });
    row.classList.remove('flms-flash');
    void row.offsetWidth; // restart the animation
    row.classList.add('flms-flash');
    return el;
  };

  reoderLesson = async (e, direction) => {
    const domLesson = e.target.closest('.module-lesson');
    const moduleId = domLesson.closest('.course-module').dataset.moduleId;
    const courseId = domLesson.closest('.course').dataset.courseId;
    const lessonId = domLesson.dataset.lessonId;
    const courses = this.state.get('courses');
    const module = courses[courseId].modules[moduleId];
    const lessonData = module.lessons[lessonId];
    const lastLessonOrder = Object.keys(module.lessons).length;

    if (
      (parseInt(lessonData.lesson_number) <= 1 && direction === -1) ||
      (parseInt(lessonData.lesson_number) >= lastLessonOrder && direction === 1)
    ) {
      return;
    }

    var data = {
      action: 'reorder_lesson',
      module_id: moduleId,
      lesson_id: lessonId,
      direction: direction
    };

    let result = await JSUtils.fetch(__futurelms.ajax_url, data);
    if (!result.error) {
      this.getCourses();
    }
  };

  reoderModule = async (e, direction) => {
    const module = e.target.closest('.course-module');
    const courseId = module.closest('.course').dataset.courseId;
    const moduleId = module.dataset.moduleId;
    const courses = this.state.get('courses');
    const moduleData = courses[courseId].modules[moduleId];
    const lastModuleOrder = Object.keys(courses[courseId].modules).length;

    if (
      (parseInt(moduleData.order) <= 1 && direction === -1) ||
      (parseInt(moduleData.order) >= lastModuleOrder && direction === 1)
    ) {
      return;
    }

    var data = {
      action: 'reorder_module',
      course_id: courseId,
      module_id: moduleId,
      direction: direction
    };

    let result = await JSUtils.fetch(__futurelms.ajax_url, data);
    if (!result.error) {
      this.getCourses();
    }
  };

  // Convert every .slideout-switch checkbox in the currently-open slideout
  // into a wpjsutils iOS-style switch. The label-based wrapper that
  // JSUtils.switch adds is what fixes the slideout's swallowed-click bug
  // — clicking the slider track now forwards to the input — and also gives
  // the controls a clear ON/OFF affordance. The belt-and-suspenders click
  // handler on the wrapper is for browsers where the synthetic label→input
  // click is intercepted somewhere in the slideout stack. Used by both
  // editCourse and editModule.
  wireSlideoutSwitches = () => {
    document.querySelectorAll('.slideout-panel input.slideout-switch').forEach(input => {
      if (typeof JSUtils.switch === 'function') {
        JSUtils.switch(input);
      }
      const wrapper = input.closest('.switch-wrapper');
      if (wrapper) {
        wrapper.addEventListener('click', ev => {
          if (ev.target === input) return;
          ev.preventDefault();
          input.checked = !input.checked;
          input.dispatchEvent(new Event('change', { bubbles: true }));
        });
      }
    });
  };

  editModule = (e, moduleId) => {
    const course = e.target.closest('.course');
    const courseId = course.dataset.courseId;
    const courses = this.state.get('courses');
    const courseData = courses[courseId];
    const module = moduleId ? courseData.modules[moduleId] : null;

    slideout.show({
      title: moduleId ? 'Edit Module' : 'Add Module',
      message: `${moduleId ? `<div class='slideout-form-line slideout-id-row'>
        <span class='entity-id-label'>Course #${courseId} · Module #${moduleId}</span>
      </div>` : ''}
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Module name</label>
        <input type='text' name='module_name' value='${module?.name || ''}'/>
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Counts towards progress</label>
        <input type='checkbox' class='slideout-switch round' id='count_progress' name='count_progress' value='1' ${module?.count_progress ? 'checked' : ''}/>
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title' for='teaser'>Teaser</label>
        <input type='text' id='teaser' name='teaser' value='${module?.teaser}'/>
        <small class='desc' style='font-size:0.8rem;'>A less revealing teaser text for course pages</small>
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Intro module (will not be numbered)</label>
        <input type='checkbox' class='slideout-switch round' id='intro_module' name='intro_module' value='1' ${module?.intro_module ? 'checked' : ''}/>
      </div>`,
      type: slideout.types.FORM,
      confirmText: 'Save',
      confirm: async vals => {
        if (!vals.module_name?.length) {
          notifications.show('Module name cannot be empty', 'error');
          return false;
        }

        var data = {
          action: 'edit_module',
          course_id: courseId,
          module_id: moduleId || '',
          name: vals.module_name,
          teaser: vals.teaser || '',
          count_progress: vals.count_progress ? '1' : '0',
          intro_module: vals.intro_module ? '1' : '0'
        };

        let result = await JSUtils.fetch(__futurelms.ajax_url, data);
        if (!result.error) {
          notifications.show('Module updated successfully', 'success');
          this.getCourses();
        } else {
          notifications.show(result.message || 'Failed to update module', 'error');
        }
      }
    });

    this.wireSlideoutSwitches();
  };

  /**
   * Multiple-choice quiz editor for a module.
   *
   * The questions live in a plain JS array (`quiz-questions` state) rather
   * than being read back out of the DOM on every keystroke: structural edits
   * (add / remove / reorder) re-render the list, and `syncQuizQuestions`
   * folds the current input values back into the array right before each
   * re-render and before saving. That keeps the ordering logic trivial and
   * makes an unsaved half-typed question survive a reorder.
   */
  editQuiz = async moduleId => {
    if (this.state.get('editing-quiz')) return;
    this.state.set('editing-quiz', true);

    const quiz = await JSUtils.fetch(__futurelms.ajax_url, {
      action: 'get_quiz_details',
      module_id: moduleId
    });

    if (quiz.error) {
      this.state.set('editing-quiz', false);
      notifications.show(quiz.message || 'Failed to load quiz', 'error');
      return;
    }

    this.state.set('quiz-questions', quiz.questions || []);

    slideout.show({
      title: 'Edit Quiz',
      message: `
        <div class='slideout-form-line slideout-id-row'>
          <span class='entity-id-label'>Course #${quiz.course_id || '?'} · Module #${moduleId}</span>
        </div>
        <div class='slideout-form-line'>
          <label class='slideout-form-line-title'>Quiz enabled</label>
          <input type='checkbox' class='slideout-switch round' name='quiz_enabled' value='1' ${
            quiz.enabled ? 'checked' : ''
          }/>
        </div>
        <div class='slideout-form-line'>
          <label class='slideout-form-line-title'>Quiz title</label>
          <input type='text' name='quiz_title' value='${this.escapeAttr(quiz.title || '')}' placeholder='בוחן'/>
        </div>
        <div class='slideout-form-line'>
          <label class='slideout-form-line-title'>Minimum pass score (%)</label>
          <input type='number' name='quiz_pass_score' min='0' max='100' value='${quiz.pass_score || 0}'/>
          <small class='desc' style='font-size:0.8rem;'>0 = no minimum score. The student always "passes".</small>
        </div>
        <div class='slideout-form-line quiz-line-block'>
          <label class='slideout-form-line-title' title='Modules after this one stay locked until the student reaches the minimum score.'>Block progress until passed</label>
          <input type='checkbox' class='slideout-switch round' name='quiz_block_progress' value='1' ${
            quiz.block_progress ? 'checked' : ''
          }/>
          <small class='desc quiz-block-desc' style='font-size:0.8rem;'></small>
        </div>
        <div class='slideout-form-line quiz-line-reveal'>
          <label class='slideout-form-line-title' title='Students see the correct answer for every question once they submit.'>Reveal correct answers after submitting</label>
          <input type='checkbox' class='slideout-switch round' name='quiz_reveal_answers' value='1' ${
            quiz.reveal_answers ? 'checked' : ''
          }/>
          <small class='desc quiz-reveal-desc' style='font-size:0.8rem;'></small>
        </div>
        <div class='slideout-form-line quiz-questions-line'>
          <label class='slideout-form-line-title'>Questions</label>
          <small class='desc' style='font-size:0.8rem;'>Click the ✓ next to an answer to mark it as the correct one.</small>
          <div class='quiz-builder'></div>
          <button type='button' class='flms-btn quiz-add-question'>${COMMON.icon('plus')}Add question</button>
        </div>
      `,
      type: slideout.types.FORM,
      confirmText: 'Save',
      confirm: async vals => {
        const questions = this.syncQuizQuestions();

        if (vals.quiz_enabled && questions.length === 0) {
          notifications.show('A quiz needs at least one question', 'error');
          return false;
        }

        const invalid = questions.find(
          q => !q.text.trim() || q.options.filter(o => o.trim()).length < 2
        );
        if (invalid) {
          notifications.show('Every question needs text and at least 2 answers', 'error');
          return false;
        }

        const passScore = Math.max(0, Math.min(100, parseInt(vals.quiz_pass_score, 10) || 0));

        const result = await JSUtils.fetch(__futurelms.ajax_url, {
          action: 'edit_quiz',
          module_id: moduleId,
          enabled: vals.quiz_enabled ? '1' : '0',
          title: vals.quiz_title || '',
          pass_score: passScore,
          block_progress: vals.quiz_block_progress ? '1' : '0',
          reveal_answers: vals.quiz_reveal_answers ? '1' : '0',
          questions: JSON.stringify(questions)
        });

        if (!result.error) {
          notifications.show('Quiz saved successfully', 'success');
          this.getCourses();
        } else {
          notifications.show(result.message || 'Failed to save quiz', 'error');
        }
      },
      onClose: () => this.state.set('editing-quiz', false)
    });

    this.wireSlideoutSwitches();

    // The questions builder needs the full panel width — the slideout's
    // two-column auto-layout would otherwise squeeze it into one half.
    const panel = document.querySelector('.slideout-panel');
    const grid = panel?.querySelector('.slideout-form-grid');
    const questionsLine = panel?.querySelector('.quiz-questions-line');
    if (grid && questionsLine) {
      grid.parentNode.appendChild(questionsLine);
    }

    // Blocking is the only setting with a dependency — it needs a pass score
    // to fail against, so its control is hidden without one. Revealing stands
    // on its own and is always offered.
    const passInput = panel?.querySelector('input[name="quiz_pass_score"]');
    const blockInput = panel?.querySelector('input[name="quiz_block_progress"]');
    const revealInput = panel?.querySelector('input[name="quiz_reveal_answers"]');
    const blockDesc = panel?.querySelector('.quiz-block-desc');
    const revealDesc = panel?.querySelector('.quiz-reveal-desc');
    let wasGating = (parseInt(passInput?.value, 10) || 0) > 0;

    const syncQuizMode = () => {
      const score = parseInt(passInput?.value, 10) || 0;
      const gating = score > 0;
      panel?.querySelector('.quiz-line-block')?.classList.toggle('hidden', !gating);

      // Setting a minimum score for the first time turns blocking on. Someone
      // who types "70" means it to matter — leaving it opt-in produced a quiz
      // that scored the student, showed them a red fail, and then waved them
      // through to the next module anyway. Still switchable off for a scored
      // quiz that's deliberately advisory; only the 0 -> N transition flips it.
      if (gating && !wasGating && blockInput && !blockInput.checked) {
        blockInput.checked = true;
      }
      wasGating = gating;

      // Spell out the consequence, so a non-blocking scored quiz is a choice
      // rather than something that got missed.
      if (blockDesc) {
        blockDesc.textContent = blockInput?.checked
          ? `Students scoring under ${score}% can't open anything after this module until they pass.`
          : `Advisory only — students continue to the next module even if they score under ${score}%.`;
      }

      // Reveal + blocking is legal but self-defeating: the student can reset
      // the quiz and retake it with the answer key. Say so at the point of
      // decision instead of silently disallowing the combination.
      const blockingNow = gating && !!blockInput?.checked;
      if (revealDesc) {
        revealDesc.textContent = !revealInput?.checked
          ? 'Currently, students see which answers they picked, but not which were right.'
          : blockingNow
            ? 'Note: this quiz blocks progress, so a student can reset it and retake it with the answers in hand.'
            : 'Currently, students see the correct answer for every question after submitting.';
        revealDesc.classList.toggle('quiz-warn', !!revealInput?.checked && blockingNow);
      }
    };

    passInput?.addEventListener('input', syncQuizMode);
    blockInput?.addEventListener('change', syncQuizMode);
    revealInput?.addEventListener('change', syncQuizMode);
    syncQuizMode();

    panel?.querySelector('.quiz-add-question')?.addEventListener('click', () => {
      const questions = this.syncQuizQuestions();
      questions.push({ id: this.nextQuizQuestionId(questions), text: '', options: ['', ''], correct: 0 });
      this.state.set('quiz-questions', questions);
      this.renderQuizQuestions();
    });

    // One delegated handler for the whole builder — the rows are re-rendered
    // on every structural change, so per-element listeners would be lost.
    panel?.querySelector('.quiz-builder')?.addEventListener('click', e => {
      const action = e.target.closest('[data-quiz-action]')?.dataset.quizAction;
      if (!action) return;

      const questions = this.syncQuizQuestions();
      const questionEl = e.target.closest('.quiz-question');
      const index = parseInt(questionEl?.dataset.index, 10);
      if (isNaN(index)) return;

      switch (action) {
        case 'delete-question':
          questions.splice(index, 1);
          break;
        case 'move-up-question':
          if (index === 0) return;
          [questions[index - 1], questions[index]] = [questions[index], questions[index - 1]];
          break;
        case 'move-down-question':
          if (index >= questions.length - 1) return;
          [questions[index + 1], questions[index]] = [questions[index], questions[index + 1]];
          break;
        case 'add-option':
          questions[index].options.push('');
          break;
        case 'mark-correct':
          questions[index].correct = parseInt(e.target.closest('.quiz-option').dataset.optionIndex, 10);
          break;
        case 'delete-option': {
          const optionIndex = parseInt(e.target.closest('.quiz-option').dataset.optionIndex, 10);
          if (questions[index].options.length <= 2) {
            notifications.show('A question needs at least 2 answers', 'error');
            return;
          }
          questions[index].options.splice(optionIndex, 1);
          // Keep the answer key pointing at the same option after a removal.
          if (questions[index].correct === optionIndex) questions[index].correct = 0;
          else if (questions[index].correct > optionIndex) questions[index].correct--;
          break;
        }
      }

      this.state.set('quiz-questions', questions);
      this.renderQuizQuestions();
    });

    this.renderQuizQuestions();
  };

  /**
   * Folds the free-text values (question text, answer text) back into the
   * questions array and returns it. Called before every re-render and before
   * saving, so nothing typed is ever lost.
   *
   * `correct` is deliberately NOT read back from the DOM: it lives only in
   * this array, set by the mark-correct action. The slideout's
   * close-on-background-click listener runs with captureChildClicks +
   * preventDefault, so every click inside the panel has its default action
   * cancelled — a native radio can never actually become :checked in here.
   * (Same swallowed-click bug wireSlideoutSwitches works around for
   * checkboxes.) Reading the answer key from a control that can't change is
   * how the correct answer silently stayed pinned to the first option.
   */
  syncQuizQuestions = () => {
    const questions = this.state.get('quiz-questions') || [];
    const panel = document.querySelector('.slideout-panel');
    if (!panel) return questions;

    panel.querySelectorAll('.quiz-question').forEach(questionEl => {
      const index = parseInt(questionEl.dataset.index, 10);
      const question = questions[index];
      if (!question) return;

      question.text = questionEl.querySelector('.quiz-question-text')?.value ?? question.text;
      question.options = [...questionEl.querySelectorAll('.quiz-option-text')].map(input => input.value);
    });

    this.state.set('quiz-questions', questions);
    return questions;
  };

  nextQuizQuestionId = questions =>
    questions.reduce((max, question) => Math.max(max, parseInt(question.id, 10) || 0), 0) + 1;

  escapeAttr = value => String(value ?? '').replace(/&/g, '&amp;').replace(/'/g, '&#39;').replace(/"/g, '&quot;');

  renderQuizQuestions = () => {
    const builder = document.querySelector('.slideout-panel .quiz-builder');
    if (!builder) return;

    const questions = this.state.get('quiz-questions') || [];

    if (questions.length === 0) {
      builder.innerHTML = `<p class='quiz-empty'>No questions yet. Add the first one below.</p>`;
      return;
    }

    builder.innerHTML = questions
      .map(
        (question, index) => `
        <div class='quiz-question' data-index='${index}' data-question-id='${question.id}'>
          <div class='quiz-question-head'>
            <span class='quiz-question-num'>${index + 1}</span>
            <input type='text' class='quiz-question-text' placeholder='Question' value='${this.escapeAttr(
              question.text
            )}'/>
            <span class='quiz-question-actions'>
              <button type='button' class='flms-iconbtn' data-quiz-action='move-up-question' aria-label='Move up'>${COMMON.icon('arrow-up')}</button>
              <button type='button' class='flms-iconbtn' data-quiz-action='move-down-question' aria-label='Move down'>${COMMON.icon('arrow-down')}</button>
              <button type='button' class='flms-iconbtn is-danger' data-quiz-action='delete-question' aria-label='Delete question'>${COMMON.icon('trash-2')}</button>
            </span>
          </div>
          <div class='quiz-options'>
            ${question.options
              .map(
                (option, optionIndex) => `
              <div class='quiz-option${optionIndex === question.correct ? ' is-correct' : ''}' data-option-index='${optionIndex}'>
                <button type='button' class='quiz-option-mark' data-quiz-action='mark-correct'
                  aria-pressed='${optionIndex === question.correct}'
                  title='Mark as the correct answer' aria-label='Mark as the correct answer'>${COMMON.icon('check')}</button>
                <input type='text' class='quiz-option-text' placeholder='Answer ${
                  optionIndex + 1
                }' value='${this.escapeAttr(option)}'/>
                <span class='quiz-option-badge'>Correct</span>
                <button type='button' class='flms-iconbtn is-danger' data-quiz-action='delete-option' aria-label='Delete answer'>${COMMON.icon('x')}</button>
              </div>`
              )
              .join('')}
          </div>
          <button type='button' class='flms-btn quiz-add-option' data-quiz-action='add-option'>${COMMON.icon('plus')}Add answer</button>
        </div>`
      )
      .join('');
  };

  /**
   * The presentation is a media attachment id, and the front end turns it into
   * the "לחץ כאן להורדת המצגת של השיעור" link in the materials tab. Without a
   * summary here the picker showed a bare "Select File" button, so an attached
   * presentation was invisible in the editor and impossible to remove.
   */
  presentationSummary = ({ presentation_id, presentation_url, presentation_filename }) => {
    if (!presentation_id) return `<span class='presentation-empty'>No presentation attached</span>`;
    const name = presentation_filename || `Attachment #${presentation_id}`;
    const link = presentation_url
      ? `<a href='${presentation_url}' target='_blank' rel='noopener'>${name}</a>`
      : name;
    return `${link} <button type='button' class='flms-btn remove-presentation'>Remove</button>`;
  };

  editLesson = async (e, lessonId = null, moduleId = null) => {
    if (this.state.get('editing-lesson')) return; // Prevent multiple simultaneous edits
    this.state.set('editing-lesson', true);

    // If lessonId is provided, we're editing; otherwise we're creating
    if (lessonId === null) {
      const lessonEl = e.target.closest('.module-lesson');
      if (lessonEl) {
        lessonId = lessonEl.dataset.lessonId;
      }
    }

    const courseEl = e.target.closest('.course');
    const courseId = courseEl?.dataset?.courseId;

    let lesson = null;
    if (lessonId) {
      lesson = await JSUtils.fetch(__futurelms.ajax_url, {
        action: 'get_lesson_details',
        lesson_id: lessonId
      });
    } else {
      lesson = {
        error: false,
        name: '',
        teaser: '',
        lesson_number: 1,
        presentation_id: 0,
        videos: [],
        homework: '',
        additional_files: '',
        module_id: moduleId
      };
    }

    if (lesson.error) {
      this.state.set('editing-lesson', false);
      notifications.show(lesson.message || 'Failed to load lesson', 'error');
      return;
    }

    const videosToTextarea = arr => (arr && arr.length ? arr.join('\n') : '');

    slideout.show({
      title: lessonId ? `Edit Lesson ${lesson.lesson_number}` : 'Add Lesson',
      message: `
        ${lessonId ? `<div class='slideout-form-line slideout-id-row'>
          <span class='entity-id-label'>Course #${courseId || '?'} · Module #${lesson.module_id || '?'} · Lesson #${lessonId}</span>
        </div>` : ''}
        <div class='slideout-form-line'>
          <label class='slideout-form-line-title'>Lesson name</label>
          <input type='text' name='lesson_name' value='${lesson.name || ''}'/>
        </div>
        <div class='slideout-form-line'>
          <label class='slideout-form-line-title'>Module</label>
          <select name='lesson_module' class='slideout-form-select'>
            <option value=''>Loading...</option>
          </select>
        </div>
        <div class='slideout-form-line'>
          <label class='slideout-form-line-title'>Teaser</label>
          <input type='text' name='lesson_teaser' value='${lesson.teaser || ''}'/>
        </div>
        <div class='slideout-form-line'>
          <label class='slideout-form-line-title'>Presentation</label>
          <div class='presentation-picker'>
            <input type='hidden' name='lesson_presentation' value='${lesson.presentation_id || 0}' />
            <div class='presentation-current'>${this.presentationSummary(lesson)}</div>
            <button type='button' class='flms-btn select-presentation'>${lesson.presentation_id ? 'Replace File' : 'Select File'}</button>
          </div>
        </div>
        <div class='slideout-form-line'>
          <label class='slideout-form-line-title'>Videos (one per line: Vimeo ID or link)</label>
          <textarea name='lesson_videos' style='height:120px;'>${videosToTextarea(lesson.videos)}</textarea>
        </div>
        <div class='slideout-form-line'>
          <label class='slideout-form-line-title'>Lesson Content</label>
          <textarea class='trumbo' name='lesson_content' style='height:250px;'></textarea>
        </div>
        <div class='slideout-form-line'>
          <label class='slideout-form-line-title'>Homework</label>
          <textarea class='trumbo' name='lesson_homework' style='height:180px;'></textarea>
        </div>
        <div class='slideout-form-line'>
          <label class='slideout-form-line-title'>Additional files</label>
          <textarea class='trumbo' name='lesson_additional_files' style='height:180px;'></textarea>
        </div>
      `,
      type: slideout.types.FORM,
      confirmText: lessonId ? 'Save' : 'Create Lesson',
      confirm: async vals => {
        if (!vals.lesson_name?.length) {
          notifications.show('Lesson name cannot be empty', 'error');
          return false;
        }

        const videos = (vals.lesson_videos || '')
          .split(/\r?\n/)
          .map(v => v.trim())
          .filter(Boolean)
          .map(v => ({ video_id: COMMON.vimeoId(v) || v }));

        const $ = window.jQuery;
        const trumbo = $ && $.fn.trumbowyg;
        const contentHtml = trumbo
          ? jQuery('.trumbo[name="lesson_content"]').trumbowyg('html')
          : vals.lesson_content || '';
        const homeworkHtml = trumbo
          ? jQuery('.trumbo[name="lesson_homework"]').trumbowyg('html')
          : vals.lesson_homework || '';
        const additionalHtml = trumbo
          ? jQuery('.trumbo[name="lesson_additional_files"]').trumbowyg('html')
          : vals.lesson_additional_files || '';

        const payload = {
          action: 'edit_lesson',
          lesson_id: lessonId,
          module_id: vals.lesson_module || lesson.module_id,
          lesson_number: vals.lesson_number,
          name: vals.lesson_name,
          teaser: vals.lesson_teaser || '',
          video_list: JSON.stringify(videos),
          content: contentHtml,
          homework: homeworkHtml,
          additional_files: additionalHtml,
          presentation: vals.lesson_presentation || 0
        };

        const result = await JSUtils.fetch(__futurelms.ajax_url, payload);
        if (!result.error) {
          notifications.show(lessonId ? 'Lesson updated successfully' : 'Lesson created successfully', 'success');
          this.getCourses();
        } else {
          notifications.show(
            result.message || (lessonId ? 'Failed to update lesson' : 'Failed to create lesson'),
            'error'
          );
        }
      },
      onClose: () => this.state.set('editing-lesson', false)
    });

    const select = document.querySelector('.slideout-form-select[name="lesson_module"]');
    if (select) {
      const courses = this.state.get('courses');
      const currentCourse = courses[courseId];
      const options = Object.keys(currentCourse.modules)
        .map(mid => ({ id: mid, name: currentCourse.modules[mid].name }))
        .sort((a, b) => a.name.localeCompare(b.name));
      select.innerHTML = options
        .map(
          o =>
            `<option value="${o.id}" ${String(o.id) === String(lesson.module_id) ? 'selected' : ''}>${o.name}</option>`
        )
        .join('');
    }

    if (window.jQuery && jQuery.fn.trumbowyg) {
      jQuery('.trumbo').trumbowyg({ lang: 'he' });
      jQuery('.trumbo[name="lesson_content"]').trumbowyg('html', lesson.content || '');
      jQuery('.trumbo[name="lesson_homework"]').trumbowyg('html', lesson.homework || '');
      jQuery('.trumbo[name="lesson_additional_files"]').trumbowyg('html', lesson.additional_files || '');
    }

    const picker = document.querySelector('.presentation-picker');
    if (picker) {
      const input = picker.querySelector('input[name="lesson_presentation"]');
      const summary = picker.querySelector('.presentation-current');
      const btn = picker.querySelector('.select-presentation');

      const renderPresentation = attachment => {
        input.value = attachment?.id || 0;
        summary.innerHTML = this.presentationSummary({
          presentation_id: attachment?.id || 0,
          presentation_url: attachment?.url || '',
          presentation_filename: attachment?.filename || ''
        });
        btn.textContent = attachment?.id ? 'Replace File' : 'Select File';
      };

      picker.addEventListener('click', e => {
        if (e.target.closest('.remove-presentation')) renderPresentation(null);
      });

      if (window.wp && wp.media && btn) {
        btn.addEventListener('click', () => {
          const frame = wp.media({ title: 'Select Presentation', multiple: false });
          frame.on('select', () => {
            renderPresentation(frame.state().get('selection').first().toJSON());
          });
          frame.open();
        });
      }
    }
  };

  addLesson = e => {
    const module = e.target.closest('.course-module');
    const moduleId = module.dataset.moduleId;
    this.editLesson(e, null, moduleId);
  };

  addCourse = () => {
    this.editCourse(null);
  };

  addClass = () => {
    this.editClass(null);
  };

  updateCourse = e => {
    const course = e.target.closest('.course');
    const courseId = course.dataset.courseId;

    this.editCourse(courseId);
  };

  changeLessonStatus = (e, status) => {
    const moduleId = e.target.closest('.course-module').dataset.moduleId;
    const lessonId = e.target.closest('.module-lesson').dataset.lessonId;

    remodaler.show({
      title: 'Change lesson status',
      message: `Are you sure you want to ${status} this lesson?`,
      type: remodaler.types.CONFIRM,
      confirmText: 'Yes',
      confirm: () => {
        JSUtils.fetch(__futurelms.ajax_url, {
          action: 'change_lesson_status',
          lesson_id: lessonId,
          module_id: moduleId,
          status: status
        }).then(data => {
          if (!data.error) {
            this.getCourses();
          }
        });
      }
    });
  };

  changeModuleStatus = (e, status) => {
    const courseId = e.target.closest('.course').dataset.courseId;
    const module = e.target.closest('.course-module');
    const moduleId = module.dataset.moduleId;

    remodaler.show({
      title: 'Change module status',
      message: `Are you sure you want to ${status} this module?`,
      type: remodaler.types.CONFIRM,
      confirmText: 'Yes',
      confirm: () => {
        JSUtils.fetch(__futurelms.ajax_url, {
          action: 'change_module_status',
          module_id: moduleId,
          course_id: courseId,
          status: status
        }).then(data => {
          if (!data.error) {
            this.getCourses();
          }
        });
      }
    });
  };

  changeCourseStatus = (e, status) => {
    const course = e.target.closest('.course');
    const courseId = course.dataset.courseId;

    //make sure we want to change the status
    remodaler.show({
      title: 'Change course status',
      message: `Are you sure you want to ${status} this course?`,
      type: remodaler.types.CONFIRM,
      confirmText: 'Yes',
      confirm: () => {
        JSUtils.fetch(__futurelms.ajax_url, {
          action: 'change_course_status',
          course_id: courseId,
          status: status
        }).then(data => {
          if (!data.error) {
            this.getCourses();
          }
        });
      }
    });
  };

  editCourse = courseId => {
    const course = courseId ? this.state.get('courses')[courseId] : null;
    slideout.show({
      title: courseId ? 'Edit Course' : 'Add Course',
      message: `<div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Course name</label>
        <input type='text' name='course_name' value='${course?.name || ''}'/>
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Course Code</label>
        <input type='text' name='course_code' value='${course?.course_code || ''}' />
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Course Content</label>
        <textarea name='course_post_content' style='height:200px;'>${course?.post_content || ''}</textarea>
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Short Description</label>
        <textarea name='course_short_description' style='height:100px;'>${course?.short_description || ''}</textarea>
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>What You will Learn</label>
        <textarea name='course_what_you_learn' style='height:100px;'>${course?.what_you_learn || ''}</textarea>
        <small class='desc' style='font-size:0.8rem;'>Please enter each point on a new line.</small>
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Full Price</label>
        <input type='number' name='course_full_price' value='${course?.full_price || ''}' step='0.01' />
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Discount Price</label>
        <input type='number' name='course_discount_price' value='${course?.discount_price || ''}' step='0.01' />
        <small class='desc' style='font-size:0.8rem;'>Leave empty for no discount.</small>
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Max Installments</label>
        <input type='number' name='course_maxpay' min='1' max='36' value='${course?.maxpay || ''}' placeholder='1' />
        <small class='desc' style='font-size:0.8rem;'>Leave empty for default (1 = single payment).</small>
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Tags (comma separated)</label>
        <input type='text' name='course_tags' value='${course?.tags || ''}' />
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Featured Image</label>
        <div class='flms-image-picker featured-image-picker ${course?.course_image ? 'has-image' : ''}'>
          <input type='hidden' name='course_image' value='${course?._thumbnail_id || 0}' />
          <div class='flms-image-picker-filled'>
            <img class='flms-image-picker-preview featured-image-preview' alt='' ${
              course?.course_image ? `src='${course.course_image}'` : ''
            } />
            <div class='flms-image-picker-actions'>
              <button type='button' class='flms-btn select-course-image'>Replace</button>
              <button type='button' class='flms-btn remove-course-image'>Remove</button>
            </div>
          </div>
          <div class='flms-image-picker-empty'>
            <svg width='28' height='28' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'>
              <rect x='3' y='4' width='18' height='16' rx='2'></rect>
              <circle cx='8.5' cy='9.5' r='1.5'></circle>
              <path d='M21 15l-5-5L5 20'></path>
            </svg>
            <button type='button' class='flms-btn select-course-image'>Select image</button>
            <span class='flms-image-picker-hint'>Students see the default course image when this is empty</span>
          </div>
        </div>
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Color</label>
        <span style="display: flex; align-items: center; gap: 16px;">
          <input type='color' name='course_color' value='${
            course?.color || '#aabbcc'
          }' style="position: relative; z-index: 9999;" />
        </span>
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Default Class</label>
        <select name="course_default_class" class="course-default-class slideout-form-select">
          <option value="">No default class selected</option>
        </select>
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Initial Student Count</label>
        <input type='number' name='initial_student_count' min='0' value='${course?.initial_student_count || '0'}' />
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Enable Diploma</label>
        <input type='checkbox' class='slideout-switch round' name='diploma_enabled' value='1' ${course?.diploma_enabled === '1' ? 'checked' : ''} />
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title' title='Lock each counted lesson until the previous counted lesson is complete. Non-counting (intro) lessons stay always open.'>Sequential Progress</label>
        <input type='checkbox' class='slideout-switch round' name='sequential_progress' value='1' ${course?.sequential_progress === '1' ? 'checked' : ''} />
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Lecturer Name</label>
        <input type='text' name='lecturer_name' value='${course?.lecturer_name || ''}' />
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Lecturer Signature</label>
        <div class='signature-picker'>
          <input type='hidden' name='lecturer_signature' value='${course?.lecturer_signature || 0}' />
          <div class='signature-preview'>
            ${course?.lecturer_signature_url ? `<img src='${course.lecturer_signature_url}' style='max-width:150px;max-height:80px;' />` : ''}
          </div>
          <button type='button' class='flms-btn select-signature'>Select Image</button>
          <button type='button' class='flms-btn remove-signature' style='display: ${
            course?.lecturer_signature_url ? 'inline-block' : 'none'
          };'>Remove</button>
        </div>
      </div>`,
      type: slideout.types.FORM,
      confirmText: courseId ? 'Update' : 'Create',
      confirm: async vals => {
        if (!vals.course_name?.length) {
          notifications.show('Course name cannot be empty', 'error');
          return false;
        }

        var data = {
          action: 'edit_course',
          course_id: courseId || '',
          name: vals.course_name,
          course_code: vals.course_code || '',
          post_content: vals.course_post_content || '',
          short_description: vals.course_short_description || '',
          what_you_learn: vals.course_what_you_learn || '',
          tags: vals.course_tags || '',
          full_price: vals.course_full_price || '',
          discount_price: vals.course_discount_price || '',
          maxpay: vals.course_maxpay || '',
          color: vals.course_color || '#aabbcc',
          course_image: vals.course_image || 0,
          default_class: vals.course_default_class,
          initial_student_count: vals.initial_student_count || '0',
          diploma_enabled: vals.diploma_enabled ? '1' : '0',
          sequential_progress: vals.sequential_progress ? '1' : '0',
          lecturer_name: vals.lecturer_name || '',
          lecturer_signature: vals.lecturer_signature || 0
        };

        let result = await JSUtils.fetch(__futurelms.ajax_url, data);
        if (!result.error) {
          window.notifications.show('Course saved successfully', 'success');
          this.getCourses();
        } else {
          window.notifications.show(result.message || 'Failed to save course', 'error');
        }
      }
    });

    if (courseId) {
      JSUtils.fetch(__futurelms.ajax_url, {
        action: 'search_classes',
        course_id: courseId
      }).then(data => {
        let select = document.querySelector('.slideout-panel select.course-default-class');

        data.results.forEach(item => {
          let option = document.createElement('option');
          option.value = item.id;
          option.textContent = item.title;

          if (course?.default_class && parseInt(course.default_class) === parseInt(item.id)) {
            option.selected = true;
          }

          select.appendChild(option);
        });
      });
    }

    this.wireSlideoutSwitches();

    // init color picker
    document
      .querySelector('.slideout-panel input[name="course_color"]')
      ?.addEventListener('click', e => e.stopPropagation(), true);

    // init featured image picker
    this.initFeaturedImagePicker();

    // init signature image picker
    this.initSignaturePicker();
  };

  refreshLessonDuration = async (icon, reloadTable = false) => {
    const lessonEl = icon.closest('.module-lesson');
    const lessonId = lessonEl.dataset.lessonId;
    const videoIds =
      icon.dataset.content
        ?.split(',')
        .map(id => id.trim())
        .filter(Boolean) || [];

    if (icon.classList.contains('is-busy')) return;

    console.log(`[Duration] Lesson ${lessonId} — videoIds:`, videoIds);
    console.log(`[Duration] Vimeo SDK loaded:`, !!window.Vimeo);
    icon.classList.remove('is-done');
    icon.classList.add('is-busy');

    try {
      if (videoIds.length === 0) {
        console.log(`[Duration] No videos for lesson ${lessonId}, clearing duration`);
        await JSUtils.fetch(__futurelms.ajax_url, {
          action: 'set_lesson_duration',
          lesson_id: lessonId
        });
      } else {
        for (const videoId of videoIds) {
          console.log(`[Duration] Creating iframe for video ${videoId}`);
          const iframe = document.createElement('iframe');
          iframe.src = `https://player.vimeo.com/video/${videoId}?background=1&autoplay=1&muted=1`;
          Object.assign(iframe.style, { width: '0', height: '0', border: '0' });
          document.body.appendChild(iframe);

          let duration = 0;
          try {
            const player = new Vimeo.Player(iframe);
            console.log(`[Duration] Vimeo player created for ${videoId}, fetching duration...`);
            duration = await player.getDuration();
            console.log(`[Duration] Video ${videoId} duration: ${duration}s (${Math.floor(duration / 60)}m ${Math.floor(duration % 60)}s)`);
          } catch (err) {
            console.warn(`[Duration] Skipping video ${videoId} (private or not found)`, err);
          } finally {
            console.log(`[Duration] Saving video ${videoId} duration=${Math.floor(duration)} to lesson ${lessonId}`);
            const saveResult = await JSUtils.fetch(__futurelms.ajax_url, {
              action: 'set_video_duration',
              lesson_id: lessonId,
              video_id: videoId,
              duration: Math.floor(duration)
            });
            console.log(`[Duration] Save result for ${videoId}:`, saveResult);
            iframe.remove();
          }
        }
      }

      if (reloadTable) {
        console.log(`[Duration] Recalculating course duration for course ${lessonEl.dataset.courseId}`);
        const result = await JSUtils.fetch(__futurelms.ajax_url, {
          action: 'set_course_duration',
          course_id: lessonEl.dataset.courseId
        });
        console.log(`[Duration] Course duration result:`, result);
        if (!result.error) {
          this.getCourses();
        }
      }
    } catch (e) {
      console.error('[Duration] Failed updating durations', e);
    } finally {
      icon.classList.remove('is-busy');
      icon.classList.add('is-done');
      icon.setAttribute('data-tip', 'Durations fetched');
      console.log(`[Duration] Done for lesson ${lessonId}`);
    }
  };

  refreshAllLessonDurations = async e => {
    const icon = e.target.closest('[data-action="refresh-course-durations"]');
    if (!icon) return;

    icon.classList.add('is-busy');
    const courseId = icon.dataset.courseId;
    const courseEl = icon.closest('.course');

    if (!courseEl) return;

    const icons = courseEl.querySelectorAll('.refresh-durations');
    const total = icons.length;

    let current = 0;
    this.updateProgressBar(courseEl, courseId, current, total);

    for (const refreshIcon of icons) {
      await this.refreshLessonDuration(refreshIcon);
      current++;
      this.updateProgressBar(courseEl, courseId, current, total);
    }

    await this.refreshCourseDuration(courseId);
    icon.classList.remove('is-busy');
  };

  refreshCourseDuration = async courseId => {
    try {
      const result = await JSUtils.fetch(__futurelms.ajax_url, {
        action: 'set_course_duration',
        course_id: courseId
      });
      if (!result.error) {
        this.getCourses();
      }
    } catch (error) {
      console.error('AJAX error:', error);
    }
  };

  formatDuration = seconds => {
    seconds = Math.max(0, Math.floor(seconds));

    const hrs = Math.floor(seconds / 3600);
    const mins = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;

    const hh = hrs.toString().padStart(2, '0');
    const mm = mins.toString().padStart(2, '0');
    const ss = secs.toString().padStart(2, '0');

    return `${hh}:${mm}:${ss}`;
  };

  updateProgressBar = (courseEl, courseId, current, total) => {
    const progressContainer = courseEl.querySelector(`#course-duration-${courseId}`);
    if (!progressContainer) return;

    if (!progressContainer.querySelector('.progress-bar')) {
      progressContainer.innerHTML = `
      <span class="inline-flex items-center gap-2"><span class="progress-text">Refreshing 0/${total}</span>
      <span class="flms-bar w-24 inline-block"><span class="progress-bar" style="width:0%"></span></span></span>`;
    }

    const progressText = progressContainer.querySelector('.progress-text');
    const progressBar = progressContainer.querySelector('.progress-bar');

    const percent = Math.round((current / total) * 100);
    progressText.textContent =
      current === total ? `All ${total} lessons refreshed` : `Refreshing ${current}/${total}`;
    progressBar.style.width = `${percent}%`;
  };

  loadVimeoSDK = () => {
    if (!window.Vimeo) {
      const tag = document.createElement('script');
      tag.src = 'https://player.vimeo.com/api/player.js';
      document.head.appendChild(tag);
    }
  };

  /**
   * Shows an attachment in the picker, or clears it. Both states are in the
   * markup and swapped by the has-image class, as in the settings tab — so the
   * form does not reflow when an image is chosen or cleared.
   */
  setFeaturedImage = (id, url) => {
    const picker = document.querySelector('.featured-image-picker');
    const input = document.querySelector('input[name="course_image"]');
    const preview = document.querySelector('.featured-image-preview');
    if (!picker || !input || !preview) return;

    input.value = id || 0;
    // An empty src would re-request the page itself, so drop the attribute.
    if (url) preview.src = url;
    else preview.removeAttribute('src');
    picker.classList.toggle('has-image', !!url);
  };

  initFeaturedImagePicker = () => {
    if (!window.wp || !window.wp.media) return;

    // Two triggers — "Select image" on the empty state, "Replace" over the
    // image — opening the same frame.
    document.querySelectorAll('.select-course-image').forEach(button => {
      button.addEventListener('click', () => {
        const frame = wp.media({
          title: 'Select Featured Image',
          multiple: false,
          library: { type: 'image' }
        });

        frame.on('select', () => {
          const attachment = frame.state().get('selection').first().toJSON();
          this.setFeaturedImage(attachment.id, attachment.url);
        });

        frame.open();
      });
    });

    document.querySelector('.remove-course-image')?.addEventListener('click', () => this.setFeaturedImage(0, ''));
  };

  initSignaturePicker = () => {
    if (!window.wp || !window.wp.media) return;

    const selectBtn = document.querySelector('.select-signature');
    const removeBtn = document.querySelector('.remove-signature');

    if (selectBtn) {
      selectBtn.addEventListener('click', () => {
        const frame = wp.media({
          title: 'Select Signature Image',
          multiple: false,
          library: { type: 'image' }
        });

        frame.on('select', () => {
          const attachment = frame.state().get('selection').first().toJSON();
          const input = document.querySelector('input[name="lecturer_signature"]');
          const preview = document.querySelector('.signature-preview');

          if (input) input.value = attachment.id;
          if (preview) {
            preview.innerHTML = `<img src='${attachment.url}' style='max-width:150px;max-height:80px;' />`;
          }
          if (removeBtn) removeBtn.style.display = 'inline-block';
        });

        frame.open();
      });
    }

    if (removeBtn) {
      removeBtn.addEventListener('click', () => {
        const input = document.querySelector('input[name="lecturer_signature"]');
        const preview = document.querySelector('.signature-preview');

        if (input) input.value = '0';
        if (preview) preview.innerHTML = '';
        removeBtn.style.display = 'none';
      });
    }
  };

  editClass = async classId => {
    const selectedClass = classId ? this.state.get('classes')[classId] : null;

    const formatDateTimeForInput = dateString => {
      if (!dateString) return '';
      try {
        const date = new Date(dateString);

        if (isNaN(date.getTime())) return '';

        const pad = num => num.toString().padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(
          date.getMinutes()
        )}`;
      } catch (e) {
        return '';
      }
    };

    slideout.show({
      title: classId ? 'Edit Class' : 'Add Class',
      message: `
        <div class='slideout-form-line'>
            <label class='slideout-form-line-title'>Class Name</label>
            <input type='text' name='class_name' value='${selectedClass?.name || ''}'/>
        </div>
        <div class='slideout-form-line'>
            <label class='slideout-form-line-title'>Select Course</label>
            <select name="class_course_id" class="slideout-form-select" disabled>
                <option value="">Loading courses...</option>
            </select>
            <div class="slideout-loading-indicator"></div>
        </div>
            <div class='slideout-form-line'>
            <label class='slideout-form-line-title'>Start Date & Time</label>
            <input type="datetime-local" 
                   name="class_start_date" 
                   value="${formatDateTimeForInput(selectedClass?.start_date)}" 
                   class="slideout-datetime-input"
                   step="300" min="${new Date().toISOString().slice(0, 16)}">
        </div>
        <div class='slideout-form-line'>
            <label class='slideout-form-line-title' title='Paced: each lesson opens on a date you set in the Classes tab.'>Pacing</label>
            <select name="class_paced" class="slideout-form-select">
                <option value="0" ${selectedClass?.paced ? '' : 'selected'}>Self-paced - every lesson open</option>
                <option value="1" ${selectedClass?.paced ? 'selected' : ''}>Paced - lessons open on set dates</option>
            </select>
        </div>`,
      type: slideout.types.FORM,
      confirmText: classId ? 'Update' : 'Create',
      confirm: async vals => {
        var data = {
          action: 'edit_class',
          class_id: classId || '',
          course_id: vals.class_course_id,
          name: vals.class_name,
          start_date: vals.class_start_date,
          paced: vals.class_paced
        };

        let result = await JSUtils.fetch(__futurelms.ajax_url, data);
        if (!result.error) {
          //show success notification
          window.notifications.show('Class saved successfully', 'success');
          this.getCourses(); // Refresh the courses data
        } else {
          notifications.show(result.message || 'Failed to save class', 'error');
        }
      }
    });

    const courses = this.state.get('courses');

    const coursesArray = Object.keys(courses).map(id => ({
      id: id,
      ...courses[id]
    }));

    const select = document.querySelector('.slideout-panel select[name="class_course_id"]');
    if (select) {
      select.innerHTML = `
                <option value="">-- Select a Course --</option>
                    ${coursesArray
                      .map(
                        course =>
                          `<option value="${course.id}" ${selectedClass?.id === course.id ? 'selected' : ''}>
                        ${course.name}
                </option>`
                      )
                      .join('')}`;
      select.disabled = false;
    }
  };
}
