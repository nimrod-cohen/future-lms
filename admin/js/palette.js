/**
 * The search window (⌘K / Ctrl K, or "/"). The field in the top bar grows into
 * a floating window; results come from search_catalog; when the box is empty
 * it offers quick commands. ↵ shows an item in the course tree, ⌘↵ edits it.
 */
class CommandPalette {
  constructor({ courses }) {
    this.courses = courses;
    this.trigger = document.querySelector('.flms-cmdk-trigger');
    this.dialog = document.querySelector('dialog.flms-palette');
    this.panel = this.dialog.querySelector('.flms-palette-panel');
    this.input = this.dialog.querySelector('.flms-palette-input input');
    this.list = this.dialog.querySelector('.flms-palette-list');
    this.spinner = this.dialog.querySelector('.flms-palette-spinner');
    this.items = [];
    this.active = 0;
    this.seq = 0;
    this.timer = null;

    const mac = /Mac|iPhone|iPad|iPod/.test(navigator.platform || navigator.userAgent);
    this.modKey = mac ? 'metaKey' : 'ctrlKey';
    document.querySelectorAll('#flms-app .flms-mod').forEach(el => (el.textContent = mac ? '⌘' : 'Ctrl '));

    this.trigger.addEventListener('click', () => this.open());
    // Typing straight onto the focused field opens it with that letter.
    this.trigger.addEventListener('keydown', e => {
      if (e.key.length === 1 && !e.metaKey && !e.ctrlKey && !e.altKey && e.key !== ' ') {
        e.preventDefault();
        this.open(e.key);
      }
    });
    // Capture phase, and stop it there: ⌘K is ours on this screen, so no other
    // palette (WordPress's, another plugin's) opens underneath.
    document.addEventListener('keydown', e => {
      if ((e.metaKey || e.ctrlKey) && !e.altKey && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        e.stopImmediatePropagation();
        this.dialog.open ? this.close() : this.open();
      } else if (e.key === '/' && !this.dialog.open && !this.isTyping(e.target) && !document.querySelector('.slideout-bg, dialog[open]')) {
        e.preventDefault();
        this.open();
      }
    }, true);

    this.dialog.addEventListener('cancel', e => {
      e.preventDefault(); // Esc: close with the animation
      this.close();
    });
    this.dialog.addEventListener('click', e => {
      if (e.target === this.dialog) this.close(); // the backdrop
    });

    this.input.addEventListener('input', () => {
      clearTimeout(this.timer);
      this.timer = setTimeout(this.search, 200);
    });
    this.input.addEventListener('keydown', this.onKey);
    this.list.addEventListener('mousemove', e => {
      const li = e.target.closest('[data-index]');
      if (li && +li.dataset.index !== this.active) this.setActive(+li.dataset.index, false);
    });
    this.list.addEventListener('click', e => {
      const li = e.target.closest('[data-index]');
      if (li) this.run(+li.dataset.index, e[this.modKey] ? 'edit' : 'show');
    });
  }

  isTyping = el => !!el?.closest?.('input, textarea, select, [contenteditable=""], [contenteditable="true"]');

  commands = () => [
    { title: 'Go to Students', sub: 'Find, add and remove students', icon: 'users', run: () => COMMON.showTab('students') },
    { title: 'Go to Courses', sub: 'Courses, modules and lessons', icon: 'book-open', run: () => COMMON.showTab('courses') },
    { title: 'Go to Classes', sub: 'Pacing and lesson dates', icon: 'calendar-days', run: () => COMMON.showTab('classes') },
    { title: 'Go to Email', sub: 'Write to a class', icon: 'mail', run: () => COMMON.showTab('mailer') },
    { title: 'Go to Settings', sub: 'School defaults', icon: 'settings', run: () => COMMON.showTab('settings') },
    {
      title: 'Add a course',
      sub: 'Opens the course editor',
      icon: 'plus',
      run: () => {
        COMMON.showTab('courses');
        this.courses.addCourse();
      }
    },
    {
      title: 'Add a class',
      sub: 'Opens the class editor',
      icon: 'plus',
      run: () => {
        COMMON.showTab('classes');
        this.courses.addClass();
      }
    }
  ];

  /* ---------------------------------------------------------- open / close */

  open = (seed = '') => {
    if (this.dialog.open && !this.closing) return;
    const reopening = this.dialog.open; // asked again while it was closing
    if (reopening) {
      this.closeToken = null; // the pending close no longer closes it
      this.closing = false;
      this.dialog.classList.remove('is-closing');
    }
    this.input.value = seed;
    this.items = [];
    this.active = 0;
    seed ? this.search() : this.renderHome();

    if (!reopening) this.dialog.showModal();
    this.trigger.classList.add('is-launching');
    this.input.focus();
    const end = this.input.value.length;
    this.input.setSelectionRange(end, end);
    this.morph(true);
  };

