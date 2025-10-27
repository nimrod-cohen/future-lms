<div class="ui padded height grid stackable">
  <div class="ui form row">
    <div class="sixteen wide column">
      <div class="students-search-form">
        <div class="field">
          <div class="ui search selection dropdown courses">
            <div class="text"></div>
          </div>
        </div>
        <div class="field">
          <div class="ui search selection dropdown classes">
            <div class="text"></div>
          </div>
        </div>
        <div class="field">
          <input type="text" name="name_or_email" placeholder="Search name or email">
        </div>
        <div class="field">
          <div class="ui calendar" id="registration_month">
            <div class="ui input left icon">
              <i class="calendar icon"></i>
              <input type="text" placeholder="Date">
            </div>
          </div>
        </div>
        <div class="field">
          <button class="ui labeled icon blue button search-students">
            <i class="search icon"></i>
            Search
          </button>
        </div>
        <div class="field">
          <button class="ui labeled icon green disabled button add-student">
            <i class="dollar icon"></i>
            Add Student
          </button>
        </div>
      </div>
    </div>
  </div>
  <div class="row">
    <div class="sixteen wide column">
      <table class="ui very relaxed small celled table students">
        <thead>
          <th>ID</th>
          <th>Course</th>
          <th>Class</th>
          <th>Registration Date</th>
          <th>Email</th>
          <th>Name</th>
          <th>Phone</th>
          <th class="result-count">0 students</th>
        </thead>
        <tbody>
          <tr><td colspan="5">No results</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
<div class="ui modal" id="add-student-modal">
  <div class="header">Header</div>
  <div class="content step-1">
    <div class="ui grid">
      <div class="row">
        <div class="two wide column middle aligned">
          <div class="field">
            <div class="ui radio checkbox">
              <input id="radio-existing-student" type="radio" name="radio" checked="checked" data-target='add-existing-student'>
              <label for="radio-existing-student">Existing</label>
            </div>
          </div>
        </div>
        <div id="add-existing-student" class="fourteen wide column">
          <div class="field">
            <div class="ui search student">
              <div class="ui fluid icon input">
                <input class="prompt" type="text" placeholder="Search a student" />
                <i class="search icon"></i>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="row">
        <div class="two wide column">
          <div class="ui radio checkbox">
            <input type="radio" name="radio" data-target='add-new-student' id="radio-new-student">
            <label for="radio-new-student">New</label>
          </div>
        </div>
        <div id='add-new-student' class="fourteen wide column">
          <div class="ui grid">
            <div class="row">
              <div class="eight wide column">
                <div class="field">
                  <div class="ui fluid left icon input disabled">
                    <input type="text" name="full-name" placeholder="Name">
                    <i class="user icon"></i>
                  </div>
                </div>
              </div>
              <div class="eight wide column">
                <div class="field">
                  <div class="ui fluid left icon input disabled">
                    <input type="text" name="phone" placeholder="Phone">
                    <i class="phone icon"></i>
                  </div>
                </div>
              </div>
            </div>
            <div class="row">
              <div class="eight wide column">
                <div class="field">
                  <div class="ui fluid left icon input disabled">
                    <input type="text" name="student-email" placeholder="Email">
                    <i class="at icon"></i>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="actions">
    <div class="ui primary prev button disabled">Back</div>
    <div class="ui primary next button">Next</div>
    <div class="ui cancel button">Cancel</div>
  </div>
</div>

