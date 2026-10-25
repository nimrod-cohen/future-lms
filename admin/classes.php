<div class="flms-page-head">
  <div>
    <h2 class="flms-page-title">Classes</h2>
    <p class="flms-page-desc">A class is self-paced, or opens its lessons on dates you set.</p>
  </div>
  <button type="button" class="flms-btn flms-btn-primary" data-action="add-class">
    <i data-lucide="plus" aria-hidden="true"></i>Add class
  </button>
</div>

<div class="classes-screen">
  <div class="flms-card p-4 mb-4 grid gap-3 sm:grid-cols-2 classes-filters">
    <div>
      <label class="flms-label" for="classes-course">Course</label>
      <select id="classes-course" class="flms-select courses"></select>
    </div>
    <div>
      <label class="flms-label" for="classes-class">Class</label>
      <select id="classes-class" class="flms-select classes" disabled></select>
    </div>
  </div>

  <div class="flms-card classes-empty">
    <div class="flms-empty">
      <i data-lucide="calendar-days" aria-hidden="true"></i>
      <strong>Pick a course and a class</strong>
      <span>Then choose whether it is self-paced or opens lessons on set dates.</span>
    </div>
  </div>

  <div class="flms-card class-panel hidden transition-opacity">
    <div class="flex flex-wrap items-start justify-between gap-4 p-4 sm:p-5 border-b border-slate-200 class-header">
      <div class="min-w-0">
        <h3 class="text-lg font-semibold flms-bidi class-name"></h3>
        <div class="mt-0.5 text-sm text-slate-500 class-meta"></div>
      </div>
      <div class="flex flex-col items-start sm:items-end gap-2 max-w-md">
        <div class="flms-segmented pacing" role="group" aria-label="Pacing">
          <button type="button" data-paced="0" aria-pressed="false">Self-paced</button>
          <button type="button" data-paced="1" aria-pressed="false">Paced</button>
        </div>
        <p class="text-sm text-slate-500 sm:text-right pacing-note"></p>
      </div>
    </div>
    <div class="class-body"></div>
  </div>
</div>
