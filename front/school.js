console.log('school.js loaded');

const callServer = async params => {
  try {
    let result = await JSUtils.fetch(window.school_info.ajax_url, params);
    if (result.error) throw new Error(result.error);
    return result;
  } catch (ex) {
    remodaler.show({
      title: 'שגיאה',
      message: 'המערכת נכשלה בקריאה לשרת, אנא התנתק והתחבר מחדש',
      type: remodaler.types.ALERT,
      confirmText: 'התנתק',
      confirm: () => {
        document.location.href = '/login';
      }
    });
  }
};

class Lobby {
  init = async () => {
    const coursePage = document.querySelector('.school-container.lobby');
    if (!coursePage) return;

    //documenting script params
    const url = new URL(_currentScript.getAttribute('src'));
    const scriptParams = Object.fromEntries(url.searchParams);
    console.log('lobby initializing', scriptParams);

    this.state = StateManagerFactory();

    let courseId = coursePage.getAttribute('course-id');

    let forms = document.querySelectorAll('.course-entry-form');

    forms.forEach(form => {
      let lesson = form.querySelector("input[name='lesson_id']");

      let progress = localStorage.getItem('course_progress');

      if (!progress) return;

      progress = JSON.parse(progress);

      if (!progress[courseId]) return;

      lesson.value = progress[courseId];
    });

    let progress = localStorage.getItem('course_progress');

    if (!progress) progress = {};
    else progress = JSON.parse(progress);

    progress[courseId] = coursePage.getAttribute('lesson-id');
    localStorage.setItem('course_progress', JSON.stringify(progress));

    this.loadProgress();
  };

  loadProgress = async () => {
    var progress = await callServer({
      action: 'get_student_progress'
    });
    this.state.set('student-progress', progress);

    let courseBtns = document.querySelectorAll(`.course-card.active-course .course-progress-bar`);
    courseBtns.forEach(btn => btn.classList.add('hidden'));

    Object.keys(progress.progress).forEach(courseId => {
      if (!progress.course_tree[courseId]) return;

      let total = Math.min(100, progress.progress[courseId].percent);

      let prog = document.querySelector(
        `.my-courses .course-card[data-course-id='${courseId}'] .course-progress-bar`
      );
      if (prog) {
        prog.classList.remove('hidden');
        prog.innerText = total === 0 ? 'טרם התחלת את הקורס' : `סיימת בהצלחה כבר ${total.toFixed(0)}% מהקורס`;
        prog.style.background = `linear-gradient(to left, #46da9c ${total}%, transparent ${total}%)`;
      }
    });

    console.log('progress is ', progress);
  };
}

class Classroom {
  state = null;

