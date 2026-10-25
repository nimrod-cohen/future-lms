<?php
use FutureLMS\classes\VersionManager;
?>
<div id="flms-app">
  <header class="flms-appbar">
    <div class="flms-appbar-row">
      <div class="flex items-center gap-2.5 min-w-0">
        <span class="flex items-center justify-center size-8 rounded-lg bg-brand-700 text-white shrink-0">
          <i data-lucide="graduation-cap" aria-hidden="true"></i>
        </span>
        <span class="text-base font-semibold tracking-tight text-slate-900 whitespace-nowrap flms-name">Future LMS</span>
        <span class="text-xs text-slate-400 tabular-nums"><?php echo esc_html(get_option(VersionManager::SCHOOL_VERSION, 0)); ?></span>
        <span class="hidden xl:block ml-2 pl-3 border-l border-slate-200 text-sm text-slate-500 truncate flms-bidi flms-site"><?php echo esc_html(get_bloginfo('name')); ?></span>
      </div>
      <button type="button" class="flms-cmdk-trigger" aria-haspopup="dialog" aria-keyshortcuts="Meta+K Control+K" aria-label="Search courses, modules, lessons and videos">
        <i data-lucide="search" aria-hidden="true"></i>
        <span class="flms-cmdk-placeholder">Search courses, lessons, videos</span>
        <kbd class="flms-kbd flms-cmdk-shortcut"><span class="flms-mod">⌘</span>K</kbd>
      </button>
      <a class="flms-btn flms-btn-ghost flms-back justify-self-end" href="<?php echo esc_url(admin_url()); ?>" aria-label="Back to WordPress">
        <i data-lucide="arrow-left" aria-hidden="true"></i><span class="hidden sm:inline">Back to WordPress</span>
      </a>
    </div>
    <nav class="flms-tabs" role="tablist" aria-label="Future LMS sections">
      <button type="button" class="flms-tab" role="tab" data-tab="students" aria-selected="false"><i data-lucide="users" aria-hidden="true"></i>Students</button>
      <button type="button" class="flms-tab" role="tab" data-tab="courses" aria-selected="false"><i data-lucide="book-open" aria-hidden="true"></i>Courses</button>
      <button type="button" class="flms-tab" role="tab" data-tab="classes" aria-selected="false"><i data-lucide="calendar-days" aria-hidden="true"></i>Classes</button>
      <button type="button" class="flms-tab" role="tab" data-tab="mailer" aria-selected="false"><i data-lucide="mail" aria-hidden="true"></i>Email</button>
      <button type="button" class="flms-tab" role="tab" data-tab="settings" aria-selected="false"><i data-lucide="settings" aria-hidden="true"></i>Settings</button>
    </nav>
  </header>

  <main class="flms-main">
    <section class="flms-panel" data-tab="students" role="tabpanel" hidden>
      <?php include_once 'students.php'; ?>
    </section>
    <section class="flms-panel" data-tab="courses" role="tabpanel" hidden>
      <?php include_once 'courses.php'; ?>
    </section>
    <section class="flms-panel" data-tab="classes" role="tabpanel" hidden>
      <?php include_once 'classes.php'; ?>
    </section>
    <section class="flms-panel" data-tab="mailer" role="tabpanel" hidden>
      <?php include_once 'mailer.php'; ?>
    </section>
    <section class="flms-panel" data-tab="settings" role="tabpanel" hidden>
      <?php include_once 'settings.php'; ?>
    </section>
  </main>

  <dialog class="flms-palette" aria-label="Search">
    <div class="flms-palette-panel">
      <div class="flms-palette-input">
        <i data-lucide="search" aria-hidden="true"></i>
        <input type="text" placeholder="Search for a course, module, lesson or Vimeo video"
          role="combobox" aria-expanded="true" aria-controls="flms-palette-list" aria-autocomplete="list"
          autocomplete="off" spellcheck="false" />
        <span class="flms-spinner flms-palette-spinner" hidden></span>
      </div>
      <div class="flms-palette-body">
        <ul class="flms-palette-list" id="flms-palette-list" role="listbox" aria-label="Results"></ul>
      </div>
      <div class="flms-palette-foot">
        <span><kbd class="flms-kbd">Esc</kbd>Close</span>
        <span class="ml-auto hidden sm:inline-flex"><kbd class="flms-kbd">↑</kbd><kbd class="flms-kbd">↓</kbd>Navigate</span>
        <span class="hidden sm:inline-flex"><kbd class="flms-kbd">↵</kbd>Show</span>
        <span class="hidden sm:inline-flex"><kbd class="flms-kbd"><span class="flms-mod">⌘</span>↵</kbd>Edit</span>
      </div>
    </div>
  </dialog>
</div>
