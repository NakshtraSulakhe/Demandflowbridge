(() => {
  const body = document.body;
  const key = 'lms_sidebar_collapsed';
  const themeKey = 'lms_theme';

  function setCollapsed(val) {
    if (val) body.classList.add('sidebar-collapsed');
    else body.classList.remove('sidebar-collapsed');
    localStorage.setItem(key, val ? '1' : '0');
  }

  function toggleMobile() {
    body.classList.toggle('sidebar-open');
  }

  function applyTheme(theme, persist) {
    body.dataset.theme = theme;
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.setAttribute('data-bs-theme', theme);
    if (persist) localStorage.setItem(themeKey, theme);
  }

  document.addEventListener('click', (e) => {
    const t = e.target;
    const btnCollapse = t.closest('[data-sidebar-toggle]');
    if (btnCollapse) {
      if (window.matchMedia('(max-width: 992px)').matches) toggleMobile();
      else setCollapsed(!body.classList.contains('sidebar-collapsed'));
      return;
    }

    const btnTheme = t.closest('[data-theme-toggle]');
    if (btnTheme) {
      const cur = body.dataset.theme === 'dark' ? 'dark' : 'light';
      applyTheme(cur === 'dark' ? 'light' : 'dark', true);
      window.location.reload();
      return;
    }

    const notifItem = t.closest('[data-notif-item]');
    if (notifItem) {
      e.preventDefault();
      const href = (notifItem.getAttribute('href') || '').trim();
      const navigate = () => {
        if (href !== '' && href !== '#') window.location.href = href;
      };
      const id = (notifItem.getAttribute('data-notif-id') || '').trim();
      const isRead = (notifItem.getAttribute('data-notif-is-read') || '0') === '1';
      if (id !== '' && !isRead) {
        const root = notifItem.closest('[data-notif-root]');
        const url = root ? (root.getAttribute('data-notif-read-url') || '') : '';
        const csrf = root ? (root.getAttribute('data-notif-csrf') || '') : '';
        if (url !== '' && csrf !== '') {
          fetch(url, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json'
            },
            credentials: 'same-origin',
            body: new URLSearchParams({ id, csrf_token: csrf }).toString()
          }).then(async (r) => {
            const txt = await r.text();
            try { return JSON.parse(txt); } catch (e) { return null; }
          }).then((data) => {
            if (!data || !data.ok) return;
            notifItem.setAttribute('data-notif-is-read', '1');
            notifItem.classList.remove('bg-light');
            const dot = notifItem.querySelector('.badge.rounded-pill.bg-primary');
            if (dot) dot.remove();
            const badge = document.querySelector('[data-notif-badge]');
            if (badge) {
              const n = parseInt((badge.textContent || '').trim(), 10);
              if (Number.isFinite(n) && n > 1) badge.textContent = String(n - 1);
              else badge.remove();
            }
          }).catch(() => {}).finally(() => { navigate(); });
          return;
        }
      }
      navigate();
      return;
    }

    if (window.matchMedia('(max-width: 992px)').matches) {
      const sidebar = document.querySelector('.app-sidebar');
      const clickedInside = sidebar && sidebar.contains(t);
      const topbarToggle = t.closest('[data-sidebar-toggle]');
      if (!clickedInside && !topbarToggle) body.classList.remove('sidebar-open');
    }
  });

  function initClippedDropdowns() {
    if (typeof bootstrap === 'undefined' || !bootstrap.Dropdown) return;
    document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach((btn) => {
      if (!(btn instanceof HTMLElement)) return;
      const inClip = btn.closest('.input-group, .chat-search-group, .chat-container, .table-responsive, .card');
      if (!inClip) return;
      bootstrap.Dropdown.getOrCreateInstance(btn, {
        popperConfig(defaultBsPopperConfig) {
          return { ...defaultBsPopperConfig, strategy: 'fixed' };
        }
      });
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    const stored = localStorage.getItem(key);
    if (stored === '1') body.classList.add('sidebar-collapsed');

    const themeStored = localStorage.getItem(themeKey);
    const theme = themeStored === 'dark' ? 'dark' : 'light';
    applyTheme(theme, true);
    initClippedDropdowns();
  });

  document.addEventListener('shown.bs.collapse', (e) => {
    const el = e.target;
    if (!(el instanceof HTMLElement)) return;
    if (!el.classList.contains('sidebar-submenu')) return;
    const nav = el.closest('.sidebar-nav');
    if (!(nav instanceof HTMLElement)) return;
    const elRect = el.getBoundingClientRect();
    const navRect = nav.getBoundingClientRect();
    const pad = 16;
    if (elRect.bottom > navRect.bottom) nav.scrollTop += (elRect.bottom - navRect.bottom) + pad;
    else if (elRect.top < navRect.top) nav.scrollTop -= (navRect.top - elRect.top) + pad;
  });
})();
