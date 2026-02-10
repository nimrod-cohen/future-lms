class CoursesTab {
  state = window.StateManagerFactory();

  constructor() {
    this.state.set('open-courses', []);
    this.state.set('open-modules', []);
    this.state.listen('courses', this.render);

    this.getCourses();

    JSUtils.addGlobalEventListener('#courses-list', '.course .course-module .module-name', 'click', this.openModule);
    JSUtils.addGlobalEventListener('#courses-list', '.course .course-name', 'click', this.openCourse);
    JSUtils.addGlobalEventListener(
      '#courses-list',
      ".actionable[data-action='edit-course']",
      'click',
      this.updateCourse
    );
    JSUtils.addGlobalEventListener('#courses-list', ".actionable[data-action='move-up-module']", 'click', e =>
      this.reoderModule(e, -1)
    );
    JSUtils.addGlobalEventListener('#courses-list', ".actionable[data-action='move-down-module']", 'click', e =>
      this.reoderModule(e, 1)
    );

    JSUtils.addGlobalEventListener('#courses-list', ".actionable[data-action='add-module']", 'click', e =>
      this.editModule(e, null)
    );
    JSUtils.addGlobalEventListener('#courses-list', ".actionable[data-action='edit-module']", 'click', e =>
      this.editModule(e, e.target.closest('.course-module').dataset.moduleId)
    );
    JSUtils.addGlobalEventListener('#courses-list', ".actionable[data-action='pause-course']", 'click', e =>
      this.changeCourseStatus(e, 'draft')
    );
    JSUtils.addGlobalEventListener('#courses-list', ".actionable[data-action='resume-course']", 'click', e =>
      this.changeCourseStatus(e, 'publish')
    );
    JSUtils.addGlobalEventListener('#courses-list', ".actionable[data-action='delete-course']", 'click', e =>
      this.changeCourseStatus(e, 'trash')
    );
    JSUtils.addGlobalEventListener('#courses-list', ".actionable[data-action='delete-module']", 'click', e =>
      this.changeModuleStatus(e, 'trash')
    );
    JSUtils.addGlobalEventListener('#courses-list', ".actionable[data-action='pause-module']", 'click', e =>
      this.changeModuleStatus(e, 'draft')
    );
    JSUtils.addGlobalEventListener('#courses-list', ".actionable[data-action='resume-module']", 'click', e =>
      this.changeModuleStatus(e, 'publish')
    );
    JSUtils.addGlobalEventListener('#courses-list', ".actionable[data-action='delete-lesson']", 'click', e =>
      this.changeLessonStatus(e, 'trash')
    );
    JSUtils.addGlobalEventListener('#courses-list', ".actionable[data-action='pause-lesson']", 'click', e =>
      this.changeLessonStatus(e, 'draft')
    );
    JSUtils.addGlobalEventListener('#courses-list', ".actionable[data-action='resume-lesson']", 'click', e =>
      this.changeLessonStatus(e, 'publish')
    );
    JSUtils.addGlobalEventListener('#courses-list', ".actionable[data-action='move-up-lesson']", 'click', e =>
      this.reoderLesson(e, -1)
    );
    JSUtils.addGlobalEventListener('#courses-list', ".actionable[data-action='move-down-lesson']", 'click', e =>
      this.reoderLesson(e, 1)
    );
    JSUtils.addGlobalEventListener('#courses-list', ".actionable[data-action='add-lesson']", 'click', this.addLesson);

    JSUtils.addGlobalEventListener('#courses-list', ".actionable[data-action='edit-lesson']", 'click', e =>
      this.editLesson(e)
    );

    document.querySelector('.action-bar [data-action="add-course"]').addEventListener('click', this.addCourse);
    document.querySelector('.action-bar [data-action="add-class"]').addEventListener('click', this.addClass);
  }

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

  getCourses = async () => {
    const data = await JSUtils.fetch(__futurelms.ajax_url, {
      action: 'get_all_courses'
    });
    this.state.set('courses', data.courses);
  };

  render = () => {
    const courses = this.state.get('courses');
    const openCourses = this.state.get('open-courses');
    const openModules = this.state.get('open-modules');

    if (!courses) {
      document.querySelector('#courses-list').innerHTML = 'No courses found';
      return;
    }

    const sortedCourseIds = Object.entries(courses)
      // Sort the entries based on the course name
      .sort((a, b) => a[1].name.localeCompare(b[1].name))
      // Map the sorted entries to get the course IDs (the keys)
      .map(entry => entry[0]);

    document.querySelector('#courses-list').innerHTML = sortedCourseIds
      .map(cid => {
        const course = courses[cid];
        var moduleIds = Object.keys(course.modules);
        moduleIds = moduleIds.sort((a, b) => {
          return course.modules[a].order - course.modules[b].order;
        });

        return `<div class="course ${openCourses.indexOf(cid) !== -1 ? '' : 'closed'}" data-course-id="${cid}">
          <div class="course-header">
            <span class='course-id'>${cid}</span>
            <h3 class='course-name ${course.enabled === true ? '' : 'disabled'}'>${course.name}</h3>
            <span class='course-actions action-bar'>
              <i class="edit icon blue actionable" data-action='edit-course'></i>
              ${
                course.enabled === true
                  ? "<i class='pause icon red actionable' data-action='pause-course'></i>"
                  : "<i class='play icon green actionable' data-action='resume-course'></i>"
              }
              <span class="tooltip" data-content="Add module" data-variation="mini" data-inverted=""><i class="plus square outline icon actionable" data-action='add-module'></i></span>
              <i class="trash alternate outline icon red actionable" data-action='delete-course'></i>
            </span>
          </div>
          <div class="course-modules">
          ${moduleIds.length === 0 ? '<p class="no-modules">No modules found</p>' : ''}
            ${moduleIds
              .map((mid, idx) => {
                if (typeof course.modules[mid] !== 'object') return '';
                let module = course.modules[mid];

                var lessonIds = Object.keys(module.lessons);
                lessonIds = lessonIds.sort((a, b) => {
                  return module.lessons[a].order - module.lessons[b].order;
                });
                //count intro modules in this course
                const introModules = Object.values(course.modules).filter(m => m.intro_module).length;

                return `<div class="course-module ${
                  openModules.indexOf(mid) !== -1 ? '' : 'closed'
                }" data-module-id='${mid}'>
                    <span class='module-order ${module.intro_module ? 'intro' : ''}'>${
                      module.intro_module ? 'In' : idx + 1 - introModules
                    }</span>
                    <span class='module-name ${module.enabled === true ? '' : 'disabled'}'>${
                      module.count_progress
                        ? '<span class="tooltip" data-content="Counts towards progress" data-variation="mini" data-inverted=""><i class="clock icon yellow"></i></span>'
                        : ''
                    } ${module.name}</span>
                    <span class='module-actions action-bar'>
                      <i class="edit icon blue actionable" data-action='edit-module'></i>
                      ${
                        module.enabled === true
                          ? "<i class='pause icon red actionable' data-action='pause-module'></i>"
                          : "<i class='play icon green actionable' data-action='resume-module'></i>"
                      }
                      <i class="arrow up icon actionable" data-action='move-up-module'></i>
                      <i class="arrow down icon actionable" data-action='move-down-module'></i>
                      <span class="tooltip" data-content="Add lesson" data-variation="mini" data-inverted="">
                        <i class="plus square outline icon actionable" data-action='add-lesson'></i>
                      </span>
                      <i class="trash alternate outline icon red actionable" data-action='delete-module'></i>
                    </span>
                    <div class='module-lessons'>
                     ${lessonIds.length === 0 ? '<p class="no-lessons">No lessons found</p>' : ''}
                    ${lessonIds
                      .map((lessonId, idx2) => {
                        const lesson = module.lessons[lessonId];
                        if (typeof lesson !== 'object') return '';
                        //copy the videos array to a new array, and remove the "text" instance from the array
                        const vidoes = lesson.videos.filter(v => v !== 'text');

                        return `<div class='module-lesson' data-lesson-id='${lessonId}'>
                          <span class='lesson-order'>${idx2 + 1}</span>
                          <span class='lesson-name ${lesson.enabled === true ? '' : 'disabled'}'>${lesson.name} ${
                            vidoes.length
                              ? `<i class='play circle outline icon green jsutils-popover' data-content='${vidoes.join(
                                  ','
                                )}'></i>`
                              : ''
                          }</span>
                          <span class='lesson-actions action-bar'>
                            <i class="edit icon blue actionable" data-action='edit-lesson'></i>
                            ${
                              lesson.enabled === true
                                ? "<i class='pause icon red actionable' data-action='pause-lesson'></i>"
                                : "<i class='play icon green actionable' data-action='resume-lesson'></i>"
                            }
                            <i class="arrow up icon actionable" data-action='move-up-lesson'></i>
                            <i class="arrow down icon actionable" data-action='move-down-lesson'></i>
                            <i class="trash alternate outline icon red actionable" data-action='delete-lesson'></i>
                          </span>
                        </div>`;
                      })
                      .join('')}</div>
                  </div>`;
              })
              .join('')}
          </div>
        </div>`;
      })
      .join('');

    document.querySelectorAll('.jsutils-popover').forEach(el => {
      new Popover(el);
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
      message: `<div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Module name</label>
        <input type='text' name='module_name' value='${module?.name || ''}'/>
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title' for='count_progress'>
          <input type='checkbox' id='count_progress' name='count_progress' ${module?.count_progress ? 'checked' : ''}/>
          Counts towards progress
        </label>
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title' for='teaser'>Teaser</label>
        <input type='text' id='teaser' name='teaser' value='${module?.teaser}'/>
        <small class='desc' style='font-size:0.8rem;'>A less revealing teaser text for course pages</small>
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title' for='intro_module'>
          <input type='checkbox' id='intro_module' name='intro_module' ${module?.intro_module ? 'checked' : ''}/>
          Intro module (will not be numbered)
        </label>        
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
      notifications.show(lesson.message || 'Failed to load lesson', 'error');
      return;
    }

    const videosToTextarea = arr => (arr && arr.length ? arr.join('\n') : '');

    slideout.show({
      title: lessonId ? `Edit Lesson ${lesson.lesson_number}` : 'Add Lesson',
      message: `
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
            <button type='button' class='ui tiny button select-presentation'>Select File</button>
          </div>
        </div>
        <div class='slideout-form-line'>
          <label class='slideout-form-line-title'>Videos (one per line)</label>
          <textarea name='lesson_videos' style='height:120px;'>${videosToTextarea(lesson.videos)}</textarea>
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
          .map(v => ({ video_id: v }));

        const $ = window.jQuery;
        const homeworkHtml =
          $ && $.fn.trumbowyg
            ? jQuery('.trumbo[name="lesson_homework"]').trumbowyg('html')
            : vals.lesson_homework || '';
        const additionalHtml =
          $ && $.fn.trumbowyg
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
      jQuery('.trumbo[name="lesson_homework"]').trumbowyg('html', lesson.homework || '');
      jQuery('.trumbo[name="lesson_additional_files"]').trumbowyg('html', lesson.additional_files || '');
    }

    if (window.wp && wp.media) {
      const btn = document.querySelector('.select-presentation');
      if (btn) {
        btn.addEventListener('click', () => {
          const frame = wp.media({ title: 'Select Presentation', multiple: false });
          frame.on('select', function () {
            const attachment = frame.state().get('selection').first().toJSON();
            const input = document.querySelector('input[name="lesson_presentation"]');
            if (input) input.value = attachment.id;
          });
          frame.open();
        });
      }
    }
  };

  openModule = e => {
    const module = e.target.closest('.course-module');
    const isOpen = !module.classList.contains('closed');
    var openModules = this.state.get('open-modules');

    if (isOpen) {
      module.classList.add('closed');
      openModules = openModules.filter(m => m !== module.dataset.moduleId);
    } else {
      module.classList.remove('closed');
      openModules.push(module.dataset.moduleId);
    }
  };

  openCourse = e => {
    const course = e.target.closest('.course');
    var openCourses = this.state.get('open-courses');

    if (!course.classList.contains('closed')) {
      openCourses = openCourses.filter(c => c !== course.dataset.courseId);
      course.classList.add('closed');
    } else {
      openCourses.push(course.dataset.courseId);
      course.classList.remove('closed');
    }
    this.state.set('open-courses', openCourses);
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
        <input type='text' name='course_code' value='${course?.code || ''}' />
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Course page URL</label>
        <input type='url' name='course_page_url' value='${course?.course_page_url || ''}' />
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Course Duration (hours)</label>
        <input type='text' name='course_duration' value='${course?.course_duration || ''}' />
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
        <input type='number' name='course_full_price' value='${course?.full_price || ''}' min='0' step='1' />
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Discount Price</label>
        <input type='number' name='course_discount_price' value='${course?.discount_price || ''}' min='0' step='1' />
        <small class='desc' style='font-size:0.8rem;'>Leave empty for no discount</small>
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Tags</label>
        <input type='hidden' name='course_tags' value='${course?.tags || ''}' />
        <div id='course-tag-cloud'></div>
      </div>
      <div class='slideout-form-line'>
        <label class='slideout-form-line-title'>Featured Image</label>
        <div class='featured-image-picker'>
          <input type='hidden' name='course_image' value='${course?._thumbnail_id || 0}' />
          <div class='ui mini image featured-image-preview'>
            ${course?.course_image ? `<img src='${course.course_image}' />` : ''}
          </div>
          <button type='button' class='ui tiny button select-course-image'>Select Image</button>
          <button type='button' class='ui tiny button remove-course-image' style='display: ${
            course?.course_image ? 'inline-block' : 'none'
          };'>Remove</button>
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
          page_url: encodeURI(vals.course_page_url || ''),
          course_duration: vals.course_duration || '',
          short_description: vals.course_short_description || '',
          what_you_learn: vals.course_what_you_learn || '',
          tags: encodeURIComponent(vals.course_tags || ''),
          color: vals.course_color || '#aabbcc',
          course_image: vals.course_image || 0,
          default_class: vals.course_default_class,
          full_price: vals.course_full_price || '',
          discount_price: vals.course_discount_price || ''
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

    // init color picker
    document
      .querySelector('.slideout-panel input[name="course_color"]')
      ?.addEventListener('click', e => e.stopPropagation(), true);

    // init tag cloud
    this.initTagCloud();

    // init featured image picker
    this.initFeaturedImagePicker();
  };

  initTagCloud = () => {
    const container = document.querySelector('#course-tag-cloud');
    const hidden = document.querySelector('.slideout-panel input[name="course_tags"]');
    if (!container || !hidden) return;

    const initial = hidden.value
      ? hidden.value
          .split(',')
          .map(t => t.trim())
          .filter(Boolean)
      : [];

    new TagCloud({
      container,
      initialValues: initial,
      callback: tags => {
        hidden.value = tags.join(',');
      },
      options: {
        suggestions: [
          { label: 'featured', description: 'highlights this course in the store' },
          { label: 'hidden', description: 'hides this course from the store' }
        ]
      }
    });
  };

  initFeaturedImagePicker = () => {
    if (!window.wp || !window.wp.media) return;

    const selectBtn = document.querySelector('.select-course-image');
    const removeBtn = document.querySelector('.remove-course-image');

    if (selectBtn) {
      selectBtn.addEventListener('click', () => {
        const frame = wp.media({
          title: 'Select Featured Image',
          multiple: false,
          library: { type: 'image' }
        });

        frame.on('select', () => {
          const attachment = frame.state().get('selection').first().toJSON();
          const input = document.querySelector('input[name="course_image"]');
          const preview = document.querySelector('.featured-image-preview');

          if (input) input.value = attachment.id;
          if (preview) {
            preview.innerHTML = `<img src='${attachment.url}' />`;
          }
          if (removeBtn) removeBtn.style.display = 'inline-block';
        });

        frame.open();
      });
    }

    if (removeBtn) {
      removeBtn.addEventListener('click', () => {
        const input = document.querySelector('input[name="course_image"]');
        const preview = document.querySelector('.featured-image-preview');

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
            <label class='slideout-form-line-title'>Lessons</label>
            <input type='text' name='class_lessons' value='${selectedClass?.lessons || ''}' />
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
          lessons: vals.class_lessons
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