  close = () => {
    if (!this.dialog.open || this.closing) return;
    this.closing = true;
    clearTimeout(this.timer);
    this.seq++; // drop any search still on its way
    this.dialog.classList.add('is-closing');
    const token = (this.closeToken = {});
    const done = () => {
      if (this.closeToken !== token) return; // reopened meanwhile
      this.dialog.close();
      this.dialog.classList.remove('is-closing');
      this.trigger.classList.remove('is-launching');
      this.closing = false;
    };
    const anim = this.morph(false);
    anim ? anim.finished.then(done, done) : done();
  };

  /**
   * Grows the window out of the top-bar field (or back into it). The panel
   * starts moved and scaled onto the field and cropped to its input row, so
   * at the first frame it *is* the field; the results fade in as it grows.
   */
  morph = opening => {
    this.panel.getAnimations().forEach(a => a.cancel());
    const rest = [this.dialog.querySelector('.flms-palette-body'), this.dialog.querySelector('.flms-palette-foot')];
    rest.forEach(el => el.getAnimations().forEach(a => a.cancel()));

    const ease = 'cubic-bezier(0.16, 1, 0.3, 1)';
    const t = this.trigger.getBoundingClientRect();
    const p = this.panel.getBoundingClientRect();
    const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Reduced motion, or the field is only an icon (phones): a short drop-in.
    if (reduce || t.width < 160 || !p.width) {
      return this.panel.animate(
        [
          { opacity: 0, transform: reduce ? 'none' : 'translateY(-10px) scale(0.98)' },
          { opacity: 1, transform: 'none' }
        ],
        { duration: reduce ? 1 : 180, easing: ease, direction: opening ? 'normal' : 'reverse', fill: 'both' }
      );
    }

    const row = this.dialog.querySelector('.flms-palette-input').offsetHeight;
    const sx = t.width / p.width;
    const sy = t.height / row;
    const frames = [
      {
        transform: `translate(${t.left - p.left}px, ${t.top - p.top}px) scale(${sx}, ${sy})`,
        clipPath: `inset(0 0 ${Math.max(0, p.height - row)}px 0 round 12px)`
      },
      { transform: 'none', clipPath: 'inset(0 0 0 0 round 14px)' }
    ];
    const timing = { duration: opening ? 340 : 240, easing: opening ? ease : 'cubic-bezier(0.4, 0, 0.6, 1)', fill: 'both' };
    const anim = this.panel.animate(opening ? frames : [...frames].reverse(), timing);

    rest.forEach(el =>
      el.animate([{ opacity: 0 }, { opacity: 1 }], {
        duration: opening ? 220 : 120,
        delay: opening ? 110 : 0,
        easing: 'ease-out',
        fill: 'both',
        direction: opening ? 'normal' : 'reverse'
      })
    );
    // Keep the end state clean so the dialog lays out normally afterwards.
    if (opening) anim.finished.then(() => anim.cancel(), () => {});
    return anim;
  };

  /* ----------------------------------------------------------- searching */

  search = async () => {
    const q = this.input.value.trim();
    if (!q) return this.renderHome();

    const commands = this.matchCommands(q);
    if (q.length < 2 && !/^\d+$/.test(q)) {
      return this.render([{ label: 'Commands', items: commands }], q, 'Keep typing to search courses, modules and lessons.');
    }

    const mine = ++this.seq;
    this.spinner.hidden = false;
    let res;
    try {
      res = await JSUtils.fetch(__futurelms.ajax_url, { action: 'search_catalog', q });
    } finally {
      if (mine === this.seq) this.spinner.hidden = true;
    }
    if (mine !== this.seq || !this.dialog.open) return; // a newer search, or closed

    if (!res?.success) {
      return this.render([{ label: 'Commands', items: commands }], q, res?.data?.message || 'Search failed. Try again.');
    }

    const { results, truncated, video_id } = res.data;
    const by = (match, type) => results.filter(r => r.match === match && (!type || r.type === type));
    const groups = [
      { label: 'Exact ID', items: by('id') },
      { label: `Lessons playing video ${video_id}`, items: by('video') },
      { label: 'Courses', items: by('title', 'course') },
      { label: 'Modules', items: by('title', 'module') },
      { label: 'Lessons', items: by('title', 'lesson') },
      { label: 'Commands', items: commands }
    ];
    this.render(groups, q, null, truncated);
  };

  matchCommands = q => this.commands().filter(c => c.title.toLowerCase().includes(q.toLowerCase()));

  renderHome() {
    this.seq++;
    this.spinner.hidden = true;
    this.render([{ label: 'Jump to', items: this.commands() }], '', null);
  }

  /* ------------------------------------------------------------ rendering */

