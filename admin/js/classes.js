class ClassesTab {
    state = window.StateManagerFactory();

    constructor() {
        this.state.set('open-courses', []);
        this.state.set('open-modules', []);
        this.state.listen('courses', this.render);

        this.getCourses();

        this.currentCourseId = null;
        this.classesData = [];

        document.querySelector('.action-bar [data-action="add-class"]').addEventListener('click', this.addClass);
    }

    addClass = () => {
        this.editClass(null);
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

        remodaler.show({
            title: classId ? 'Edit Class' : 'Add Class',
            message: `
        <div class='remodal-form-line'>
            <label class='remodal-form-line-title'>Class Name</label>
            <input type='text' name='class_name' value='${selectedClass?.name || ''}'/>
        </div>
        <div class='remodal-form-line'>
            <label class='remodal-form-line-title'>Select Course</label>
            <select name="class_course_id" class="remodal-form-select" disabled>
                <option value="">Loading courses...</option>
            </select>
            <div class="remodal-loading-indicator"></div>
        </div>
            <div class='remodal-form-line'>
            <label class='remodal-form-line-title'>Start Date & Time</label>
            <input type="datetime-local" 
                   name="class_start_date" 
                   value="${formatDateTimeForInput(selectedClass?.start_date)}" 
                   class="remodal-datetime-input"
                   step="300" min="${new Date().toISOString().slice(0, 16)}">
        </div>
        <div class='remodal-form-line'>
            <label class='remodal-form-line-title'>Lessons</label>
            <input type='text' name='class_lessons' value='${selectedClass?.lessons || ''}' />
        </div>
        <div class='remodal-form-line'>
            <label class='remodal-form-line-title' style="display: flex; align-items: center; gap: 8px;">
                <input type="checkbox"
                    name="is_live_class"
                    ${selectedClass?.is_live_class ? 'checked' : ''}
                    style="margin: 0; width: auto;">
                This is a live class.
            </label>
        </div>`,
            type: remodaler.types.FORM,
            confirmText: classId ? 'Update' : 'Create',
            confirm: async vals => {
                var data = {
                    action: 'edit_class',
                    class_id: classId || '',
                    course_id: vals.class_course_id,
                    name: vals.class_name,
                    start_date: vals.class_start_date,
                    lessons: vals.class_lessons,
                    is_live_class: vals.is_live_class ? 1 : 0,
                };

                let result = await JSUtils.fetch(__futurelms.ajax_url, data);
                if (!result.error) {
                    this.renderClasses(vals.class_course_id);
                    //show success notification
                    window.notifications.show('Class saved successfully', 'success');
                }
            }
        });

        const courses = this.state.get('courses');

        const coursesArray = Object.keys(courses).map(id => ({
            id: id,
            ...courses[id]
        }));

        const select = document.querySelector('.remodal-form-select[name="class_course_id"]');
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

    render = () => {
        const courses = this.state.get('courses');

        if (!courses) {
            document.querySelector('#classes-list').innerHTML = 'No courses found';
            return;
        }
        const coursesNameValue = Object.keys(courses).map(cid => {
            let course = courses[cid];
            return { name: course.name, value: course.ID };
        })

        let classesTab = COMMON.getTab(COMMON.TABS.CLASSES);
        COMMON.wireDropdown(
            classesTab.querySelector('.ui.search.courses'),
            coursesNameValue,
            item => {
                this.renderClasses(item);
            },
            'Select course'
        );
    };

    renderClasses = async (courseId) => {
        const classes = await this.getClasses(courseId);
        this.currentCourseId = courseId;

        this.classesData = Object.values(classes.classes).map(cls => ({
            id: cls.ID,
            name: cls.name,
            enabled: cls.enabled,
            start_date: cls.start_date,
            total_lessons: cls.total,
            lessons: Object.values(cls.lessons),
            lessons_json: cls.lessons_json,
            is_live_class: cls.is_live_class > 0 ?? false,
        }));

        this.renderAllClasses();
        this.setupClassEventListeners();
        this.setupLessonEventListeners();
    };

    renderAllClasses() {
        document.querySelector('#classes-list').innerHTML = this.classesData
            .map(classItem => `
        <div class="class" data-class-id="${classItem.id}" data-class-live='${classItem.is_live_class}'>
          <div class="class-header">
            <span class='class-id'>${classItem.id}</span>
            <h3 class='class-name' style='${classItem.enabled ? "" : "color: grey;"}'>
              ${classItem.name} ${classItem.is_live_class? "(LIVE)" : ""}
            </h3>
            <span class='class-actions action-bar'>
              ${classItem.enabled
                ? "<i class='pause icon red actionable' data-action='pause-class'></i>"
                : "<i class='play icon green actionable' data-action='resume-class'></i>"}
            </span>
             <span class='action-spinner' style="display: none;"></span>
          </div>
          <div class="class-lessons">
            ${Object.keys(classItem.lessons).length === 0
                ? '<p class="no-lessons">No lessons found</p>'
                : Object.keys(classItem.lessons)
                    .map((lessonId, idx) =>
                        this.renderLesson(classItem.lessons[lessonId], idx, classItem.lessons_json, classItem.is_live_class))
                    .join('')}
          </div>
        </div>`
            )
            .join('');
    }

    renderLesson = (lesson, index, lessonsJSON, isLiveClass) => {
        if (typeof lesson !== 'object') return '';

        const lessonsList = this.parseLessonsJSON(lessonsJSON);
        const lessonEntry = Array.isArray(lessonsList) ? lessonsList.find(l => l.id === lesson.id) : undefined;

        // if a lesson is in the lessonsJSON set this status
        let isOpen = lessonEntry ? lessonEntry.open !== false : true;

        // all live class lessons should be closed if they are not on the list
        if(isLiveClass) {
            isOpen = lessonEntry ? lessonEntry.open !== false : false;
        }

        const videos = Array.isArray(lesson.videos) ? lesson.videos.filter(v => v !== 'text') : [];
        return `
        <div class='class-lesson' data-lesson-id='${lesson.id}'>
          <span class='lesson-order'>${index + 1}</span>
          <span class='lesson-name ${isOpen ? '' : 'disabled'}'>
            ${lesson.name} ${
                videos.length
                    ? `<i class='play circle outline icon green jsutils-popover' 
                     data-content='${videos.join(',')}'></i>`
                    : ''
            }
          </span>
          
          <span class='lesson-actions action-bar disabled'>
                ${
                isOpen
                    ? "<i class='pause icon red actionable' data-action='pause-lesson'></i>"
                    : "<i class='play icon green actionable' data-action='resume-lesson'></i>"
                }
          </span>
        </div>`;
    };

    getCourses = async () => {
        const data = await JSUtils.fetch(__futurelms.ajax_url, {
            action: 'get_all_courses'
        });
        this.state.set('courses', data.courses);
    };

    getClasses = async (courseId) => {
         const data = await JSUtils.fetch(__futurelms.ajax_url, {
            action: 'get_all_classes',
            course_id: courseId
        });
         return data;
    }

    changeSchoolClassStatus = (e, status) => {
        const schoolClass = e.target.closest('.class');
        const schoolClassId = schoolClass.dataset.classId;

        //make sure we want to change the status
        remodaler.show({
            title: 'Change course status',
            message: `Are you sure you want to ${status} this Class?`,
            type: remodaler.types.CONFIRM,
            confirmText: 'Yes',
            confirm: () => {
                const spinner = schoolClass.querySelector('.action-spinner');
                spinner.style.display = 'inline-block';

                JSUtils.fetch(__futurelms.ajax_url, {
                    action: 'change_schoolclass_status',
                    class_id: schoolClassId,
                    status: status
                }).then(data => {
                    if (!data.error) {
                        this.classesData = this.classesData.map(cls =>
                            cls.id === schoolClassId
                                ? { ...cls, enabled: status === 'publish' ? true : false  }
                                : cls
                        );

                        this.renderAllClasses();
                        this.setupClassEventListeners();
                        this.setupLessonEventListeners();
                    }
                });
            }
        });
    };

    changeLessonStatus =  (e, status) => {
        const lessonElement = e.target.closest('.class-lesson');
        const lessonId = lessonElement.dataset.lessonId;
        const classElement = e.target.closest('.class');
        const classId = classElement.dataset.classId;
        const isClassLive = classElement.dataset.classLive;
        remodaler.show({
            title: 'Change lesson status',
            message: `Are you sure you want to ${status} this lesson?`,
            type: remodaler.types.CONFIRM,
            confirmText: 'Yes',
            confirm: () => {

                const spinner = classElement.querySelector('.action-spinner');
                spinner.style.display = 'inline-block';

                JSUtils.fetch(__futurelms.ajax_url, {
                    action: 'set_lesson',
                    lesson_id: lessonId,
                    class_id: classId,
                    is_class_live: isClassLive
                }).then(data => {
                    if (!data.error) {
                        this.renderClasses(this.currentCourseId);
                    }
                })
            }
        });
    };

    setupLessonEventListeners() {
        document.querySelectorAll('.class-lesson .actionable').forEach(icon => {
            const isPause = icon.classList.contains('pause');
            icon.addEventListener('click', (e) => this.changeLessonStatus(
                e,
                isPause ? 'pause' : 'resume'
            ));
        });
    }

    setupClassEventListeners() {
        document.querySelectorAll('.actionable[data-action^="pause-class"], .actionable[data-action^="resume-class"]')
            .forEach(icon => {
                icon.addEventListener('click', (e) => {
                    this.changeSchoolClassStatus(
                        e,
                        icon.classList.contains('pause') ? 'draft' : 'publish'
                    );
                });
            });
    }

    parseLessonsJSON = (lessonsJSON) => {
        // check if input exists and is a non-empty string
        if (!lessonsJSON || typeof lessonsJSON !== 'string' || lessonsJSON.trim().length === 0) {
            return [];
        }

        try {
            const parsed = JSON.parse(lessonsJSON);
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            console.warn('Invalid lsString format:', lessonsJSON);
            return [];
        }
    };
}


