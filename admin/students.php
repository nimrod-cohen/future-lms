<?php
$flms_year = (int) wp_date('Y');
$flms_months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
?>
<div class="flms-page-head">
  <div>
    <h2 class="flms-page-title">Students</h2>
    <p class="flms-page-desc">Find students by course, class, name or when they joined.</p>
  </div>
  <button type="button" class="flms-btn flms-btn-primary add-student" disabled>
    <i data-lucide="user-plus" aria-hidden="true"></i>Add student
  </button>
</div>

<form class="flms-card p-4 mb-4 students-search-form" onsubmit="return false">
  <div class="grid gap-3 grid-cols-1 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.2fr)_minmax(240px,1fr)] xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.2fr)_minmax(250px,1fr)_auto] items-end">
    <div>
      <label class="flms-label" for="students-course">Course</label>
      <select id="students-course" class="flms-select courses"></select>
    </div>
    <div>
      <label class="flms-label" for="students-class">Class</label>
      <select id="students-class" class="flms-select classes" disabled></select>
    </div>
    <div>
      <label class="flms-label" for="students-search">Name, email or phone</label>
      <div class="flms-input-icon">
        <i data-lucide="search" aria-hidden="true"></i>
        <input id="students-search" class="flms-input" type="search" name="name_or_email" placeholder="Search students" />
      </div>
    </div>
    <div>
      <span class="flms-label">Joined</span>
      <div class="grid grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)] gap-2" id="registration_month">
        <select class="flms-select reg-month" aria-label="Month joined">
          <option value="">Any month</option>
          <?php foreach ($flms_months as $i => $m) : ?>
            <option value="<?php echo $i + 1; ?>"><?php echo esc_html($m); ?></option>
          <?php endforeach; ?>
        </select>
        <select class="flms-select reg-year" aria-label="Year joined">
          <?php for ($y = $flms_year; $y >= 2018; $y--) : ?>
            <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
          <?php endfor; ?>
        </select>
      </div>
    </div>
    <button type="submit" class="flms-btn flms-btn-primary search-students sm:col-span-2 lg:col-span-4 lg:justify-self-end xl:col-span-1">
      <i data-lucide="search" aria-hidden="true"></i>Search
    </button>
  </div>
</form>

<div class="flms-card overflow-hidden">
  <div class="flex items-center justify-between px-4 py-3 border-b border-slate-200">
    <span class="font-semibold">Results</span>
    <span class="text-sm text-slate-500 tabular-nums result-count">0 students</span>
  </div>
  <div class="overflow-x-auto">
    <table class="flms-table is-responsive students md:min-w-[1040px]">
      <thead>
        <tr>
          <th>ID</th>
          <th>Name</th>
          <th>Email</th>
          <th>Phone</th>
          <th>Course</th>
          <th>Class</th>
          <th>Joined</th>
          <th>Progress</th>
          <th><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <tr class="students-empty"><td colspan="9">
          <div class="flms-empty">
            <i data-lucide="users" aria-hidden="true"></i>
            <strong>No students listed yet</strong>
            <span>Pick a course or type a name, then search.</span>
          </div>
        </td></tr>
      </tbody>
    </table>
  </div>
</div>

<dialog id="add-student-modal" class="flms-dialog" aria-labelledby="add-student-title">
  <div class="flms-dialog-head">
    <h3 id="add-student-title" class="text-base font-semibold header">Add student</h3>
    <button type="button" class="flms-iconbtn modal-close" aria-label="Close"><i data-lucide="x" aria-hidden="true"></i></button>
  </div>

  <div class="flms-dialog-body step-1" data-step="1">
    <div class="flms-segmented mb-4" role="group" aria-label="Student">
      <button type="button" data-mode="existing" aria-pressed="true">Existing student</button>
      <button type="button" data-mode="new" aria-pressed="false">New student</button>
    </div>

    <div id="add-existing-student">
      <label class="flms-label" for="existing-student-search">Student</label>
      <div class="flms-combo search student">
        <div class="flms-input-icon">
          <i data-lucide="search" aria-hidden="true"></i>
          <input id="existing-student-search" class="flms-input prompt" type="text" placeholder="Name, email or phone" />
        </div>
      </div>
      <p class="flms-hint">Type at least 2 characters.</p>
    </div>

    <div id="add-new-student" class="grid gap-3 sm:grid-cols-2" hidden>
      <div class="sm:col-span-2">
        <label class="flms-label" for="new-student-name">Full name</label>
        <input id="new-student-name" class="flms-input" type="text" name="full-name" autocomplete="off" />
      </div>
      <div>
        <label class="flms-label" for="new-student-email">Email</label>
        <input id="new-student-email" class="flms-input" type="email" name="student-email" autocomplete="off" />
      </div>
      <div>
        <label class="flms-label" for="new-student-phone">Phone</label>
        <input id="new-student-phone" class="flms-input" type="tel" name="phone" autocomplete="off" />
      </div>
    </div>
  </div>

  <div class="flms-dialog-body step-2" data-step="2" hidden>
    <label class="flms-label" for="comment">Comment <span class="font-normal text-slate-500">(optional)</span></label>
    <textarea id="comment" class="flms-textarea" rows="4" placeholder="A note about this enrollment"></textarea>
    <p class="flms-hint">The student is enrolled without a payment.</p>
  </div>

  <div class="flms-dialog-foot">
    <button type="button" class="flms-btn mr-auto prev" disabled><i data-lucide="arrow-left" aria-hidden="true"></i>Back</button>
    <button type="button" class="flms-btn modal-close">Cancel</button>
    <button type="button" class="flms-btn flms-btn-primary next">Next</button>
  </div>
</dialog>