  //call only once
  init = async () => {
    let coursePage = document.querySelector('.school-container.classroom');
    if (!coursePage) return;

    console.log('classroom initializing');
    this.state = StateManagerFactory();

    this.state.set('coursePage', coursePage);

    let classId = parseInt(coursePage.getAttribute('class-id'));
    this.state.set('classId', classId);
    let courseId = parseInt(coursePage.getAttribute('course-id'));
    this.state.set('courseId', courseId);

    const lessonId = this.determineInitialLesson();
    this.state.set('lesson', { id: lessonId });

    let sidebar = coursePage.querySelector('.school-sidebar');
    this.state.set('sidebar', sidebar);

    // Show fallback buttons if loading takes too long
    setTimeout(() => {
      const fallback = document.getElementById('classroom-loader-fallback');
      if (fallback) fallback.classList.add('show');
    }, 10000);

    const autoAdvance = localStorage.getItem('auto_advance_videos') === 'true';
    this.state.set('auto-advance', autoAdvance);
    const autoplayIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"></polygon><line x1="19" y1="3" x2="19" y2="21"></line></svg>`;
    const exitIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>`;

    new Dropdown(
      '.course-options',
      [
        {
          text: () => {
            let autoAdvance = this.state.get('auto-advance');
            return `${autoplayIcon}${autoAdvance ? '✔️ ' : ''}ניגון סרטונים אוטומטי`;
          },
          action: () => {
            let autoAdvance = this.state.get('auto-advance');
            this.state.set('auto-advance', !autoAdvance);
            localStorage.setItem('auto_advance_videos', !autoAdvance);
            return false;
          }
        },
        {
          text: () => `${exitIcon}חזרה לאיזור תלמידים`,
          action: () => {
            document.location.href = '/lobby';
          }
        }
      ],
      { class: 'rtl' }
    );

    let navs = document.querySelectorAll('.lesson-materials-nav li');
    navs.forEach(nav => {
      if (nav.classList.contains('toggle-videos')) return;
      nav.addEventListener('click', e => this.state.set('tab', e.target.getAttribute('tab-id')));
    });

    // Delegated handler: covers the static floating button under the video
    // AND the inline `דלג` button that showSidebar injects per lesson row.
    // (Delegation is required for the sidebar buttons because those rows are
    // built after init runs.) Bubbling will also hit the per-row click
    // handler attached in showSidebar — changeLesson bails out when the
    // click was on .skip-lesson so we don't navigate AND skip.
    JSUtils.addGlobalEventListener(coursePage, '.skip-lesson', 'click', () => this.skipLesson());

    coursePage.querySelector('.toggle-videos').addEventListener('click', this.enlargeMaterials);
    document.querySelectorAll('.nav-lessons').forEach(nav =>
      nav.addEventListener('click', () => {
        this.toggleMobileSidebar(true);
      })
    );

    coursePage.querySelector('.close-sidebar').addEventListener('click', () => this.toggleMobileSidebar(false));

    coursePage.querySelector('.next-video').addEventListener('click', () => {
      this.promoteVideo(1);
    });
    coursePage.querySelector('.prev-video').addEventListener('click', () => {
      this.promoteVideo(-1);
    });


    //listen to student note changes
    JSUtils.addGlobalEventListener(coursePage, '.student-notes', 'input', e => {
      let notesTimer = this.state.get('notes-timer');
      if (notesTimer) clearTimeout(notesTimer);
      notesTimer = setTimeout(() => {
        const notes = e.target.innerText;

        //updating local data store
        let lesson = this.state.get('lesson') || {};
        lesson.studentNotes = notes;
        this.state.set('lesson', lesson);

        //do save only after a split of a second without change, to save on server calls.
        callServer({
          action: 'set_student_notes',
          lesson_id: lesson.id,
          notes: notes
        });
      }, 500);
      this.state.set('notes-timer', notesTimer);
    });

    //add listeners
    this.state.listen('tab', this.showLessonTab);
    this.state.listen('show-videos', this.showVideos);
    this.state.listen('curr-video', this.loadCurrentVideo);
    this.state.listen('lesson', async (val, old) => {
      if (val.id !== old.id) {
        this.lastProgressCall = null;
        await this.loadLesson();
        this.showLessonTab('content');
      }
    });

    // Delegated because the quiz body is re-rendered on every state change
    // (answering, submitting, resetting).
    JSUtils.addGlobalEventListener(coursePage, '.quiz-submit', 'click', () => this.submitQuiz());
    JSUtils.addGlobalEventListener(coursePage, '.quiz-reset', 'click', () => this.resetQuiz());
    JSUtils.addGlobalEventListener(coursePage, '.quiz-next-lesson', 'click', () =>
      this.openLesson(this.nextLessonAfterQuiz(this.state.get('quiz')?.module_id))
    );
    JSUtils.addGlobalEventListener(coursePage, '.quiz-answer input[type="radio"]', 'change', e =>
      this.recordQuizAnswer(e.target)
    );

    await this.loadLessons();
    await this.loadQuizzes();
    this.state.listen('student-progress', this.showSidebar);
    await this.loadProgress(); //need lesson data

    let selected = coursePage.querySelector('.sidebar-lesson.selected');
    if (selected) {
      selected.closest('.sidebar-module').classList.add('open');
      selected.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }
    window.scrollTo(0, 0);

    await this.loadLesson();

    // Dismiss loading overlay
    const loader = document.getElementById('classroom-loader');
    if (loader) {
      loader.classList.add('hidden');
      setTimeout(() => loader.remove(), 300);
    }
  };

  enlargeMaterials = e => {
    let show = this.state.get('show-videos');
    this.state.set('show-videos', !show);
    e.target.closest('.toggle-videos').classList.toggle('rotated');
  };

  promoteVideo = add => {
    let lesson = this.state.get('lesson');
    if (!lesson.videos?.length) return;

    let coursePage = this.state.get('coursePage');
    let current = this.state.get('curr-video');

    if (current + add < 0 || current + add >= lesson.videos.length) return;

    const lessonTitles = coursePage.querySelectorAll('.current-lesson-title .lesson-title');
    lessonTitles.forEach(title => (title.innerText = `${lesson.title} (${current + 1 + add}/${lesson.videos.length})`));

    const multiIndication = coursePage.querySelector('.lesson-videos .current-lesson-title .multiple-video-indication');
    multiIndication.innerText = 'שים לב, לשיעור זה מספר סרטונים';
    multiIndication.classList.remove('hidden');
    setTimeout(() => multiIndication.classList.add('hidden'), 8000);

    this.state.set('curr-video', current + add);
  };

  showVideos = show => {
    let coursePage = this.state.get('coursePage');

    if (show) {
      coursePage.querySelector('.lesson').classList.remove('no-videos');
    } else {
      coursePage.querySelector('.lesson').classList.add('no-videos');
    }
  };

  loadProgress = async () => {
    let progress = await callServer({
      action: 'get_student_progress',
      course_id: this.state.get('courseId')
    });
    this.state.set('student-progress', progress);
    console.log('progress is ', progress);
  };

  loadLesson = async () => {
    let coursePage = this.state.get('coursePage');
    let lesson = this.state.get('lesson');

    //loading a lesson always leaves the quiz view
    this.setQuizMode(false);

    let courseData = this.state.get('course-data');
    let idx = courseData.findIndex(ld => ld.id === lesson.id);

    //in case the course doesn't contain the saved lesson (removed lesson), we loose the progress.
    if (idx === -1) {
      this.state.set('lesson', { id: courseData[0].id });
      return;
    }

    // Defensive: if the requested lesson is locked (e.g. a stale
    // localStorage value pointing at a now-gated lesson, or a returning
    // student in a course that became sequential after their last visit),
    // bounce them to the open gate lesson instead. Without this, the
    // server-side get_lesson_content refuses to serve and the auto-save in
    // loadCurrentVideo would attempt to mark the locked text lesson 100%,
    // re-unlocking everything via implicit-completion.
    if (courseData[idx].open === false) {
      const gate = courseData.find(l => l.open === true && l.count_progress);
      const fallback = gate?.id ?? courseData[0].id;
      console.log(`lesson ${lesson.id} is locked, redirecting to gate ${fallback}`);
      this.persistCurrentLesson(fallback);
      this.state.set('lesson', { id: fallback });
      return;
    }

    if (!courseData[idx].loaded) {
      console.log(`loading lesson ${lesson.id}`);
      const lessonData = await callServer({
        action: 'get_lesson_content',
        course_id: this.state.get('courseId'),
        lesson_id: lesson.id
      });

      //get extended data of this lesson and merge with received information
      lesson = { ...courseData[idx], ...lessonData, loaded: true };
      courseData[idx] = lesson;

      //set the lesson title on screen
      coursePage.querySelector('.lesson-materials .lesson-title').innerText = lesson.title;

      this.state.set('course-data', courseData);
      this.state.set('lesson', lesson);
    }

    this.state.set('show-videos', lesson.videos?.length);

    const lessonTitles = coursePage.querySelectorAll('.current-lesson-title .lesson-title');
    lessonTitles.forEach(title => (title.innerText = lesson.title));

    const vc = coursePage.querySelector('.video-container');

    //force rerender
    this.state.set('curr-video', 0, true);

    this.state.get('sidebar').querySelector('.sidebar-lesson.selected')?.classList?.remove('selected');
    this.state.get('sidebar').querySelector(`.sidebar-lesson[lesson-id='${lesson.id}']`)?.classList.add('selected');

    this.state.set('tab', 'content');

    // Skip-to-next-lesson is only meaningful when the course locks progression
    // AND the current lesson is one that the lock applies to (count_progress).
    // Only the floating (mobile) button is toggled here — the inline sidebar
    // buttons render unconditionally for count_progress sequential lessons,
    // and CSS shows them only on the currently-selected lesson.
    const showSkip = !!(lesson.sequential && lesson.count_progress);
    coursePage.querySelectorAll('.skip-lesson-floating').forEach(btn => btn.classList.toggle('hidden', !showSkip));

    this.persistCurrentLesson(lesson.id);
  };

  skipLesson = async () => {
    const lesson = this.state.get('lesson');
    if (!lesson?.id) return;

    const result = await callServer({
      action: 'skip_lesson',
      lesson_id: lesson.id
    });
    if (!result) return;

    // Re-fetch lessons (so `open` flags reflect the unlocked next lesson)
    // and progress (so the sidebar bars re-color), then move on.
    await this.loadLessons();
    await this.loadProgress();

    const courseData = this.state.get('course-data');
    const idx = courseData.findIndex(l => l.id === lesson.id);
    if (idx > -1 && idx < courseData.length - 1) {
      this.state.set('lesson', courseData[idx + 1]);
    }
  };

  cleanupVideoEvents = () => {
    let player = this.state.get('vimeo-player');
    if (player) {
      player.off('timeupdate');
      player.off('ended');
    } else {
      const vc = this.state.get('coursePage').querySelector('.video-container');
      const video = vc.querySelector('video');
      if (video) {
        video.removeEventListener('timeupdate');
        video.removeEventListener('ended');
      }
    }
  };

  loadCurrentVideo = async current => {
    let coursePage = this.state.get('coursePage');
    this.lastProgressCall = null;

    this.cleanupVideoEvents();
    const lesson = this.state.get('lesson');
    const vc = this.state.get('coursePage').querySelector('.video-container');

    if (lesson.videos?.length) {
      vc.classList.remove('no-videos-available');
      coursePage.querySelector('.toggle-videos').classList.remove('rotated');

      if (lesson.videos.length > 1) {
        coursePage.querySelector('.lesson-videos .current-lesson-title .lesson-title').innerText = `${
          lesson.videos[current].caption?.length ? lesson.videos[current].caption : lesson.title
        } (${current + 1}/${lesson.videos.length})`;
        const multiIndication = coursePage.querySelector(
          '.lesson-videos .current-lesson-title .multiple-video-indication'
        );
        multiIndication.innerText = 'שים לב, לשיעור זה מספר סרטונים';
        multiIndication.classList.remove('hidden');
        setTimeout(() => multiIndication.classList.add('hidden'), 8000);
      }

      if (current === 0) coursePage.querySelector('.prev-video').classList.add('hide');
      else coursePage.querySelector('.prev-video').classList.remove('hide');

      if (current >= lesson.videos.length - 1) coursePage.querySelector('.next-video').classList.add('hide');
      else coursePage.querySelector('.next-video').classList.remove('hide');

      let iframeId = `ifrm_${Math.floor(Math.random() * 10000000)}`;
      const url = lesson.videos[current].video_id
        ? `https://player.vimeo.com/video/${lesson.videos[current].video_id}`
        : lesson.videos[current].url;
      vc.innerHTML = `
          <iframe 
            src="${url}"
            height="400"
            id=${iframeId}
            width="auto"
            frameborder="0" 
            allow="autoplay; fullscreen; picture-in-picture" 
            allowfullscreen
            title="${lesson.videos[current].caption?.length ? lesson.videos[current].caption : lesson.title}">
          </iframe>
        `;
      let iframe = document.querySelector(`#${iframeId}`);
      let player = new Vimeo.Player(iframe);
      this.state.set('vimeo-player', player);

      const savedProgress = this.state.get('student-progress')?.course_progress?.[lesson.id]?.[lesson.videos[current].video_id];
      if (this.state.get('auto-advancing')) {
        this.state.set('auto-advancing', false);
        player.ready().then(() => player.play());
      } else if (savedProgress?.seconds > 0 && savedProgress?.percent < 100) {
        player.ready().then(() => player.setCurrentTime(savedProgress.seconds));
      }

      const vimeoPlayerEvent = async data => {
        this.reportProgress(lesson, lesson.videos[current], Math.floor(data.percent * 100), data.seconds);
      };
      player.on('timeupdate', vimeoPlayerEvent);
      player.on('ended', async data => {
        await vimeoPlayerEvent(data);
        const isAutoAdvance = this.state.get('auto-advance');
        if (isAutoAdvance) {
          this.autoAdvanceToNextVideo();
        }
        this.blinkNavIcon();
      });
    } else {
      // Don't auto-mark a locked text lesson 100% — the server now rejects
      // it (gate enforcement in setStudentProgress) but firing the call at
      // all would still trigger callServer's error modal. loadLesson should
      // have redirected away from locked lessons by now; this is a safety
      // belt for stale state or race conditions.
      if (lesson.open !== false) {
        this.reportProgress(lesson, { video_id: 'text' }, 100, 0);
      }
      setTimeout(() => this.blinkNavIcon(), 500);

      vc.classList.add('no-videos-available');
      coursePage.querySelector('.toggle-videos').classList.add('rotated');
      coursePage.querySelector('.next-video').classList.add('hide');
      coursePage.querySelector('.prev-video').classList.add('hide');
      vc.innerHTML = `<label>לשיעור זה אין סרטונים</label>`;
    }
  };

  reportProgress = async (lesson, video, percent, seconds) => {
    if (percent < 100 && this.lastProgressCall && Math.floor((new Date() - this.lastProgressCall) / 1000) < 10) return;

    this.lastProgressCall = new Date();
    const courseId = this.state.get('courseId');
    const studentProgress = this.state.get('student-progress');
    const currentCoursePercent = studentProgress?.progress?.[courseId]?.percent ?? -1;

    await callServer({
      action: 'set_student_progress',
      course_id: courseId,
      module_id: lesson.module_id,
      lesson_id: lesson.id,
      video_id: video.video_id,
      percent: percent,
      seconds: seconds,
      progress: currentCoursePercent
    });

    // On lesson-completing events, also refresh lessons so a sequential-locked
    // next lesson updates its `open` flag in the sidebar. Awaited so any
    // immediately-following auto-advance sees the fresh state. Quizzes come
    // along for the ride: on a sequential course, finishing the last lesson
    // of a module is exactly what unlocks that module's quiz, so without this
    // the quiz row would stay greyed out until a page reload.
    if (percent >= 100) {
      await this.loadLessons();
      await this.loadQuizzes();
    }
    await this.loadProgress();
  };

  determineInitialLesson = () => {
    //maintain progress to local storage
    let progress = localStorage.getItem('course_progress');

    if (!progress) progress = {};
    else progress = JSON.parse(progress);

    if (progress[this.state.get('courseId')]) return progress[this.state.get('courseId')];

    let coursePage = this.state.get('coursePage');
    return parseInt(coursePage.getAttribute('lesson-id'));
  };
  persistCurrentLesson = lessonId => {
    //maintain progress to local storage
    let progress = localStorage.getItem('course_progress');

    if (!progress) progress = {};
    else progress = JSON.parse(progress);

    progress[this.state.get('courseId')] = lessonId;
    localStorage.setItem('course_progress', JSON.stringify(progress));
  };

  autoAdvanceToNextVideo = () => {
    const coursePage = this.state.get('coursePage');
    const vc = coursePage.querySelector('.video-container');
    const lesson = this.state.get('lesson');
    const currentVideo = this.state.get('curr-video');

    if (!lesson.videos?.length) return;

    vc.innerHTML = `<div style="display: flex; align-items: center; justify-content: center; height: 300px; font-size: 18px; background: black; color:white;">טוען את הסרטון הבא...</div>`;

    setTimeout(() => {
      this.state.set('auto-advancing', true);

      // Check if there's a next video in current lesson
      if (lesson.videos?.length && currentVideo < lesson.videos.length - 1) {
        this.state.set('curr-video', currentVideo + 1);
        return;
      }

      // Move to next lesson
      const courseData = this.state.get('course-data');
      const currentLessonIndex = courseData.findIndex(l => l.id === lesson.id);

      if (currentLessonIndex !== -1 && currentLessonIndex < courseData.length - 1) {
        const nextLesson = courseData[currentLessonIndex + 1];
        // Sequential gate: don't auto-advance into a still-locked lesson.
        if (nextLesson.open === false) return;
        this.state.set('lesson', nextLesson);
      }
    }, 3000);
  };

  /**
   * If the lesson content already uses HTML block-level structure (paragraphs,
   * headings, lists, tables, etc.), let it flow normally — its own tags carry
   * the visual rhythm. Only when it's plain text (raw `\n`s with no
   * block-level markup) do we convert newlines to <br>. This is the inverse
   * of v1.2.1's blanket `white-space: pre-wrap`, which over-indented
   * HTML-formatted lessons because the inter-tag whitespace was preserved.
   */
  autoBr = content => {
    if (!content) return '';
    if (/<(p|div|br|h[1-6]|ul|ol|li|table|pre|blockquote|section|article|hr)\b/i.test(content)) {
      return content;
    }
    return content.replace(/\n/g, '<br>');
  };

  showLessonTab = tab => {
    let lesson = this.state.get('lesson');
    let content = '';
    switch (tab) {
      case 'content':
        content = this.autoBr(lesson.lessonContent);
        break;
      case 'additional':
        content = '';
        // Guard against a bare id / "0" leaking through from older data — only
        // an actual URL should produce a download link.
        if (/^(https?:)?\/\//i.test(lesson.presentation || ''))
          content += `<p><a target="_blank" href="${lesson.presentation}">לחץ כאן להורדת המצגת של השיעור</a><br/></p>`;
        if (lesson.additionalFiles?.length) content += `<p>${this.autoBr(lesson.additionalFiles)}</p>`;
        break;
      case 'homework':
        content = this.autoBr(lesson.homework);
        break;
      case 'student-notes':
        content = `<div class="notebook-container"><div class="notebook-lines"></div><div class="student-notes" contenteditable="true" spellcheck="false">${lesson?.studentNotes || ''}</div></div>`;
        break;
    }

    let coursePage = this.state.get('coursePage');
    let navs = document.querySelectorAll('.lesson-materials-nav li');
    navs.forEach(nav => nav.classList.remove('selected'));
    document.querySelector(`.lesson-materials-nav li[tab-id='${tab}']`).classList.add('selected');
    coursePage.querySelector('.lesson-content-viewer').innerHTML = content;

    if (document.querySelector('table.screeners.table')) {
      window.screeners.init();
    }

    coursePage.querySelectorAll('tr[data-href]').forEach(row => {
      row.addEventListener('click', e => {
        if (e.target.closest('a')) return;
        window.open(row.getAttribute('data-href'), '_blank');
      });
    });

  };

  loadLessons = async () => {
    const lessondata = await callServer({
      action: 'get_lessons',
      class_id: this.state.get('classId')
    });

    this.state.set('course-data', lessondata);
  };

  loadQuizzes = async () => {
    const quizzes = await callServer({
      action: 'get_course_quizzes',
      course_id: this.state.get('courseId')
    });

    this.state.set('quizzes', Array.isArray(quizzes) ? quizzes : []);
  };

  blinkNavIcon = () => {
    const btns = document.querySelectorAll('.nav-lessons');
    btns.forEach(btn => {
      btn.classList.remove('blink');
      void btn.offsetWidth; // force reflow to restart animation
      btn.classList.add('blink');
    });
    setTimeout(() => btns.forEach(btn => btn.classList.remove('blink')), 2500);
  };

  /**
   * Sidebar icons are built in JS, so they don't get the ?ver= that
   * wp_enqueue_* stamps onto scripts and styles. Append the plugin version
   * ourselves, otherwise a redrawn icon stays invisible behind the cache.
   */
  iconUrl = name =>
    `${window.school_info.theme_url}assets/images/${name}.svg?ver=${window.school_info.version || ''}`;

  /**
   * Shield-with-a-question-mark marking a module quiz. Inlined rather than
   * loaded as a file like the lesson icons, because it has three states that
   * differ only in stroke and colour — one shape driven by CSS beats three
   * near-identical SVGs kept in sync by hand (and it can't go stale in the
   * browser cache).
   *
   * `state` is '' (not taken → dashed), 'attempted' (done, no pass mark →
   * solid neutral), or 'attempted passed' / 'attempted failed' (solid green /
   * red). Strokes use currentColor so the CSS only has to set `color` — which
   * also means the locked-row rule greys the icon out for free.
   */
  quizIconSvg = state =>
    `<svg class="quiz-icon ${state}" viewBox="0 0 50 50" aria-hidden="true" focusable="false">
      <path class="quiz-icon-shield" d="M11,4.5 H39 A3.5,3.5 0 0 1 42.5,8 V24 C42.5,34.6 35,42.1 25,45.5 C15,42.1 7.5,34.6 7.5,24 V8 A3.5,3.5 0 0 1 11,4.5 Z"/>
      <g transform="translate(25,23) scale(0.9) translate(-25,-25)">
        <path class="quiz-icon-mark" d="M18.7,19.8C18.7,15.5 21.6,13.2 25,13.2C28.6,13.2 31.3,15.7 31.3,19.3C31.3,22.4 29.6,23.8 27.5,25.3C25.9,26.4 25.1,27.5 25.1,29.4"/>
        <circle class="quiz-icon-dot" cx="25.1" cy="35.4" r="2.2"/>
      </g>
    </svg>`;

  toggleMobileSidebar = show => {
    if (show) this.state.get('sidebar').classList.add('show');
    else this.state.get('sidebar').classList.remove('show');
  };

  showSidebar = () => {
    const lessondata = this.state.get('course-data');

    const sidebar = this.state.get('sidebar');

    //remove all .sidebar-module from the sidebar
    sidebar.querySelectorAll('.sidebar-module').forEach(m => m.remove());

    const modules = lessondata.reduce((arr, curr) => {
      if (!arr.find(m => m.id === curr.module_id))
        arr.push({ id: curr.module_id, title: curr.module_title, order: curr.module_order, intro: curr.intro_module });
      return arr;
    }, []);

    // A module can hold nothing but a quiz (a final exam, typically). It has no
    // lessons, so the reduce above never saw it — add it from the quiz list.
    const quizzes = this.state.get('quizzes') || [];
    quizzes.forEach(quiz => {
      if (modules.find(m => String(m.id) === String(quiz.module_id))) return;
      modules.push({
        id: quiz.module_id,
        title: quiz.module_name,
        order: quiz.module_order,
        intro: quiz.intro_module ? 1 : 0
      });
    });

    modules.sort((a, b) => a.order - b.order);

    //count intro modules
    let introModules = modules.filter(m => parseInt(m.intro) === 1).length;

    //add modules to sidebar
    modules.forEach(module => {
      sidebar.insertAdjacentHTML(
        'beforeend',
        `<div class="sidebar-module" 
        id="module_${module.id}">
        <div class="sidebar-module-header" >
          <label>${
            parseInt(module.intro) === 1
              ? `מבוא: ${module.title}`
              : `מודול ${module.order - introModules}: ${module.title}`
          }</label>
          <span class="opener">›</span>
        </div>
      </div>`
      );

      const domModule = sidebar.querySelector(`#module_${module.id}`);
      domModule.addEventListener('click', e => {
        // Only toggle when the user clicks the module header itself.
        // Lesson rows have their own click semantics (navigate / skip) and
        // bubble up here — without this guard the module would collapse and
        // re-open every time the student hits the in-row "דלג" button.
        if (e.target.closest('.sidebar-lesson')) return;
        e.preventDefault();
        domModule.classList.toggle('open');
      });
    });

    lessondata.forEach(lesson => {
      let currLesson = this.state.get('lesson');
      let isCurrent = currLesson.id === lesson.id;

      let currModule = sidebar.querySelector(`#module_${lesson.module_id}`);
      if (isCurrent) currModule.classList.add('open');

      //add module lessons
      const progress = this.state.get('student-progress');
      const course = this.state.get('courseId');

      var showPlay = true;

      //calculate progress
      var background = 'white';
      try {
        const courseTreeLesson = progress.course_tree[course].modules[lesson.module_id].lessons[lesson.id];
        showPlay = courseTreeLesson.videos[0] !== 'text';

        var lessonTotal = courseTreeLesson.videos.length * 100;

        const lessonProgress = progress.course_progress?.[lesson.id];
        if (lessonProgress) {
          const passed = Object.values(lessonProgress).reduce((prev, curr) => {
            prev += curr.percent;
            return prev;
          }, 0);

          const pct = parseInt((passed / lessonTotal) * 100);
          background = `linear-gradient(to top, #46da9c 0, #46da9c ${pct}%, white ${pct}%, white 100%)`;
        }
      } catch (ex) {}

      // Inline "דלג" button is rendered once per row for count_progress
      // sequential lessons and shown by CSS only on `.sidebar-lesson.selected`.
      // Always built into the row so we don't have to re-render the sidebar
      // when the currently-selected lesson changes — selection just moves
      // the .selected class.
      const skipBtnHtml = (lesson.sequential && lesson.count_progress)
        ? `<button type="button" class="skip-lesson skip-lesson-sidebar">דלג</button>`
        : '';

      var lessonDiv = currModule.querySelector(`[lesson-id='${lesson.id}']`);
      if (!lessonDiv) {
        //check if currModule contains lessons and add in the right order
        const lessons = currModule.querySelectorAll('.sidebar-lesson');
        let insertBefore = null;
        lessons.forEach(l => {
          if (parseInt(l.getAttribute('order')) > parseInt(lesson.lesson_number)) {
            insertBefore = l;
          }
        });

        if (!insertBefore) {
          currModule.insertAdjacentHTML(
            'beforeend',
            `<div class="sidebar-lesson${isCurrent ? ' selected' : ''}${lesson.open ? '' : ' locked'}"
            course-id="${this.state.get('courseId')}"
            module-id="${lesson.module_id}"
            order="${lesson.lesson_number}"
            lesson-id="${lesson.id}">
            <label>${lesson.title}</label>
            ${skipBtnHtml}
            <img class='play-icon' src="${this.iconUrl(showPlay ? 'play' : 'text')}" style='background:${background}' />
          </div>`
          );
        } else {
          insertBefore.insertAdjacentHTML(
            'beforebegin',
            `<div class="sidebar-lesson${isCurrent ? ' selected' : ''}${lesson.open ? '' : ' locked'}"
            course-id="${this.state.get('courseId')}"
            module-id="${lesson.module_id}"
            order="${lesson.lesson_number}"
            lesson-id="${lesson.id}">
            <label>${lesson.title}</label>
            ${skipBtnHtml}
            <img class='play-icon' src="${this.iconUrl(showPlay ? 'play' : 'text')}" style='background:${background}' />
          </div>`
          );
        }

        //lesson selection
        lessonDiv = currModule.querySelector(`[lesson-id='${lesson.id}']`);
        lessonDiv.addEventListener('click', this.changeLesson);
      } else {
        lessonDiv.querySelector('.play-icon').style.background = background;
        // Keep the lock visual in sync with the latest server-side `open` flag,
        // so completing a sequential lesson unlocks the next one without a reload.
        lessonDiv.classList.toggle('locked', !lesson.open);
      }
    });

    // Quizzes go in last, after every lesson row exists, so each one lands at
    // the bottom of its module. (The lesson insertion above orders by the
    // `order` attribute, which a quiz row deliberately doesn't have.)
    const currentQuiz = this.state.get('quiz');
    quizzes.forEach(quiz => {
      const domModule = sidebar.querySelector(`#module_${quiz.module_id}`);
      if (!domModule) return;

      const isCurrent = currentQuiz?.module_id === quiz.module_id;
      if (isCurrent) {
        domModule.classList.add('open');
        // The lessons loop above marked the last-visited lesson as selected;
        // while a quiz is open it owns the selection instead.
        sidebar.querySelectorAll('.sidebar-lesson.selected').forEach(el => el.classList.remove('selected'));
      }

      const attempt = quiz.attempt;
      // Only a quiz that actually gates has a verdict. On a self-check quiz
      // every submission "passes", so pass/fail colouring there would be
      // meaningless — it reads as done (solid, neutral) instead.
      const verdict = !attempt ? '' : quiz.pass_score > 0 ? (attempt.passed ? ' passed' : ' failed') : ' neutral';
      const badge = attempt ? `<span class="quiz-score-badge${verdict}">${attempt.score}%</span>` : '';

      domModule.insertAdjacentHTML(
        'beforeend',
        `<div class="sidebar-lesson sidebar-quiz${isCurrent ? ' selected' : ''}${quiz.locked ? ' locked' : ''}"
          module-id="${quiz.module_id}">
          <label>${this.escapeHtml(quiz.title)}</label>
          ${badge}
          ${this.quizIconSvg(attempt ? `attempted${verdict}` : '')}
        </div>`
      );

      domModule.querySelector(`.sidebar-quiz[module-id='${quiz.module_id}']`).addEventListener('click', e => {
        e.stopPropagation();
        e.preventDefault();
        this.showQuiz(quiz.module_id);
        this.toggleMobileSidebar(false);
      });
    });
  };

  // --- Module quiz --------------------------------------------------------

  /**
   * Quiz titles, questions and answers are plain-text fields in the admin, so
   * they're rendered as text — unlike lesson content, which is deliberately
   * HTML.
   */
  escapeHtml = value =>
    String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');

  /**
   * The quiz takes over the whole .lesson area. Lesson navigation exits the
   * mode again (see loadLesson), so the two views can never be on screen at
   * the same time.
   */
  setQuizMode = on => {
    const coursePage = this.state.get('coursePage');
    coursePage.querySelector('.lesson').classList.toggle('quiz-mode', !!on);
    if (!on) this.state.set('quiz', null);
  };

  showQuiz = async moduleId => {
    const quiz = await callServer({
      action: 'get_quiz',
      module_id: moduleId
    });
    if (!quiz) return;

    // Answers the student has picked but not yet submitted, keyed by question
    // id. Kept out of `quiz` so re-rendering the results view can't resurrect
    // them.
    this.state.set('quiz-answers', {});
    this.state.set('quiz', quiz);
    this.setQuizMode(true);

    const coursePage = this.state.get('coursePage');
    coursePage.querySelector('.quiz-view .quiz-title').innerText = quiz.title;

    const sidebar = this.state.get('sidebar');
    sidebar.querySelector('.sidebar-lesson.selected')?.classList.remove('selected');
    sidebar.querySelector(`.sidebar-quiz[module-id='${moduleId}']`)?.classList.add('selected');

    this.renderQuiz();
    window.scrollTo(0, 0);
  };

  recordQuizAnswer = radio => {
    const answers = this.state.get('quiz-answers') || {};
    answers[parseInt(radio.getAttribute('data-question-id'))] = parseInt(radio.value);
    this.state.set('quiz-answers', answers);

    const coursePage = this.state.get('coursePage');
    coursePage.querySelectorAll('.quiz-answer').forEach(answer => answer.classList.remove('checked'));
    coursePage
      .querySelectorAll('.quiz-answer input[type="radio"]:checked')
      .forEach(checked => checked.closest('.quiz-answer').classList.add('checked'));

    const quiz = this.state.get('quiz');
    const submit = coursePage.querySelector('.quiz-submit');
    if (submit) submit.disabled = Object.keys(answers).length < quiz.questions.length;
  };

  renderQuiz = () => {
    const quiz = this.state.get('quiz');
    const body = this.state.get('coursePage').querySelector('.quiz-view .quiz-body');
    if (!quiz || !body) return;

    body.innerHTML = quiz.attempt ? this.quizResultHtml(quiz) : this.quizFormHtml(quiz);
  };

  quizFormHtml = quiz => {
    const intro =
      quiz.pass_score > 0
        ? `<p class="quiz-intro">ציון עובר: ${quiz.pass_score}%.${
            quiz.blocking ? ' עד לעמידה בציון העובר, המשך הקורס נעול.' : ''
          }</p>`
        : `<p class="quiz-intro">בוחן לתרגול עצמי, ללא ציון עובר.</p>`;

    const questions = quiz.questions
      .map(
        (question, index) => `
        <div class="quiz-question">
          <div class="quiz-question-text"><span class="quiz-question-num">${index + 1}</span>${this.escapeHtml(
          question.text
        )}</div>
          <div class="quiz-answers">
            ${question.options
              .map(
                (option, optionIndex) => `
              <label class="quiz-answer">
                <input type="radio" name="quiz_q_${question.id}" data-question-id="${question.id}" value="${optionIndex}" />
                <span>${this.escapeHtml(option)}</span>
              </label>`
              )
              .join('')}
          </div>
        </div>`
      )
      .join('');

    return `${intro}
      <div class="quiz-questions">${questions}</div>
      <div class="quiz-actions">
        <button type="button" class="quiz-submit" disabled>שלח תשובות</button>
      </div>`;
  };

  /**
   * The lesson that follows a module's quiz: the quiz renders after that
   * module's last lesson, so it's whatever comes next in course order.
   * Returns null when the quiz closes the course, or when the next lesson is
   * still gated (drip / sequential) — there'd be nothing to navigate to.
   */
  nextLessonAfterQuiz = moduleId => {
    const courseData = this.state.get('course-data') || [];

    let lastOfModule = -1;
    courseData.forEach((lesson, i) => {
      if (parseInt(lesson.module_id) === parseInt(moduleId)) lastOfModule = i;
    });
    if (lastOfModule === -1) return null;

    const next = courseData[lastOfModule + 1];
    return next && next.open !== false ? next : null;
  };

  quizResultHtml = quiz => {
    const attempt = quiz.attempt;
    const gating = quiz.pass_score > 0;

    let verdict = `<p class="quiz-verdict">השלמת את הבוחן.</p>`;
    if (gating) {
      verdict = attempt.passed
        ? `<p class="quiz-verdict passed">עברת בהצלחה! ציון עובר: ${quiz.pass_score}%</p>`
        : `<p class="quiz-verdict failed">לא עברת. ציון עובר: ${quiz.pass_score}%${
            quiz.blocking ? ' — המשך הקורס ייפתח לאחר שתעבור.' : ''
          }</p>`;
    }

    // The questions always stay on screen after submitting — having your work
    // vanish and be replaced by a bare number is disorienting, and you can't
    // learn anything from it. What varies is only how much is marked up:
    // correct answers are in the payload solely when the quiz permits
    // revealing them (see Quiz::can_reveal), so without that we still show
    // every question with the student's own pick highlighted, just no verdict
    // per answer.
    const review = `<div class="quiz-questions reviewed">
          ${quiz.questions
            .map((question, index) => {
              const given = attempt.answers?.[question.id];
              const options = question.options
                .map((option, optionIndex) => {
                  const isGiven = optionIndex === given;
                  const isCorrect = quiz.can_reveal && optionIndex === question.correct;
                  const isWrongPick = quiz.can_reveal && isGiven && !isCorrect;
                  const cls = `quiz-answer reviewed${isCorrect ? ' correct' : ''}${
                    isWrongPick ? ' wrong' : ''
                  }${!quiz.can_reveal && isGiven ? ' chosen' : ''}`;
                  const mark = isCorrect ? '✔' : isWrongPick ? '✘' : !quiz.can_reveal && isGiven ? '●' : '';
                  return `<div class="${cls}"><span class="quiz-answer-mark">${mark}</span><span>${this.escapeHtml(
                    option
                  )}</span></div>`;
                })
                .join('');
              return `<div class="quiz-question">
                <div class="quiz-question-text"><span class="quiz-question-num">${index + 1}</span>${this.escapeHtml(
                question.text
              )}</div>
                <div class="quiz-answers">${options}</div>
              </div>`;
            })
            .join('')}
        </div>`;

    // Carry on only when the student is actually clear to: `passed` is already
    // true for every submission on a quiz with no minimum score (see
    // Quiz::grade), so this covers both "passed" and "nothing to pass".
    const nextLesson = attempt.passed ? this.nextLessonAfterQuiz(quiz.module_id) : null;
    const continueButton = nextLesson
      ? `<button type="button" class="quiz-next-lesson">המשך לשיעור הבא</button>`
      : '';

    // Answers first, then the reset button, then the score last — the score
    // reads as the conclusion of the review rather than a headline above it.
    return `${review}
      <div class="quiz-actions">
        ${continueButton}
        <button type="button" class="quiz-reset">איפוס הבוחן</button>
      </div>
      <div class="quiz-score-card${gating ? (attempt.passed ? ' passed' : ' failed') : ''}">
        <span class="quiz-score-value">${attempt.score}%</span>
        <span class="quiz-score-detail">${attempt.correct_count} מתוך ${attempt.total_count} תשובות נכונות</span>
      </div>
      ${verdict}`;
  };

  submitQuiz = async () => {
    const quiz = this.state.get('quiz');
    const answers = this.state.get('quiz-answers') || {};

    if (Object.keys(answers).length < quiz.questions.length) {
      notifications.show('יש לענות על כל השאלות', 'error');
      return;
    }

    const result = await callServer({
      action: 'submit_quiz',
      module_id: quiz.module_id,
      answers: JSON.stringify(answers)
    });
    if (!result) return;

    quiz.attempt = {
      score: result.score,
      correct_count: result.correct_count,
      total_count: result.total_count,
      passed: result.passed,
      answers: answers
    };
    quiz.can_reveal = result.can_reveal;
    if (result.questions) quiz.questions = result.questions;
    this.state.set('quiz', quiz);

    this.renderQuiz();

    // A blocking quiz that just got passed unlocks the rest of the course —
    // re-pull the lesson gates and the badges before redrawing the sidebar.
    await this.loadLessons();
    await this.loadQuizzes();
    this.showSidebar();

    // The score now sits below the reviewed answers, so on anything longer
    // than a couple of questions it lands off-screen — bring it into view or
    // submitting looks like it did nothing. .quiz-body is the scroll
    // container here, not the window, so scrollIntoView is what moves it.
    this.state
      .get('coursePage')
      .querySelector('.quiz-view .quiz-score-card')
      ?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  };

  resetQuiz = () => {
    const quiz = this.state.get('quiz');

    // A passed blocking quiz is what's holding the rest of the course open —
    // say so, because resetting it re-locks everything after this module.
    const warning =
      quiz.blocking && quiz.attempt?.passed
        ? ' שים לב: המשך הקורס יינעל שוב עד שתעבור את הבוחן מחדש.'
        : '';

    remodaler.show({
      title: 'איפוס הבוחן',
      message: `התוצאה הקיימת תימחק ותוכל לענות על הבוחן מחדש. להמשיך?${warning}`,
      type: remodaler.types.CONFIRM,
      confirmText: 'איפוס',
      confirm: async () => {
        const result = await callServer({
          action: 'reset_quiz',
          module_id: quiz.module_id
        });
        if (!result) return;

        await this.loadLessons();
        await this.loadQuizzes();
        await this.showQuiz(quiz.module_id);
        this.showSidebar();
      }
    });
  };

  changeLesson = e => {
    // The inline `דלג` button lives inside the .sidebar-lesson row so its
    // click bubbles here before the delegated .skip-lesson handler can
    // stop propagation — bail out so we don't navigate AND skip.
    if (e.target.closest('.skip-lesson')) return;
    e.stopPropagation();
    e.preventDefault();
    let lessonId = parseInt(e.target.closest('.sidebar-lesson').getAttribute('lesson-id'));
    let lesson = this.state.get('course-data').find(ld => ld.id === lessonId);

    this.openLesson(lesson);
    this.toggleMobileSidebar(false);
  };

  /**
   * Navigate to a lesson, leaving the quiz view if it's open.
   *
   * The special case: when the target is the lesson we were already on before
   * opening the quiz, the `lesson` state doesn't change, so its listener never
   * fires and loadLesson — which is what closes the quiz view — never runs.
   * Do both of its jobs here instead.
   */
  openLesson = lesson => {
    if (!lesson) return;

    if (this.state.get('quiz') && this.state.get('lesson')?.id === lesson.id) {
      this.setQuizMode(false);
      const sidebar = this.state.get('sidebar');
      sidebar.querySelectorAll('.sidebar-lesson.selected').forEach(el => el.classList.remove('selected'));
      sidebar.querySelector(`.sidebar-lesson[lesson-id='${lesson.id}']`)?.classList.add('selected');
      return;
    }

    this.state.set('lesson', lesson);
  };
}

var _classroom = new Classroom();
var _lobby = new Lobby();
var _currentScript = document.currentScript;

JSUtils.domReady(async () => {
  _lobby.init();
  _classroom.init();
});
