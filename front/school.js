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

    await this.loadLessons();
    this.state.listen('student-progress', this.showSidebar);
    await this.loadProgress(); //need lesson data

    let selected = coursePage.querySelector('.sidebar-lesson.selected');
    if (selected) {
      selected.closest('.sidebar-module').classList.add('open');
      selected.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }
    window.scrollTo(0, 0);

    await this.loadLesson();
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

    let courseData = this.state.get('course-data');
    let idx = courseData.findIndex(ld => ld.id === lesson.id);

    //in case the course doesn't contain the saved lesson (removed lesson), we loose the progress.
    if (idx === -1) {
      this.state.set('lesson', { id: courseData[0].id });
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

    this.persistCurrentLesson(lesson.id);
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
      });
    } else {
      this.reportProgress(lesson, { video_id: 'text' }, 100, 0);

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
    this.loadProgress();
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
        this.state.set('lesson', nextLesson);
      }
    }, 3000);
  };

  showLessonTab = tab => {
    let lesson = this.state.get('lesson');
    let content = '';
    switch (tab) {
      case 'content':
        content = lesson.lessonContent;
        break;
      case 'additional':
        content = '';
        if (lesson.presentation?.length)
          content += `<p><a target="_blank" href="${lesson.presentation}">לחץ כאן להורדת המצגת של השיעור</a><br/></p>`;
        if (lesson.additionalFiles?.length) content += `<p>${lesson.additionalFiles}</p>`;
        break;
      case 'homework':
        content = lesson.homework;
        break;
      case 'student-notes':
        content = `<div class="notebook-container">
            <div class="notebook-lines"></div>
            <div class="student-notes" contenteditable="true" spellcheck="false">${lesson?.studentNotes || ''}</div>
          </div>`;
        break;
    }

    let coursePage = this.state.get('coursePage');
    let navs = document.querySelectorAll('.lesson-materials-nav li');
    navs.forEach(nav => nav.classList.remove('selected'));
    document.querySelector(`.lesson-materials-nav li[tab-id='${tab}']`).classList.add('selected');
    coursePage.querySelector('.lesson-content-viewer').innerHTML = `<pre>${content}</pre>`;

    if (document.querySelector('table.screeners.table')) {
      window.screeners.init();
    }
  };

  loadLessons = async () => {
    const lessondata = await callServer({
      action: 'get_lessons',
      class_id: this.state.get('classId')
    });

    this.state.set('course-data', lessondata);
  };

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
            <img class='play-icon' src="${window.school_info.theme_url}assets/images/${
              showPlay ? 'play' : 'text'
            }.svg" style='background:${background}' />
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
            <img class='play-icon' src="${window.school_info.theme_url}assets/images/${
              showPlay ? 'play' : 'text'
            }.svg" style='background:${background}' />
          </div>`
          );
        }

        //lesson selection
        lessonDiv = currModule.querySelector(`[lesson-id='${lesson.id}']`);
        lessonDiv.addEventListener('click', this.changeLesson);
      } else {
        lessonDiv.querySelector('.play-icon').style.background = background;
      }
    });
  };

  changeLesson = e => {
    e.stopPropagation();
    e.preventDefault();
    let lessonId = parseInt(e.target.closest('.sidebar-lesson').getAttribute('lesson-id'));
    let lesson = this.state.get('course-data').find(ld => ld.id === lessonId);
    this.state.set('lesson', lesson);
    this.toggleMobileSidebar(false);
  };
}

var _classroom = new Classroom();
var _lobby = new Lobby();
var _currentScript = document.currentScript;

JSUtils.domReady(async () => {
  _lobby.init();
  _classroom.init();
});
