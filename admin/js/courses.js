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

    JSUtils.addGlobalEventListener('#courses-list', ".actionable[data-action='edit-lesson'", 'click', e => {
      const lessonId = e.target.closest('.module-lesson').dataset.lessonId;
      window.open(`/wp-admin/post.php?post=${lessonId}&action=edit`, '_blank');
    });

    document.querySelector('.action-bar [data-action="add-course"]').addEventListener('click', this.addCourse);
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
            <small class='course-price'> ${JSUtils.formatCurrency({ sum: course.price })}</small>
            <span class='course-actions action-bar'>
              <i class="edit icon blue actionable" data-action='edit-course'></i>
              ${
                course.enabled === true
                  ? "<i class='pause icon red actionable' data-action='pause-course'></i>"
                  : "<i class='play icon green actionable' data-action='resume-course'></i>"
              }
              <span data-tooltip="Add module" data-variation="mini" data-inverted=""><i class="plus square outline icon actionable" data-action='add-module'></i></span>
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
                    ? '<span data-tooltip="Counts towards progress" data-variation="mini" data-inverted=""><i class="clock icon yellow"></i></span>'
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
                      <span data-tooltip="Add lesson" data-variation="mini" data-inverted="">
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

    remodaler.show({
      title: moduleId ? 'Edit Module' : 'Add Module',
      message: `<div class='remodal-form-line'>
        <label class='remodal-form-line-title'>Module name</label>
        <input type='text' name='module_name' value='${module?.name || ''}'/>
      </div>
      <div class='remodal-form-line'>
        <label class='remodal-form-line-title' for='count_progress'>
          <input type='checkbox' id='count_progress' name='count_progress' ${module?.count_progress ? 'checked' : ''}/>
          Counts towards progress
        </label>
      </div>
      <div class='remodal-form-line'>
        <label class='remodal-form-line-title' for='intro_module'>
          <input type='checkbox' id='intro_module' name='intro_module' ${module?.intro_module ? 'checked' : ''}/>
          Intro module (will not be numbered)
        </label>        
      </div>`,
      type: remodaler.types.FORM,
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
          count_progress: vals.count_progress ? '1' : '0',
          intro_module: vals.intro_module ? '1' : '0'
        };

        let result = await JSUtils.fetch(__futurelms.ajax_url, data);
        if (!result.error) {
          this.getCourses();
        }
      }
    });
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

    remodaler.show({
      title: 'Add Lesson',
      message: `<div class='remodal-form-line'>
        <label class='remodal-form-line-title'>Lesson name</label>
        <input type='text' name='lesson_name' />
      </div>`,
      type: remodaler.types.FORM,
      confirmText: 'Create Lesson',
      confirm: async vals => {
        if (!vals.lesson_name?.length) {
          notifications.show('Lesson name cannot be empty', 'error');
          return false;
        }

        var data = {
          action: 'add_lesson',
          module_id: moduleId,
          name: vals.lesson_name
        };

        let result = await JSUtils.fetch(__futurelms.ajax_url, data);
        if (!result.error) {
          this.getCourses();
        }
      }
    });
  };

  addCourse = () => {
    this.editCourse(null);
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
    remodaler.show({
      title: courseId ? 'Edit Course' : 'Add Course',
      message: `<div class='remodal-form-line'>
        <label class='remodal-form-line-title'>Course name</label>
        <input type='text' name='course_name' value='${course?.name || ''}'/>
      </div>
      <div class='remodal-form-line'>
        <label class='remodal-form-line-title'>Price</label>
        <input type='number' name='course_price' value='${course?.price || ''}' />
      </div>
      <div class='remodal-form-line'>
        <label class='remodal-form-line-title'>Course page Url</label>
        <input type='text' name='course_page_url' value='${course?.course_page_url || ''}' />
      </div>
      <div class='remodal-form-line'>
        <label class='remodal-form-line-title'>Charge Url</label>
        <input type='text' name='course_charge_url' value='${course?.charge_url || ''}' />
      </div>
      <div class='remodal-form-line'>
        <label class='remodal-form-line-title'>Tags</label>
        <input type='text' name='course_tags' value='${course?.tags || ''}' />
      </div>`,
      type: remodaler.types.FORM,
      confirmText: courseId ? 'Update' : 'Create',
      confirm: async vals => {
        if (!vals.course_name?.length) {
          notifications.show('Course name cannot be empty', 'error');
          return false;
        }
        if (!vals.course_price?.length) {
          notifications.show('Course price cannot be empty', 'error');
          return false;
        }

        var data = {
          action: 'edit_course',
          course_id: courseId || '',
          name: vals.course_name,
          price: vals.course_price,
          page_url: encodeURI(vals.course_page_url),
          tags: encodeURIComponent(vals.course_tags),
          charge_url: encodeURIComponent(vals.course_charge_url)
        };

        let result = await JSUtils.fetch(__futurelms.ajax_url, data);
        if (!result.error) {
          //show success notification
          window.notifications.show('Course saved successfully', 'success');
          this.getCourses();
        }
      }
    });
  };
}
