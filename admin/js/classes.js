class ClassesTab {
    state = window.StateManagerFactory();

    constructor() {
        this.state.set('open-courses', []);
        this.state.set('open-modules', []);
        this.state.listen('courses', this.render);

        this.getCourses();
    }

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

    renderClasses = async (item) => {
       const classes = await this.getClasses(item);

        const transformedClasses = Object.values(classes.classes).map(cls => ({
            id: cls.ID,
            name: cls.name,
            enabled: cls.enabled,
            start_date: cls.start_date,
            total_lessons: cls.total,
            lessons: Object.values(cls.lessons)
        }));

        document.querySelector('#classes-list').innerHTML = transformedClasses
            .map(item => {
                var lessonsIds = Object.keys(item.lessons);

                return `
            <div class="class" data-class-id="${item.id}">
                <div class="class-header">
                    <span class='class-id'>${item.id}</span>
                    <h3 class='class-name'>${item.name}</h3>
                    <span class='class-actions action-bar'>
                        <i class="edit icon blue actionable" data-action='edit-course'></i>
                            ${
                                item.enabled === true
                                    ? "<i class='pause icon red actionable' data-action='pause-class'></i>"
                                    : "<i class='play icon green actionable' data-action='resume-class'></i>"
                            }
                        <span data-tooltip="Add module" data-variation="mini" data-inverted=""><i class="plus square outline icon actionable" data-action='add-module'></i></span>
                        <i class="trash alternate outline icon red actionable" data-action='delete-course'></i>
                    </span>
                  </div>
                  <div class="class-lessons">
                     ${lessonsIds.length === 0 ? '<p class="no-lessons">No lessons found</p>' : ''}
                      ${lessonsIds
                    .map((lessonId, idx2) => {
                        console.log(item)
                        const lesson = item.lessons[lessonId];
                        if (typeof lesson !== 'object') return '';
                        //copy the videos array to a new array, and remove the "text" instance from the array
                        const vidoes = lesson.videos.filter(v => v !== 'text');

                        return `<div class='class-lesson' data-lesson-id='${lessonId}'>
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
                    .join('')}
                  </div>
                </div>
            </div>`
            }).join('');


    }

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

    getLessons = async (classId) => {
        const data = await JSUtils.fetch(__futurelms.ajax_url, { action: 'get_lessons', class_id: classId });
        return data;
    }
}


