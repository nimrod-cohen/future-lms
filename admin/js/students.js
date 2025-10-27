class StudentsTab {
  state = window.StateManagerFactory();
  currentClassName = '';
  LAST_STEP = 3;

  constructor() {
    this.changeStep = this.changeStep.bind(this);
    this.enableWizardButtons = this.enableWizardButtons.bind(this);
    this.fetchStudents = this.fetchStudents.bind(this);
    this.render = this.render.bind(this);
    this.removeStudentFromClass = this.removeStudentFromClass.bind(this);
    this.addStudentToClass = this.addStudentToClass.bind(this);
    this.clickNext = this.clickNext.bind(this);
    this.clickPrev = this.clickPrev.bind(this);
    this.showAddStudent = this.showAddStudent.bind(this);
    this.validateStep = this.validateStep.bind(this);

    this.state.listen('register-current-step', this.changeStep);
    this.state.listen('register-current-step', this.enableWizardButtons);

    let tab = COMMON.getTab(COMMON.TABS.STUDENTS);

    //init values
    this.state.set('register-current-step', 1);
    this.state.set('add-student-is-existing', true);
    this.state.set('modal', document.querySelector('#add-student-modal'));
    this.state.listen('students', this.render);
    this.state.set('students', []);

    jQuery(tab.querySelector('#registration_month')).calendar({
      type: 'month'
    });

    tab.querySelector('.search-students').addEventListener('click', e => {
      e.preventDefault();
      this.fetchStudents();
    });

    JSUtils.fetch(__futurelms.ajax_url, { action: 'get_all_courses' }).then(data => {
      var coursesNameValue = Object.keys(data.courses).map(cid => {
        let course = data.courses[cid];
        return { name: course.name, value: cid };
      });

      //sort by name
      coursesNameValue = coursesNameValue.sort((a, b) => a.name.localeCompare(b.name));

      COMMON.wireDropdown(
        tab.querySelector('.ui.search.courses'),
        coursesNameValue,
        item => {
          let classes = jQuery(`${COMMON.getTabSelector(COMMON.TABS.STUDENTS)} .ui.search.classes`);

          if (!item || item == '') {
            classes.dropdown('clear');
            classes.dropdown('change values', []);
            return;
          }

          JSUtils.fetch(__futurelms.ajax_url, {
            action: 'search_classes',
            course_id: item
          }).then(data => {
            COMMON.wireDropdown(
              classes,
              data.results,
              item => {
                item !== ''
                  ? tab.querySelector('.add-student').classList.remove('disabled')
                  : tab.querySelector('.add-student').classList.add('disabled');
              },
              'Select class'
            );
          });
        },
        'Select course'
      );
    });

    //student search in add student modal
    let modal = this.state.get('modal');

    modal.querySelector('.ui.search.student input').addEventListener('focus', e => {
      e.target.closest('.ui.search').removeAttribute('data-id');
      e.target.value = '';
    });

    COMMON.wireSearch(
      `#add-student-modal .ui.search.student`,
      'search_students',
      student => {},
      () => {
        return {
          class_id: tab.querySelector('.ui.search.classes').getAttribute('data-id')
        };
      }
    );

    //clear errors on field entry
    let inputs = modal.querySelectorAll('.ui.input input');
    inputs.forEach(input =>
      input.addEventListener('focus', e => e.target.closest('.ui.input').classList.remove('error'))
    );

    //add student to class
    tab.querySelector('button.add-student').addEventListener('click', this.showAddStudent);

    modal.querySelector('.button.next').addEventListener('click', this.clickNext);
    modal.querySelector('.button.prev').addEventListener('click', this.clickPrev);

    let step1 = modal.querySelector('.step-1');

    let radios = step1.querySelectorAll('input[type=radio]');
    radios.forEach(radio =>
      radio.addEventListener('change', e => {
        this.state.set('add-student-is-existing', e.target.getAttribute('data-target') === 'add-existing-student');
      })
    );

    //disable/enable inputs of selected option
    this.state.listen('add-student-is-existing', value => {
      var inps = step1.querySelectorAll('.input');
      inps.forEach(inp => inp.classList.add('disabled'));
      let selector = value === true ? 'add-existing-student' : 'add-new-student';
      inps = step1.querySelectorAll(`#${selector} .input`);
      inps.forEach(inp => inp.classList.remove('disabled'));
    });
  }

  validateStep(step) {
    let modal = this.state.get('modal');
    let failed = false;
    switch (step) {
      case 1:
        let step1 = modal.querySelector('.step-1');
        if (this.state.get('add-student-is-existing')) {
          let student = modal.querySelector('.search.student');
          let id = student.getAttribute('data-id');
          if (!id) {
            student.querySelector('.ui.input').classList.add('error');
            failed = true;
          }
        } else {
          let email = step1.querySelector('.ui.input input[name=student-email]').value.trim();
          let name = step1.querySelector('.ui.input input[name=full-name]').value.trim();
          let phone = step1.querySelector('.ui.input input[name=phone]').value.trim();

          email = COMMON.removeNonAsciiChars(email);

          if (!email || email.length === 0) {
            step1.querySelector('.ui.input input[name=student-email]').closest('.ui.input').classList.add('error');
            failed = true;
          }
          if (!phone || phone.length === 0) {
            step1.querySelector('.ui.input input[name=phone]').closest('.ui.input').classList.add('error');
            failed = true;
          }
          if (!name || name.length === 0) {
            step1.querySelector('.ui.input input[name=full-name]').closest('.ui.input').classList.add('error');
            failed = true;
          }
        }
        break;
    }

    return !failed;
  }

  showAddStudent() {
    console.log('show add student');
    let modal = document.querySelector('#add-student-modal');

    jQuery(modal)
      .modal({
        inverted: true,
        onHidden: () => {
          this.state.set('register-current-step', 1);
        }
      })
      .modal('show');

    modal.querySelector('.header').innerText = `Add student to class ${this.currentClassName}`;
  }

  clickNext(e) {
    e.stopPropagation();
    let step = this.state.get('register-current-step');
    if (this.validateStep(step)) {
      step++;
      this.state.set('register-current-step', step);
    }
  }

  clickPrev(e) {
    e.stopPropagation();
    let step = this.state.get('register-current-step');
    step--;
    this.state.set('register-current-step', step);
  }

  enableWizardButtons(step) {
    let modal = document.querySelector('#add-student-modal');

    if (step === 1) {
      modal.querySelector('.button.prev').classList.add('disabled');
      modal.querySelector('.button.next').classList.remove('disabled');
    } else if (step === this.LAST_STEP) {
      modal.querySelector('.button.prev').classList.remove('disabled');
      modal.querySelector('.button.next').classList.add('disabled');
    } else {
      modal.querySelector('.button.prev').classList.remove('disabled');
      modal.querySelector('.button.next').classList.remove('disabled');
    }
  }

  changeStep(step) {
    try {
      let modal = document.querySelector('#add-student-modal');

      let steps = modal.querySelectorAll(':scope > .content');
      steps.forEach(stp => stp.classList.add('hidden'));
      modal.querySelector(`:scope > .content.step-${step}`).classList.remove('hidden');

      if (step === this.LAST_STEP) {
        let tab = COMMON.getTab(COMMON.TABS.STUDENTS);

        let userData = {};

        if (this.state.get('add-student-is-existing')) {
          userData = {
            id: modal.querySelector('.search.student').getAttribute('data-id'),
            name: modal.querySelector('.search.student input.prompt').value
          };
        } else {
          let email = modal.querySelector('.ui.input input[name=student-email]').value.trim();
          email = COMMON.removeNonAsciiChars(email);

          userData = {
            email: encodeURIComponent(email),
            name: modal.querySelector('.ui.input input[name=full-name]').value.trim(),
            phone: modal.querySelector('.ui.input input[name=phone]').value.trim()
          };
        }
        let courseId = tab.querySelector('.ui.search.courses').getAttribute('data-id');

        userData.class_id = tab.querySelector('.ui.search.classes').getAttribute('data-id');

        let step2 = modal.querySelector('.content.step-2');

        userData.comment = step2.querySelector('textarea#comment').value;
        this.addStudentToClass(courseId, userData, sum);
      }
    } catch (ex) {
      alert(ex.message);
    }
  }

  render() {
    const tab = COMMON.getTab(COMMON.TABS.STUDENTS);

    let studentTable = tab.querySelector('table.students tbody');
    studentTable.innerHTML = '';

    let data = this.state.get('students');

    let students = Object.values(data);
    if (students.length === 0) {
      studentTable.insertAdjacentHTML('beforeend', "<tr><td colspan='5'>No results</td></tr>");
      tab.querySelector('.result-count').innerText = '0 students';
    }

    tab.querySelector('.result-count').innerText = `${students.length} students`;
    students.forEach(student => {
      let phone = [false, '', 'undefined'].some(v => v === student.phone) ? '' : student.phone;
      let name = [false, '', 'undefined'].some(v => v === student.display_name) ? '' : student.display_name;
      //add row
      studentTable.insertAdjacentHTML(
        'beforeend',
        `<tr data-id=${student.id} class-id=${student.class_id} course-id=${student.course_id}>
        <td>${student.id}</td>
        <td>${student.course_name}</td>
        <td>${student.class_name}</td>
        <td>${student.registration_date.substring(0, 10)}</td>
        <td>${student.user_email}</td>
        <td>${name}</td>
        <td>${phone}</td>
        <td>
          <span data-inverted='' data-position='top right' data-tooltip='Remove student'><i class="minus red circle icon clickable remove-class"></i></span>
          <span data-inverted='' data-position='top right' data-tooltip='Show progress'><i class="tasks blue circle icon clickable show-progress"></i></span>
        </td>
      </tr>`
      );

      //attach removal event
      let studentRow = studentTable.querySelector(`tr[data-id='${student.id}'][class-id='${student.class_id}']`);
      let td = studentRow.querySelector(`td:last-child`);
      let remove = td.querySelector('i.remove-class');
      let showProgress = td.querySelector('i.show-progress');
      const courseId = studentRow.getAttribute('course-id');

      const removeStudentClick = e => {
        remodaler.show({
          title: `Remove student ${student.display_name}`,
          type: remodaler.types.CONFIRM,
          message: 'Are you sure?',
          confirmText: 'Yes, Remove',
          confirm: () => {
            let classId = studentRow.getAttribute('class-id');

            remove.removeEventListener('click', removeStudentClick);
            td.innerHTML = "<div class='ui active tiny inline loader'></div>";
            this.removeStudentFromClass(student.id, classId, courseId);
          }
        });
      };

      const showProgressClick = e => {
        JSUtils.fetch(__futurelms.ajax_url, {
          action: 'get_student_progress',
          student_id: student.id
        }).then(data => {
          data.progress.courses.forEach(course => {
            if (!data.course_tree[course.course_id]) return;

            var courseProgress = 0;
            //measure course size
            course.modules.forEach(module => {
              module.lessons.forEach(lesson => {
                lesson.videos.forEach(video => (courseProgress += video.percent));
              });
            });

            const courseSize = data.course_tree[course.course_id].total;
            courseProgress = Math.floor(Math.min(100, (courseProgress / courseSize) * 100));

            let progressBtn = studentTable.querySelector(
              `tr[data-id='${student.id}'][course-id='${course.course_id}'] td:last-child i.show-progress`
            );

            progressBtn.removeEventListener('click', showProgressClick);
            progressBtn.replaceWith(`${courseProgress}%`);
          });
        });
      };

      showProgress.addEventListener('click', showProgressClick);
      remove.addEventListener('click', removeStudentClick);
    });
  }

  fetchStudents() {
    const tab = COMMON.getTab(COMMON.TABS.STUDENTS);

    let dt = jQuery('#registration_month').calendar('get date');
    let month = '',
      year = '';

    if (dt) {
      month = dt.getMonth() + 1;
      year = dt.getFullYear();
    }

    JSUtils.fetch(__futurelms.ajax_url, {
      action: 'get_students',
      course_id: tab.querySelector('.dropdown.courses').getAttribute('data-id'),
      class_id: tab.querySelector('.dropdown.classes').getAttribute('data-id'),
      search: tab.querySelector(`[name='name_or_email']`).value,
      month: month,
      year: year
    }).then(data => {
      this.state.set('students', data);
    });
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
      jQuery('#add-student-modal').modal('hide');
      this.fetchStudents();
    });
  }

  // the course id is required to ensure the student is not related to another class of this course.
  removeStudentFromClass(studentId, classId, courseId) {
    JSUtils.fetch(__futurelms.ajax_url, {
      action: 'remove_class',
      student_id: studentId,
      course_id: courseId,
      class_id: classId
    }).then(() => {
      this.fetchStudents();
    });
  }
}
