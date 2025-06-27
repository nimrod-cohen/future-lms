class ClassesTab {
    state = window.StateManagerFactory();

    constructor() {
        this.state.set('open-courses', []);
        this.state.set('open-modules', []);
        this.state.listen('courses', this.render);
        this.state.listen('courses', this.updateDropdown);

        this.getCourses();

        this.currentCourseId = null;
        this.currentClassId = null;
        this.classesData = [];

        JSUtils.addGlobalEventListener('#classes-list', ".actionable[data-action='edit-class']", 'click', e => {
            this.editClass(e.target.closest('.class').dataset.classId);
        });

        JSUtils.addGlobalEventListener('#classes-list', ".actionable[data-action='delete-class']", 'click', e =>
            this.changeSchoolClassStatus(e, 'trash')
        );

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
                <select name="class_course_id" class="remodal-form-select " ${classId ? 'disabled' : ''}>
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
            <input type='text' name='class_lessons' value='${selectedClass?.lessons_json || ''}' />
        </div>
        <div class='remodal-form-line'>
            <label class='remodal-form-line-title' style="display: flex; align-items: center; gap: 8px;">
                <input type="checkbox"
                    name="is_live_class"
                    ${selectedClass?.is_live_class > 0 ? 'checked' : ''}
                    style="margin: 0; width: auto;">
                This is a live class.
            </label>
        </div>`,
            type: remodaler.types.FORM,
            confirmText: classId ? 'Update' : 'Create',
            confirm: vals => {
                if (!vals.class_name?.length) {
                    notifications.show('Class name cannot be empty', 'error');
                    return false;
                }
                if (!vals.class_course_id?.length) {
                    notifications.show('Please select a course for your class', 'error');
                    return false;
                }

                var data = {
                    action: 'edit_class',
                    class_id: classId || '',
                    course_id: vals.class_course_id,
                    name: vals.class_name,
                    start_date: vals.class_start_date,
                    lessons: vals.class_lessons,
                    is_live_class: vals.is_live_class ? 1 : 0,
                };

                COMMON.showLoader();

                JSUtils.fetch(__futurelms.ajax_url, data).then(data => {
                    if (!data.error) {
                        this.getCourses(() => {window.notifications.show('Class saved successfully', 'success')})
                    }
                })
            }
        });

       this.updateDropdown(selectedClass);
    };

    render = () => {
        const courses = this.state.get('courses');

        if (!courses) {
            document.querySelector('#classes-list').innerHTML = 'No courses found';
            return;
        }
        const coursesNameValue = Object.keys(courses).map(cid => {
            let course = courses[cid];
            return {name: course.name, value: course.ID};
        })

        let classesTab = COMMON.getTab(COMMON.TABS.CLASSES);
        COMMON.wireDropdown(
            classesTab.querySelector('.ui.search.courses'),
            coursesNameValue,
            courseId => {
                this.currentCourseId = courseId;
                this.renderAllClasses(this.currentClassId);
            },
            'Select course',
            this.currentCourseId,
            true
        );
    };

    renderClasses = (classes) => {
        this.classesData = (Array.isArray(classes) && classes.length > 0 && classes[0] !== undefined)
            ? classes.map(cls => ({
                id: cls?.ID,
                name: cls?.name,
                enabled: cls?.enabled,
                start_date: cls?.start_date,
                total_lessons: cls?.total,
                modules: cls?.modules ? Object.values(cls.modules) : [],
                lessons: cls?.lessons ? Object.values(cls.lessons) : [],
                lessons_json: cls?.lessons_json,
                is_live_class: (cls?.is_live_class ?? 0) > 0,
            }))
            : [];

        document.querySelector('#classes-list').innerHTML = this.classesData
            .map(classItem => {
                return `
                <div class="class" data-class-id="${classItem.id}" data-class-live='${classItem.is_live_class}'>
                    <div class="class-header">
                        <span class='class-id'>${classItem.id}</span>
                        <h3 class='class-name' style='${classItem.enabled ? "" : "color: grey;"}'>
                            ${classItem.name} ${classItem.is_live_class ? "(LIVE)" : ""}
                        </h3>
                        <span class='class-actions action-bar'>
                            <i class="edit icon blue actionable" data-action='edit-class'></i>
                            ${classItem.enabled
                    ? "<i class='pause icon red actionable' data-action='pause-class'></i>"
                    : "<i class='play icon green actionable' data-action='resume-class'></i>"}
                            <i class="trash alternate outline icon red actionable" data-action='delete-class'></i>
                        </span>
                    </div>
                    <div class="class-modules">
                        ${this.renderModules(classItem)}
                    </div>
                </div>`;
            }).join('');

        this.setupClassEventListeners();
        this.setupLessonEventListeners();
    };

    renderAllClasses = (classId) => {
        this.getClasses(this.currentCourseId).then(response => {
            this.renderClasses([response.classes[classId]]);

            const classesNameValue = this.mapClassesToNameValue(response);

            COMMON.wireDropdown(
                COMMON.getTab(COMMON.TABS.CLASSES).querySelector('.ui.search.classes'),
                classesNameValue,
                classId => {
                    this.currentClassId = classId;
                    this.renderClasses([response.classes[classId]]);
                },
                'Select class',
                classId,
                true
            );
            COMMON.hideLoader();
        });
    };

    renderModules(classItem) {
        const moduleIds = Object.keys(classItem.modules).sort((a, b) => {
            return classItem.modules[a].order - classItem.modules[b].order;
        });

        if (moduleIds.length === 0) {
            return '<p class="no-modules">No modules found</p>';
        }

        const introModulesCount = Object.values(classItem.modules)
            .filter(m => m.intro_module).length;

        return moduleIds.map((mid, idx) => {
            if (typeof classItem.modules[mid] !== 'object') return '';
            const module = classItem.modules[mid];

            const isIntroModule = module.intro_module;
            const moduleNumber = isIntroModule ? 'In' : idx + 1 - introModulesCount;
            const disabledClass = module.enabled === true ? '' : 'disabled';
            const progressIcon = module.count_progress
                ? '<span data-tooltip="Counts towards progress" data-variation="mini" data-inverted=""><i class="clock icon yellow"></i></span>'
                : '';

            return `
            <div class="class-module" data-module-id='${mid}'>
                <span class='module-order ${isIntroModule ? 'intro' : ''}'>
                    ${moduleNumber}
                </span>
                <span class='module-name ${disabledClass}'>
                    ${progressIcon} ${module.name}
                </span>
                <div class="module-lessons">
                    ${this.renderLessons(module,classItem)}
                </div>
            </div>`;
        }).join('');
    }

    renderLessons(module,classItem) {
        if (Object.keys(module.lessons).length === 0) {
            return '<p class="no-lessons">No lessons found</p>';
        }

        // sort lessons by their set order
        let lessonIds = Object.keys(module.lessons);
        lessonIds = lessonIds.sort((a, b) => {
            return module.lessons[a].order - module.lessons[b].order;
        });

        return lessonIds.map((lessonId, index) =>
                this.renderLesson(module.lessons[lessonId], index, classItem.lessons_json, classItem.is_live_class))
            .join('');
    }

    renderLesson = (lesson, index, lessonsJSON, isLiveClass) => {
        if (typeof lesson !== 'object') return '';

        const lessonsList = this.parseLessonsJSON(lessonsJSON);
        const lessonEntry = Array.isArray(lessonsList) ? lessonsList.find(l => l.id === lesson.id) : undefined;

        // if a lesson is in the lessonsJSON set this status
        let isOpen = lessonEntry ? lessonEntry.open !== false : true;

        // all live class lessons should be closed if they are not on the list
        if (isLiveClass) {
            isOpen = lessonEntry ? lessonEntry.open !== false : false;
        }

        const videos = Array.isArray(lesson.videos) ? lesson.videos.filter(v => v !== 'text') : [];
        return `
        <div class='module-lesson' data-lesson-id='${lesson.id}'>
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
            ${isLiveClass
                ? (isOpen
                        ? "<i class='pause icon red actionable' data-action='pause-lesson'></i>"
                        : "<i class='play icon green actionable' data-action='resume-lesson'></i>"
                )
                : "Recorded class"
            }          
          </span>
        </div>`;
    };

    getCourses = async (callback) => {
        const data = await JSUtils.fetch(__futurelms.ajax_url, {
            action: 'get_all_courses'
        });

        this.state.set('courses', data.courses);
        if (callback) callback();
    };

    getClasses = async (courseId) => {
        let data = await JSUtils.fetch(__futurelms.ajax_url, {
            action: 'get_all_classes',
            course_id: courseId
        });
        this.state.set('classes', data.classes);
        return data
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
                COMMON.showLoader();

                JSUtils.fetch(__futurelms.ajax_url, {
                    action: 'change_schoolclass_status',
                    class_id: schoolClassId,
                    status: status
                }).then(data => {
                    if (!data.error) {
                        this.getCourses(() => {window.notifications.show('Class ' + status  + 'ed' + ' successfully', 'success')});
                        this.setupClassEventListeners();
                        this.setupLessonEventListeners();
                    }
                });
            }
        });
    };

    changeLessonStatus = (e, status) => {
        const lessonElement = e.target.closest('.module-lesson');
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

                COMMON.showLoader();

                JSUtils.fetch(__futurelms.ajax_url, {
                    action: 'set_lesson',
                    lesson_id: lessonId,
                    class_id: classId,
                    is_class_live: isClassLive
                }).then(data => {
                    if (!data.error) {
                        this.getCourses(() => {window.notifications.show('Lesson ' + status  + 'd' + ' successfully', 'success')});
                    }
                })
            }
        });
    };

    updateDropdown = (selectedClass = null) => {
        const courses = this.state.get('courses');
        if (!courses) return;

        const select = document.querySelector('.remodal-form-select[name="class_course_id"]');
        if (!select) return;

        const coursesArray = Object.keys(courses).map(id => ({
            id: id,
            ...courses[id]
        }));

        select.innerHTML = `
            <option value="">-- Select a Course --</option>
            ${coursesArray
            .map(
                course =>
                    `<option value="${course.id}" ${selectedClass?.course === course.id ? 'selected' : ''}>
                        ${course.name}
                    </option>`
            )
            .join('')}`;

        // Disable selection for edit class screen
        if (!selectedClass)
            select.disabled = false;
    };

    setupLessonEventListeners() {
        document.querySelectorAll('.module-lesson .actionable').forEach(icon => {
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

    mapClassesToNameValue = (response) => {
        const classesNameValue = Object.keys(response.classes).map(cid => {
            let classItem = response.classes[cid];
            return {name: classItem.name, value: classItem.ID};
        });
        return classesNameValue;
    }
}


