/**
 * Shared admin helpers: tabs, icons, selects, autocomplete, small utilities.
 * Replaces the Semantic UI widgets the screens used to depend on.
 */
const COMMON = {
  TABS: {
    CLASSES: 'classes',
    COURSES: 'courses',
    STUDENTS: 'students',
    MAILER: 'mailer',
    SETTINGS: 'settings'
  },

  htmlRTL: str => {
    return str.replace(
      /<([a-z][a-z0-9]*)(?:\s+(.*?)(?:style=[\"\'](.*?)[\"\'])(.*?))?>/g,
      "<$1 align='right' dir='rtl' $2 style='text-align:right; direction:rtl;$3' $4>"
    );
  },

  removeNonAsciiChars(str) {
    return str.replace(/[^\x20-\x7E]/g, '');
  },

  getTab: tab => document.querySelector(COMMON.getTabSelector(tab)),

  getTabSelector: tab => `.flms-panel[data-tab='${tab}']`,

  esc: value =>
    String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]),

  /** Markup for a Lucide icon; turned into an <svg> as soon as it is in the page. */
  icon: (name, cls = '') => `<i data-lucide="${name}"${cls ? ` class="${cls}"` : ''} aria-hidden="true"></i>`,

  /** The Vimeo id in a bare id or a Vimeo link, or '' (mirrors FutureLMS::vimeo_id). */
  vimeoId: value => {
    const v = String(value ?? '').trim();
    if (/^\d+$/.test(v)) return v;
    const m = v.match(/vimeo\.com\/(?:[^?#]*\/)?(\d+)/i);
    return m ? m[1] : '';
  },

  /* ---------------------------------------------------------------- icons */

  renderIcons(root = document) {
    const set = window.FLMS_ICONS || {};
    root.querySelectorAll('i[data-lucide]:not([data-missing])').forEach(el => {
      const name = el.getAttribute('data-lucide');
      const node = set[name];
      if (!node) {
        el.setAttribute('data-missing', '');
        COMMON._warned = COMMON._warned || new Set();
        if (!COMMON._warned.has(name)) {
          COMMON._warned.add(name);
          console.warn(`[future-lms] icon "${name}" is not in icons.js - run npm run build in assets-src`);
        }
        return;
      }
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      const attrs = {
        xmlns: 'http://www.w3.org/2000/svg',
        width: 24,
        height: 24,
        viewBox: '0 0 24 24',
        fill: 'none',
        stroke: 'currentColor',
        'stroke-width': 2,
        'stroke-linecap': 'round',
        'stroke-linejoin': 'round',
        'aria-hidden': 'true'
      };
      Object.entries(attrs).forEach(([k, v]) => svg.setAttribute(k, v));
      svg.setAttribute('class', `lucide lucide-${name} ${el.getAttribute('class') || ''}`.trim());
      node.forEach(([tag, a]) => {
        const child = document.createElementNS('http://www.w3.org/2000/svg', tag);
        Object.entries(a).forEach(([k, v]) => child.setAttribute(k, v));
        svg.appendChild(child);
      });
      el.replaceWith(svg);
    });
  },

  /** Renders icons in anything added to the page later (re-renders, slide-outs, dialogs). */
  watchIcons() {
    // Mutation callbacks are already batched per task; drawing here (not on
    // the next animation frame) also works while the tab is in the background.
    new MutationObserver(records => {
      if (records.some(r => r.addedNodes.length)) COMMON.renderIcons();
    }).observe(document.body, { childList: true, subtree: true });
    COMMON.renderIcons();
  },

  /* ----------------------------------------------------------------- tabs */

  initTabs(onChange) {
    const buttons = [...document.querySelectorAll('#flms-app .flms-tab[data-tab]')];
    const show = (name, push = true) => {
      if (!buttons.some(b => b.dataset.tab === name)) name = buttons[0].dataset.tab;
      buttons.forEach(b => {
        const on = b.dataset.tab === name;
        b.setAttribute('aria-selected', on ? 'true' : 'false');
        b.tabIndex = on ? 0 : -1;
      });
      document.querySelectorAll('#flms-app .flms-panel').forEach(p => {
        p.hidden = p.dataset.tab !== name;
      });
      if (push) history.replaceState(null, '', `#${name}`);
      onChange?.(name);
    };
    buttons.forEach((b, i) => {
      b.addEventListener('click', () => show(b.dataset.tab));
      b.addEventListener('keydown', e => {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        const next = buttons[(i + (e.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length];
        next.focus();
        show(next.dataset.tab);
      });
    });
    COMMON.showTab = name => show(name);
    show(location.hash.replace('#', ''), false);
  },

  /* -------------------------------------------------------------- selects */

  /**
   * Fills a native <select> and wires it. The chosen value is mirrored to the
   * element's data-id (the screens read it from there). Returns a small API.
   * values: [{ name, value }]
   */
  select(el, values, onChange = () => {}, placeholder = '') {
    if (typeof el === 'string') el = document.querySelector(el);
    if (!el) return null;
    if (el._flmsSelect) el.removeEventListener('change', el._flmsSelect.handler);

    const fill = list => {
      el.innerHTML =
        (placeholder ? `<option value="">${COMMON.esc(placeholder)}</option>` : '') +
        list.map(v => `<option value="${COMMON.esc(v.value)}">${COMMON.esc(v.name)}</option>`).join('');
      el.value = '';
      el.setAttribute('data-id', '');
    };
    fill(values || []);

    const handler = () => {
      el.setAttribute('data-id', el.value);
      onChange(el.value);
    };
    el.addEventListener('change', handler);

    const api = {
      handler,
      value: () => el.value,
      set(value, fire = true) {
        el.value = value ?? '';
        el.setAttribute('data-id', el.value);
        if (fire) onChange(el.value);
      },
      clear(fire = false) {
        api.set('', fire);
      },
      setValues(list) {
        fill(list || []);
      },
      disable(on = true) {
        el.disabled = on;
      }
    };
    el._flmsSelect = api;
    return api;
  },

  /** Back-compat name for select(). */
  wireDropdown(selector, values, onChange, placeholder = '') {
    return COMMON.select(typeof selector === 'string' ? selector : selector?.jquery ? selector[0] : selector, values, onChange, placeholder);
  },

  /* ------------------------------------------------------------ dropdowns */

  /**
   * Turns a native <select> into a searchable dropdown. The <select> stays in
   * the page (hidden) as the source of truth: the screens and the slide-out
   * editors keep reading and setting it as before, and every choice made here
   * is written back to it with a real `change` event.
   */
  dropdown(select) {
    if (!select || select._flmsDD) return select?._flmsDD;
    const esc = COMMON.esc;
    const uid = `flms-dd-${Math.random().toString(36).slice(2, 9)}`;

    const wrap = document.createElement('div');
    wrap.className = 'flms-dd';
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    select.classList.add('flms-dd-native');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');

    const label = select.id ? document.querySelector(`label[for='${select.id}']`) : null;
    wrap.insertAdjacentHTML(
      'beforeend',
      `<button type="button" class="flms-dd-trigger" role="combobox" aria-haspopup="listbox" aria-expanded="false" aria-controls="${uid}-list">
        <span class="flms-dd-value"></span>
        <span class="flms-dd-clear" role="button" tabindex="-1" aria-label="Clear" hidden>${COMMON.icon('x')}</span>
        ${COMMON.icon('chevrons-up-down', 'flms-dd-caret')}
      </button>
      <div class="flms-dd-panel" hidden>
        <div class="flms-dd-search">
          ${COMMON.icon('search')}
          <input type="text" autocomplete="off" spellcheck="false" placeholder="Search…" aria-controls="${uid}-list" aria-autocomplete="list" />
        </div>
        <ul class="flms-dd-list" id="${uid}-list" role="listbox"></ul>
      </div>`
    );
    const trigger = wrap.querySelector('.flms-dd-trigger');
    const valueEl = wrap.querySelector('.flms-dd-value');
    const clearEl = wrap.querySelector('.flms-dd-clear');
    const panel = wrap.querySelector('.flms-dd-panel');
    const search = wrap.querySelector('.flms-dd-search input');
    const list = wrap.querySelector('.flms-dd-list');
    if (label) {
      label.id = label.id || `${uid}-label`;
      trigger.setAttribute('aria-labelledby', label.id);
      label.addEventListener('click', e => {
        e.preventDefault();
        trigger.focus();
      });
    } else if (select.getAttribute('aria-label')) {
      trigger.setAttribute('aria-label', select.getAttribute('aria-label'));
    }

    let items = [];
    let active = -1;

    const options = () =>
      [...select.options].map((o, i) => ({ index: i, value: o.value, text: o.textContent.trim(), disabled: o.disabled }));
    const placeholderOption = () => [...select.options].find(o => o.value === '');

    const sync = () => {
      const opt = select.selectedOptions[0];
      const empty = !opt || opt.value === '';
      valueEl.textContent = opt ? opt.textContent.trim() : '';
      valueEl.classList.toggle('is-placeholder', empty);
      trigger.disabled = select.disabled;
      wrap.classList.toggle('is-disabled', select.disabled);
      // Clearable when there is an empty choice to go back to.
      clearEl.hidden = empty || !placeholderOption() || select.disabled;
      if (!panel.hidden) paint();
    };

    const highlight = (text, q) => {
      if (!q) return esc(text);
      const at = text.toLowerCase().indexOf(q.toLowerCase());
      if (at < 0) return esc(text);
      return `${esc(text.slice(0, at))}<mark>${esc(text.slice(at, at + q.length))}</mark>${esc(text.slice(at + q.length))}`;
    };

    const paint = () => {
      const q = search.value.trim();
      items = options().filter(o => !q || o.text.toLowerCase().includes(q.toLowerCase()));
      if (active >= items.length) active = items.length - 1;
      list.innerHTML = items.length
        ? items
            .map((o, i) => {
              const selected = o.index === select.selectedIndex;
              return `<li role="option" id="${uid}-${i}" data-i="${i}" aria-selected="${selected}"
                class="${i === active ? 'is-active' : ''} ${o.value === '' ? 'is-placeholder' : ''} ${o.disabled ? 'is-disabled' : ''}">
                <span class="flms-dd-text">${highlight(o.text, q)}</span>${selected ? COMMON.icon('check') : ''}
              </li>`;
            })
            .join('')
        : `<li class="flms-dd-empty">No matches for “${esc(q)}”</li>`;
      search.setAttribute('aria-activedescendant', active >= 0 ? `${uid}-${active}` : '');
      COMMON.renderIcons(list);
      place();
      list.querySelector('.is-active')?.scrollIntoView({ block: 'nearest' });
    };

    // Fixed positioning escapes cards and scrolling panels. Inside a slide-out
    // (a transformed ancestor) "fixed" is relative to that panel instead.
    const place = () => {
      if (panel.hidden) return;
      const r = trigger.getBoundingClientRect();
      const box = wrap.closest('.slideout-panel')?.getBoundingClientRect() || { left: 0, top: 0 };
      const below = window.innerHeight - r.bottom - 12;
      const above = r.top - 12;
      // At least 280px wide, but never past the window's edge.
      const width = Math.min(Math.max(r.width, 280), window.innerWidth - 16);
      const left = Math.max(8, Math.min(r.left, window.innerWidth - width - 8));
      panel.style.left = `${left - box.left}px`;
      panel.style.width = `${width}px`;
      panel.style.maxHeight = '';
      const natural = Math.min(panel.scrollHeight, 340);
      const up = natural > below && above > below;
      const height = Math.min(natural, up ? above : below);
      panel.style.maxHeight = `${height}px`;
      panel.style.top = `${(up ? r.top - 6 - height : r.bottom + 6) - box.top}px`;
      panel.classList.toggle('is-up', up);
    };

    const open = () => {
      if (select.disabled || !panel.hidden) return;
      COMMON._openDD?.close();
      COMMON._openDD = api;
      search.value = '';
      // A search box only earns its place on longer lists.
      wrap.querySelector('.flms-dd-search').hidden = select.options.length < 7;
      active = Math.max(0, options().findIndex(o => o.index === select.selectedIndex));
      panel.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      wrap.classList.add('is-open');
      paint();
      (wrap.querySelector('.flms-dd-search').hidden ? trigger : search).focus();
      window.addEventListener('scroll', onScroll, true);
      window.addEventListener('resize', close);
    };

    const close = (focus = false) => {
      if (panel.hidden) return;
      panel.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      wrap.classList.remove('is-open');
      if (COMMON._openDD === api) COMMON._openDD = null;
      window.removeEventListener('scroll', onScroll, true);
      window.removeEventListener('resize', close);
      if (focus) trigger.focus();
    };

    const onScroll = e => {
      if (!panel.contains(e.target)) close();
    };

    const choose = i => {
      const o = items[i];
      if (!o || o.disabled) return;
      const changed = select.selectedIndex !== o.index;
      select.selectedIndex = o.index;
      close(true);
      sync();
      if (changed) select.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const move = step => {
      if (!items.length) return;
      active = (active + step + items.length) % items.length;
      paint();
    };

    const keys = e => {
      if (panel.hidden) {
        if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) {
          e.preventDefault();
          open();
        }
        return;
      }
      switch (e.key) {
        case 'ArrowDown': e.preventDefault(); move(1); break;
        case 'ArrowUp': e.preventDefault(); move(-1); break;
        case 'Home': if (e.target !== search) { e.preventDefault(); active = 0; paint(); } break;
        case 'End': if (e.target !== search) { e.preventDefault(); active = items.length - 1; paint(); } break;
        case 'Enter': e.preventDefault(); choose(active); break;
        case 'Escape':
          // Close only the dropdown - not the slide-out or dialog around it.
          e.preventDefault();
          e.stopPropagation();
          close(true);
          break;
        case 'Tab': close(); break;
        default:
          // Typing on the trigger of a short list jumps to the first match.
          if (e.target === trigger && e.key.length === 1) {
            const i = items.findIndex(o => o.text.toLowerCase().startsWith(e.key.toLowerCase()));
            if (i >= 0) { active = i; paint(); }
          }
      }
    };

    trigger.addEventListener('click', e => {
      if (e.target.closest('.flms-dd-clear')) {
        e.preventDefault();
        const ph = placeholderOption();
        if (ph && select.value !== '') {
          select.value = '';
          sync();
          select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        return;
      }
      panel.hidden ? open() : close(true);
    });
    trigger.addEventListener('keydown', keys);
    search.addEventListener('keydown', keys);
    search.addEventListener('input', () => {
      active = 0;
      paint();
    });
    list.addEventListener('mousedown', e => e.preventDefault()); // keep focus in the search box
    list.addEventListener('click', e => {
      const li = e.target.closest('li[data-i]');
      if (li) choose(parseInt(li.dataset.i, 10));
    });
    list.addEventListener('mousemove', e => {
      const li = e.target.closest('li[data-i]');
      if (li && parseInt(li.dataset.i, 10) !== active) {
        active = parseInt(li.dataset.i, 10);
        list.querySelectorAll('li').forEach(x => x.classList.toggle('is-active', x === li));
      }
    });
    document.addEventListener('mousedown', e => {
      if (!panel.hidden && !wrap.contains(e.target)) close();
    });

    // Keep the face in step with the <select>, however it is changed: options
    // replaced, disabled toggled, a value set from code, or a native change.
    new MutationObserver(sync).observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled'] });
    select.addEventListener('change', sync);
    const proto = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
    const protoIndex = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'selectedIndex');
    Object.defineProperty(select, 'value', {
      configurable: true,
      get() { return proto.get.call(this); },
      set(v) { proto.set.call(this, v); sync(); }
    });
    Object.defineProperty(select, 'selectedIndex', {
      configurable: true,
      get() { return protoIndex.get.call(this); },
      set(v) { protoIndex.set.call(this, v); sync(); }
    });
    const protoDisabled = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'disabled');
    Object.defineProperty(select, 'disabled', {
      configurable: true,
      get() { return protoDisabled.get.call(this); },
      set(v) { protoDisabled.set.call(this, v); sync(); }
    });

    const api = { open, close, sync, wrap };
    select._flmsDD = api;
    sync();
    return api;
  },

  /** Upgrades every select in the app and in slide-out editors, now and later. */
  watchDropdowns() {
    const upgrade = root =>
      root
        .querySelectorAll?.('#flms-app select.flms-select, .slideout-panel select')
        .forEach(s => COMMON.dropdown(s));
    new MutationObserver(records => {
      if (records.some(r => r.addedNodes.length)) upgrade(document);
    }).observe(document.body, { childList: true, subtree: true });
    upgrade(document);
  },

  /* --------------------------------------------------------- autocomplete */

  /**
   * Search-as-you-type over an admin-ajax action that answers
   * { results: [{ id, title, description? }] }. The wrapper (.flms-combo)
   * carries data-id of the chosen item.
   */
  autocomplete(wrapper, { action, extra = () => ({}), minChars = 2, onSelect = () => {} }) {
    const input = wrapper.querySelector('input');
    let list = wrapper.querySelector('.flms-combo-list');
    if (!list) {
      list = document.createElement('ul');
      list.className = 'flms-combo-list';
      list.setAttribute('role', 'listbox');
      list.hidden = true;
      wrapper.appendChild(list);
    }
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('autocomplete', 'off');

    let items = [];
    let active = -1;
    let timer = null;
    let seq = 0;

    const close = () => {
      list.hidden = true;
      active = -1;
      input.setAttribute('aria-expanded', 'false');
    };
    const paint = () => {
      list.innerHTML = items.length
        ? items
            .map(
              (it, i) => `<li role="option" data-index="${i}" aria-selected="${i === active}">
                <div class="font-medium flms-bidi">${COMMON.esc(it.title)}</div>
                ${it.description ? `<div class="text-xs text-slate-500">${COMMON.esc(it.description)}</div>` : ''}
              </li>`
            )
            .join('')
        : `<li class="is-empty">No matches</li>`;
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    };
    const choose = i => {
      const it = items[i];
      if (!it) return;
      wrapper.setAttribute('data-id', it.id);
      input.value = it.title;
      close();
      onSelect(it);
    };

    input.addEventListener('input', () => {
      wrapper.removeAttribute('data-id');
      clearTimeout(timer);
      const q = input.value.trim();
      if (q.length < minChars) return close();
      timer = setTimeout(async () => {
        const mine = ++seq;
        const res = await JSUtils.fetch(__futurelms.ajax_url, { action, search: q, ...extra() });
        if (mine !== seq) return; // a newer search is on its way
        items = res?.results || [];
        active = items.length ? 0 : -1;
        paint();
      }, 250);
    });
    input.addEventListener('keydown', e => {
      if (list.hidden) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % Math.max(items.length, 1);
        paint();
      } else if (e.key === 'Enter') {
        e.preventDefault();
        choose(active);
      } else if (e.key === 'Escape') {
        close();
      }
    });
    list.addEventListener('mousedown', e => {
      const li = e.target.closest('li[data-index]');
      if (li) {
        e.preventDefault();
        choose(parseInt(li.dataset.index, 10));
      }
    });
    input.addEventListener('blur', () => setTimeout(close, 120));

    return {
      reset() {
        input.value = '';
        wrapper.removeAttribute('data-id');
        items = [];
        close();
      }
    };
  }
};