  render(groups, q, note, truncated = false) {
    const esc = COMMON.esc;
    const mark = text => {
      if (!q) return esc(text);
      const at = text.toLowerCase().indexOf(q.toLowerCase());
      return at < 0 ? esc(text) : `${esc(text.slice(0, at))}<mark>${esc(text.slice(at, at + q.length))}</mark>${esc(text.slice(at + q.length))}`;
    };
    const typeIcon = { course: COMMON.icon('book-open'), module: COMMON.icon('folder'), lesson: COMMON.icon('file-text') };

    this.items = [];
    let html = '';
    for (const group of groups) {
      if (!group.items.length) continue;
      html += `<li class="flms-palette-group" role="presentation">${esc(group.label)}</li>`;
      for (const item of group.items) {
        const i = this.items.push(item) - 1;
        if (item.run) {
          html += `<li class="flms-palette-item" role="option" id="flms-pi-${i}" data-index="${i}" data-type="command">
            <span class="flms-palette-icon">${COMMON.icon(item.icon)}</span>
            <span class="flms-palette-text">
              <span class="flms-palette-title block">${mark(item.title)}</span>
              <span class="flms-palette-sub block">${esc(item.sub)}</span>
            </span>
            <span class="flms-palette-meta"><span class="flms-palette-hint"><kbd class="flms-kbd">↵</kbd>Open</span></span>
          </li>`;
          continue;
        }
        const where =
          item.type === 'lesson' ? `${esc(item.course_name)} › ${esc(item.module_name)}` : item.type === 'module' ? esc(item.course_name) : `Course #${esc(item.id)}`;
        const chip =
          item.match === 'id'
            ? `<span class="flms-badge">${COMMON.icon('hash')}${esc(item.id)}</span>`
            : item.match === 'video' && item.video_count > 1
            ? `<span class="flms-badge">${COMMON.icon('video')}${item.position} of ${item.video_count}</span>`
            : '';
        html += `<li class="flms-palette-item" role="option" id="flms-pi-${i}" data-index="${i}" data-type="${item.type}" data-id="${item.id}">
          <span class="flms-palette-icon">${typeIcon[item.type]}</span>
          <span class="flms-palette-text">
            <span class="flms-palette-title block">${item.match === 'title' ? mark(item.name) : esc(item.name)}</span>
            <span class="flms-palette-sub block">${where}</span>
          </span>
          <span class="flms-palette-meta">
            ${item.enabled ? '' : '<span class="flms-badge">Draft</span>'}
            ${chip}
            <span class="flms-palette-hint"><kbd class="flms-kbd">↵</kbd>Show<kbd class="flms-kbd ml-1"><span class="flms-mod">${this.modKey === 'metaKey' ? '⌘' : 'Ctrl '}</span>↵</kbd>Edit</span>
          </span>
        </li>`;
      }
    }

    if (!this.items.length) {
      html = `<li class="flms-palette-empty" role="presentation">${COMMON.icon('search-x')}
        <strong class="text-slate-800">Nothing matches “${esc(q)}”</strong>
        <span>Try part of a title, a course / module / lesson ID, or a Vimeo link.</span></li>`;
    } else if (note) {
      html += `<li class="px-3 py-2 text-sm text-slate-500" role="presentation">${esc(note)}</li>`;
    }
    if (truncated) html += `<li class="px-3 py-2 text-xs text-slate-500" role="presentation">Showing the first 100. Type more to narrow it down.</li>`;

    this.list.innerHTML = html;
    this.renderedQuery = q;
    this.list.dataset.query = q;
    this.setActive(Math.min(this.active, Math.max(0, this.items.length - 1)));
  }

  setActive(i, scroll = true) {
    this.active = i;
    this.list.querySelectorAll('.flms-palette-item').forEach(li => {
      const on = +li.dataset.index === i;
      li.classList.toggle('is-active', on);
      li.setAttribute('aria-selected', String(on));
      if (on && scroll) li.scrollIntoView({ block: 'nearest' });
    });
    this.input.setAttribute('aria-activedescendant', this.items.length ? `flms-pi-${i}` : '');
  }

  onKey = async e => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const n = this.items.length;
      if (n) this.setActive((this.active + (e.key === 'ArrowDown' ? 1 : -1) + n) % n);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const mode = e[this.modKey] ? 'edit' : 'show';
      // Typed faster than the search delay: search first, so Enter acts on
      // what the box says now, not on the previous results.
      if (this.input.value.trim() !== this.renderedQuery) {
        clearTimeout(this.timer);
        this.active = 0;
        await this.search();
      }
      if (this.items.length) this.run(this.active, mode);
    }
  };

  run(i, mode) {
    const item = this.items[i];
    if (!item) return;
    this.close();
    if (item.run) return item.run();
    COMMON.showTab('courses');
    this.courses.openHit(item, mode);
  }
}
