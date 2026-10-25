<div class="flms-page-head">
  <div>
    <h2 class="flms-page-title">Email</h2>
    <p class="flms-page-desc">Write to every student in a class.</p>
  </div>
</div>

<form class="flms-card p-4 sm:p-5 space-y-4 mailer-form" onsubmit="return false">
  <div class="grid gap-3 sm:grid-cols-2">
    <div>
      <label class="flms-label" for="mailer-course">Course</label>
      <select id="mailer-course" class="flms-select courses"></select>
    </div>
    <div>
      <label class="flms-label" for="mailer-class">Class</label>
      <select id="mailer-class" class="flms-select classes" disabled></select>
    </div>
  </div>

  <div>
    <label class="flms-label" for="txtSubject">Subject</label>
    <input type="text" id="txtSubject" class="flms-input" dir="rtl" placeholder="נושא" />
  </div>

  <div>
    <span class="flms-label">Message</span>
    <div id="mailer-content"></div>
  </div>

  <div class="flex flex-col-reverse sm:flex-row sm:items-center gap-2 pt-1">
    <p class="flms-hint sm:mr-auto mt-0">Send a test first: it goes to the site's admins only.</p>
    <button type="button" class="flms-btn send-test">
      <i data-lucide="flask-conical" aria-hidden="true"></i>Send test to admins
    </button>
    <button type="button" class="flms-btn flms-btn-primary send">
      <i data-lucide="send" aria-hidden="true"></i>Send to class
    </button>
  </div>
</form>
<script>
  jQuery('#mailer-content').trumbowyg({ lang: 'he', autogrow: true });
</script>
