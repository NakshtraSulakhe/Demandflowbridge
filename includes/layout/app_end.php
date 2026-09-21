    </main>
    <footer class="app-footer">
      <div class="d-flex justify-content-between align-items-center">
        <div>© <?php echo date('Y'); ?> Taraj Global Solutions. All rights reserved.</div>
        <div class="small">
          <a class="text-decoration-none" href="https://tarajglobal.com/" target="_blank" rel="noopener">Taraj Global Solutions.</a>
        </div>
      </div>
    </footer>
  </div>
</div>

<?php
  $toastUserId = (int)($layoutUser['id'] ?? 0);
  $toastRows = $toastUserId > 0 ? getUnreadToastNotifications($toastUserId, 3) : [];
?>
<?php if (!empty($toastRows)): ?>
  <div class="toast-container position-fixed top-50 start-50 translate-middle p-3" style="z-index:1080; width: 420px; max-width: calc(100vw - 24px);">
    <?php foreach ($toastRows as $t): ?>
      <?php
        $tid = (int)($t['id'] ?? 0);
        $title = (string)($t['title'] ?? '');
        $body = (string)($t['body'] ?? '');
        $link = (string)($t['link_url'] ?? '');
        $importance = strtolower(trim((string)($t['importance'] ?? 'normal')));
        if (!in_array($importance, ['low','normal','high'], true)) $importance = 'normal';
        $createdAt = (string)($t['created_at'] ?? '');
        $when = $createdAt ? date('d M, H:i', strtotime($createdAt)) : '';
        $l = strtolower($link);
        $tt = strtolower($title);
        $icon = 'bi-bell-fill';
        $iconCls = 'text-secondary';
        if (str_contains($l, 'chat') || str_contains($tt, 'chat')) { $icon = 'bi-chat-dots-fill'; $iconCls = 'text-primary'; }
        elseif (str_contains($l, 'qa') || str_contains($tt, 'qa')) { $icon = 'bi-check2-circle'; $iconCls = 'text-info'; }
        elseif (str_contains($l, 'invoice') || str_contains($l, 'revenue') || str_contains($tt, 'invoice')) { $icon = 'bi-receipt'; $iconCls = 'text-success'; }
        elseif (str_contains($l, 'attendance') || str_contains($l, 'payroll') || str_contains($tt, 'attendance')) { $icon = 'bi-calendar-check'; $iconCls = 'text-warning'; }
        elseif (str_contains($l, 'campaign') || str_contains($tt, 'campaign')) { $icon = 'bi-megaphone'; $iconCls = 'text-primary'; }
      ?>
      <div class="toast border-0 shadow-sm mb-2 df-notif-toast df-notif-<?php echo htmlspecialchars($importance); ?>" role="alert" aria-live="assertive" aria-atomic="true" data-notif-toast data-notif-id="<?php echo $tid; ?>" data-notif-importance="<?php echo htmlspecialchars($importance); ?>">
        <div class="toast-header">
          <i class="bi <?php echo htmlspecialchars($icon); ?> <?php echo htmlspecialchars($iconCls); ?> me-2"></i>
          <strong class="me-auto"><?php echo htmlspecialchars($title); ?></strong>
          <small class="text-muted"><?php echo htmlspecialchars($when); ?></small>
          <button type="button" class="btn-close ms-2" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        <div class="toast-body">
          <?php if ($body !== ''): ?>
            <div class="mb-2"><?php echo htmlspecialchars($body); ?></div>
          <?php endif; ?>
          <?php if ($link !== ''): ?>
            <a class="btn btn-sm btn-primary" href="<?php echo htmlspecialchars($link); ?>">Open</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php $layoutAssetsBase = isset($layoutAssetsBase) ? (string)$layoutAssetsBase : (appBasePath() . '/assets'); ?>
<script src="<?php echo htmlspecialchars($layoutAssetsBase); ?>/js/metis.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (typeof bootstrap === 'undefined' || !bootstrap.Tooltip) return;
  const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
  tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
  });

  const csrf = <?php echo json_encode((string)($_SESSION['csrf_token'] ?? '')); ?>;
  const basePath = <?php echo json_encode(appBasePath()); ?>;
  const logoutUrl = basePath + '/modules/auth/logout?reason=idle';
  const pingUrl = basePath + '/modules/auth/logout?ping=1';
  const idleMs = 15 * 60 * 1000;
  const idlePauseMs = 60 * 1000;
  const activityKey = 'crm_last_user_activity_ts_v1';
  const forceKey = 'crm_force_logout_v1';
  let lastActivity = (() => {
    try {
      const v = parseInt(localStorage.getItem(activityKey) || '0', 10);
      if (Number.isFinite(v) && v > 0) return v;
    } catch (e) {}
    return Date.now();
  })();
  function setLastActivity(ts) {
    lastActivity = ts;
    try { localStorage.setItem(activityKey, String(ts)); } catch (e) {}
  }
  let lastActWrite = 0;
  function touchActivity() {
    const now = Date.now();
    if (now - lastActWrite < 900) return;
    lastActWrite = now;
    setLastActivity(now);
  }
  ['mousemove','keydown','click','scroll','touchstart'].forEach((evt) => {
    document.addEventListener(evt, touchActivity, { capture: true, passive: true });
  });
  window.addEventListener('storage', (e) => {
    try {
      if (e.key === activityKey) {
        const v = parseInt(e.newValue || '0', 10);
        if (Number.isFinite(v) && v > lastActivity) lastActivity = v;
      }
      if (e.key === forceKey && e.newValue) {
        window.location.href = logoutUrl;
      }
    } catch (err) {}
  });
  function forceLogout() {
    try { localStorage.setItem(forceKey, String(Date.now())); } catch (e) {}
    window.location.href = logoutUrl;
  }
  window.setInterval(() => {
    const now = Date.now();
    if ((now - lastActivity) >= idleMs) forceLogout();
  }, 2000);
  let lastPingAt = 0;
  window.setInterval(() => {
    const now = Date.now();
    if (!csrf) return;
    if ((now - lastActivity) > idlePauseMs) return;
    if ((now - lastPingAt) < 60000) return;
    lastPingAt = now;
    fetch(pingUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'X-CRM-User-Active': '1', 'Accept': 'application/json' },
      body: 'csrf_token=' + encodeURIComponent(csrf)
    }).catch(function(){});
  }, 30000);
  const notifMarkReadUrl = <?php echo json_encode(appBasePath() . '/modules/notifications/mark-read-ajax.php'); ?>;
  const notifPollUrl = <?php echo json_encode(appBasePath() . '/modules/notifications/poll.php'); ?>;
  const shown = new Set();
  let sinceId = 0;
  let container = document.querySelector('.toast-container');
  if (!container) {
    container = document.createElement('div');
    container.className = 'toast-container position-fixed top-50 start-50 translate-middle p-3';
    container.style.zIndex = '1080';
    container.style.width = '420px';
    container.style.maxWidth = 'calc(100vw - 24px)';
    document.body.appendChild(container);
  }

  let backdrop = document.getElementById('notifToastBackdrop');
  if (!backdrop) {
    backdrop = document.createElement('div');
    backdrop.id = 'notifToastBackdrop';
    backdrop.className = 'notif-toast-backdrop';
    backdrop.setAttribute('aria-hidden', 'true');
    document.body.appendChild(backdrop);
  }

  function updateBackdrop() {
    const hasAny = container && container.querySelector('.toast.show');
    backdrop.classList.toggle('show', !!hasAny);
  }

  const bellBtn = document.querySelector('[data-notif-bell]');
  function getBadgeEl() {
    if (!bellBtn) return null;
    return bellBtn.querySelector('[data-notif-badge]');
  }
  function getBadgeCount() {
    const el = getBadgeEl();
    const n = el ? parseInt(el.textContent || '0', 10) : 0;
    return isNaN(n) ? 0 : n;
  }
  function setBadgeCount(n) {
    if (!bellBtn) return;
    n = Math.max(0, Math.min(99, parseInt(String(n), 10) || 0));
    let el = getBadgeEl();
    if (n <= 0) {
      if (el) el.remove();
      return;
    }
    if (!el) {
      el = document.createElement('span');
      el.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
      el.setAttribute('data-notif-badge', '');
      bellBtn.appendChild(el);
    }
    el.textContent = String(n);
  }
  function bumpBadge(delta) {
    const cur = getBadgeCount();
    setBadgeCount(cur + (parseInt(String(delta), 10) || 0));
  }

  const seenKey = 'notif_toast_seen_v1';
  function getSeenMap() {
    try {
      const raw = localStorage.getItem(seenKey);
      const parsed = raw ? JSON.parse(raw) : null;
      if (!parsed || typeof parsed !== 'object') return {};
      return parsed;
    } catch (e) {
      return {};
    }
  }
  function setSeenMap(map) {
    try { localStorage.setItem(seenKey, JSON.stringify(map)); } catch (e) {}
  }
  function hasSeenToast(id) {
    const m = getSeenMap();
    return !!m[String(id)];
  }
  function markSeenToast(id) {
    const m = getSeenMap();
    m[String(id)] = Date.now();
    const keys = Object.keys(m);
    if (keys.length > 500) {
      keys.sort((a, b) => (m[a] || 0) - (m[b] || 0));
      for (let i = 0; i < keys.length - 400; i++) delete m[keys[i]];
    }
    setSeenMap(m);
  }

  function inferIcon(link, title) {
    const l = String(link || '').toLowerCase();
    const t = String(title || '').toLowerCase();
    if (l.includes('chat') || t.includes('chat')) return { icon: 'bi-chat-dots-fill', cls: 'text-primary' };
    if (l.includes('qa') || t.includes('qa')) return { icon: 'bi-check2-circle', cls: 'text-info' };
    if (l.includes('invoice') || l.includes('revenue') || t.includes('invoice')) return { icon: 'bi-receipt', cls: 'text-success' };
    if (l.includes('attendance') || l.includes('payroll') || t.includes('attendance')) return { icon: 'bi-calendar-check', cls: 'text-warning' };
    if (l.includes('campaign') || t.includes('campaign')) return { icon: 'bi-megaphone', cls: 'text-primary' };
    return { icon: 'bi-bell-fill', cls: 'text-secondary' };
  }

  function markRead(id) {
    if (!id || !csrf) return;
    fetch(notifMarkReadUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      body: 'csrf_token=' + encodeURIComponent(csrf) + '&id=' + encodeURIComponent(id)
    }).catch(function(){});
  }

  function markReadAndUpdate(id) {
    if (!id) return;
    markRead(id);
    bumpBadge(-1);
  }

  function renderToast(row) {
    const id = parseInt(row.id || 0, 10);
    if (!id || shown.has(id)) return;
    shown.add(id);
    if (id > sinceId) sinceId = id;
    if (hasSeenToast(id)) return;
    const title = String(row.title || '');
    const body = String(row.body || '');
    const link = String(row.link_url || '');
    const importance = String(row.importance || 'normal').toLowerCase();
    const when = row.created_at ? String(row.created_at).slice(0, 16).replace('T', ' ') : '';
    const ic = inferIcon(link, title);

    const el = document.createElement('div');
    const imp = (importance === 'high' || importance === 'low') ? importance : 'normal';
    el.className = 'toast border-0 shadow-sm mb-2 df-notif-toast df-notif-' + imp;
    el.setAttribute('role', 'alert');
    el.setAttribute('aria-live', 'assertive');
    el.setAttribute('aria-atomic', 'true');
    el.setAttribute('data-notif-id', String(id));
    el.setAttribute('data-notif-importance', imp);
    el.innerHTML =
      '<div class="toast-header">' +
        '<i class="bi me-2"></i>' +
        '<strong class="me-auto"></strong>' +
        '<small class="text-muted"></small>' +
        '<button type="button" class="btn-close ms-2" data-bs-dismiss="toast" aria-label="Close"></button>' +
      '</div>' +
      '<div class="toast-body"></div>';
    const iconEl = el.querySelector('i');
    const impCls = imp === 'high' ? 'text-danger' : (imp === 'low' ? 'text-secondary' : ic.cls);
    iconEl.className = 'bi ' + ic.icon + ' ' + impCls + ' me-2';
    el.querySelector('strong').textContent = title;
    el.querySelector('small').textContent = when;
    const bodyEl = el.querySelector('.toast-body');
    if (body) {
      const p = document.createElement('div');
      p.className = 'mb-2';
      p.textContent = body;
      bodyEl.appendChild(p);
    }
    if (link) {
      const a = document.createElement('a');
      a.className = 'btn btn-sm btn-primary';
      a.href = link;
      a.textContent = 'Open';
      bodyEl.appendChild(a);
    }
    container.appendChild(el);
    const toast = new bootstrap.Toast(el, { autohide: false });
    el.addEventListener('shown.bs.toast', function () {
      updateBackdrop();
      const nid = parseInt(el.getAttribute('data-notif-id') || '0', 10);
      if (nid && !el.getAttribute('data-notif-marked')) {
        el.setAttribute('data-notif-marked', '1');
        markReadAndUpdate(nid);
      }
    });
    el.addEventListener('hidden.bs.toast', function () {
      const nid = parseInt(el.getAttribute('data-notif-id') || '0', 10);
      if (nid && !el.getAttribute('data-notif-marked')) {
        el.setAttribute('data-notif-marked', '1');
        markReadAndUpdate(nid);
      }
      el.remove();
      updateBackdrop();
    });
    toast.show();
    markSeenToast(id);
    updateBackdrop();
    bumpBadge(1);
  }

  document.querySelectorAll('[data-notif-toast]').forEach(function(el) {
    const id = parseInt(el.getAttribute('data-notif-id') || '0', 10);
    if (id) {
      shown.add(id);
      if (id > sinceId) sinceId = id;
      markSeenToast(id);
    }
    const toast = new bootstrap.Toast(el, { autohide: false });
    el.addEventListener('shown.bs.toast', function () {
      updateBackdrop();
      const nid = parseInt(el.getAttribute('data-notif-id') || '0', 10);
      if (nid && !el.getAttribute('data-notif-marked')) {
        el.setAttribute('data-notif-marked', '1');
        markReadAndUpdate(nid);
      }
    });
    el.addEventListener('hidden.bs.toast', function () {
      const nid = parseInt(el.getAttribute('data-notif-id') || '0', 10);
      if (nid && !el.getAttribute('data-notif-marked')) {
        el.setAttribute('data-notif-marked', '1');
        markReadAndUpdate(nid);
      }
      el.remove();
      updateBackdrop();
    });
    toast.show();
  });
  updateBackdrop();

  container.addEventListener('click', function (e) {
    const a = e.target && e.target.closest ? e.target.closest('a.btn') : null;
    if (!a) return;
    const t = a.closest('.toast');
    if (!t) return;
    const nid = parseInt(t.getAttribute('data-notif-id') || t.getAttribute('data-notif-id') || '0', 10);
    if (!nid || t.getAttribute('data-notif-marked')) return;
    t.setAttribute('data-notif-marked', '1');
    markReadAndUpdate(nid);
    try {
      const inst = bootstrap.Toast.getOrCreateInstance(t);
      inst.hide();
    } catch (err) {}
  });

  function poll() {
    if (!csrf) return;
    if ((Date.now() - lastActivity) > idlePauseMs) return;
    fetch(notifPollUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      body: 'csrf_token=' + encodeURIComponent(csrf) + '&since_id=' + encodeURIComponent(String(sinceId))
    })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (!data || !data.ok || !Array.isArray(data.rows)) return;
        if (typeof data.unread_count === 'number' && Number.isFinite(data.unread_count)) {
          setBadgeCount(data.unread_count);
        }
        data.rows.forEach(renderToast);
      })
      .catch(function(){});
  }

  setInterval(poll, 45000);

  // Break Countdown Timer Polling
  const breakCountdownUrl = <?php echo json_encode(appBasePath() . '/modules/hr/break-countdown-ajax'); ?>;
  const breakOverrideUrl = <?php echo json_encode(appBasePath() . '/modules/hr/break-override-ajax'); ?>;
  const breakCard = document.getElementById('break-countdown-card');
  const breakLabel = document.getElementById('break-label');
  const breakTimer = document.getElementById('break-timer');
  const breakProgress = document.getElementById('break-progress');
  const breakStatus = document.getElementById('break-status');
  const breakMeta = document.getElementById('break-meta');
  const breakOverrideControls = document.getElementById('break-override-controls');
  const breakResumeBtn = document.getElementById('break-resume-btn');
  const breakEndBtn = document.getElementById('break-end-btn');

  function updateBreakCountdown() {
    if (!breakCard || !breakLabel || !breakTimer) return;
    if ((Date.now() - lastActivity) > idlePauseMs) return;
    
    fetch(breakCountdownUrl, {
      method: 'GET',
      headers: { 'Accept': 'application/json' }
    })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (!data || !data.success) {
          breakCard.classList.add('d-none');
          if (breakOverrideControls) breakOverrideControls.classList.add('d-none');
          return;
        }
        
        if (data.break) {
          breakCard.classList.remove('d-none');
          const status = data.break.status || 'upcoming';
          const scheduledTime = data.break.scheduled_time || '--:--';
          const duration = Number(data.break.duration_minutes || 0);
          
          if (status === 'active') {
            // Currently on break
            breakLabel.textContent = data.break.label;
            breakTimer.textContent = data.break.countdown;
            if (breakStatus) {
              const startedAt = data.break.start_time ? ('Started ' + data.break.start_time) : 'On break';
              breakStatus.textContent = startedAt;
            }
            if (breakMeta) {
              breakMeta.textContent = scheduledTime + ' · ' + duration + 'm';
            }
            if (breakProgress) {
              breakProgress.style.width = (data.break.progress_percent || 0) + '%';
              const progressWrap = breakProgress.parentElement;
              if (progressWrap) progressWrap.classList.remove('d-none');
            }
            if (breakOverrideControls) breakOverrideControls.classList.remove('d-none');
          } else {
            // Upcoming break
            breakLabel.textContent = data.break.label;
            breakTimer.textContent = data.break.countdown || '--:--:--';
            if (breakStatus) breakStatus.textContent = 'Starts in';
            if (breakMeta) {
              breakMeta.textContent = scheduledTime + ' · ' + duration + 'm';
            }
            if (breakProgress) {
              breakProgress.style.width = '0%';
              const progressWrap = breakProgress.parentElement;
              if (progressWrap) progressWrap.classList.add('d-none');
            }
            if (breakOverrideControls) breakOverrideControls.classList.add('d-none');
          }
        } else {
          breakCard.classList.add('d-none');
          if (breakOverrideControls) breakOverrideControls.classList.add('d-none');
        }
      })
      .catch(function(){});
  }

  // Manual override handlers
  if (breakResumeBtn) {
    breakResumeBtn.addEventListener('click', function() {
      if (!confirm('Are you sure you want to resume working? This will end your current break.')) return;
      
      fetch(breakOverrideUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ action: 'resume_working', csrf_token: csrf })
      })
        .then(function(r){ return r.json(); })
        .then(function(data){
          if (data && data.success) {
            updateBreakCountdown();
          } else {
            alert('Failed to resume working: ' + (data?.message || 'Unknown error'));
          }
        })
        .catch(function(){ alert('An error occurred while resuming work.'); });
    });
  }

  if (breakEndBtn) {
    breakEndBtn.addEventListener('click', function() {
      if (!confirm('Are you sure you want to end this break?')) return;
      
      fetch(breakOverrideUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ action: 'end_break', csrf_token: csrf })
      })
        .then(function(r){ return r.json(); })
        .then(function(data){
          if (data && data.success) {
            updateBreakCountdown();
          } else {
            alert('Failed to end break: ' + (data?.message || 'Unknown error'));
          }
        })
        .catch(function(){ alert('An error occurred while ending break.'); });
    });
  }

  function scheduleBreakPolling(delayMs) {
    window.setTimeout(function() {
      Promise.resolve(updateBreakCountdown()).finally(function() {
        const isActiveBreak = breakOverrideControls && !breakOverrideControls.classList.contains('d-none');
        scheduleBreakPolling(isActiveBreak ? 5000 : 30000);
      });
    }, delayMs);
  }
  
  // Initial fetch
  setTimeout(updateBreakCountdown, 1000);
  scheduleBreakPolling(5000);
});
</script>

<?php
  $appFlash = $_SESSION['app_flash_toasts'] ?? [];
  if (!is_array($appFlash)) $appFlash = [];
  unset($_SESSION['app_flash_toasts']);
?>
<div id="appFlashToastContainer" class="toast-container position-fixed top-50 start-50 translate-middle p-3 app-flash-toast-container" aria-live="polite" aria-atomic="true"></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (typeof bootstrap === 'undefined' || !bootstrap.Toast) return;

  const container = document.getElementById('appFlashToastContainer');
  if (!container) return;

  const queue = <?php echo json_encode($appFlash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const normalizeType = (t) => {
    t = String(t || '').toLowerCase();
    if (t === 'success' || t === 'warning' || t === 'danger' || t === 'info') return t;
    return 'info';
  };
  const iconFor = (t) => {
    if (t === 'success') return 'bi-check-circle-fill';
    if (t === 'warning') return 'bi-exclamation-triangle-fill';
    if (t === 'danger') return 'bi-x-circle-fill';
    return 'bi-info-circle-fill';
  };
  const clsFor = (t) => {
    if (t === 'success') return 'text-success';
    if (t === 'warning') return 'text-warning';
    if (t === 'danger') return 'text-danger';
    return 'text-primary';
  };

  const showToast = (type, title, message) => {
    type = normalizeType(type);
    title = String(title || (type === 'success' ? 'Success' : type === 'warning' ? 'Warning' : type === 'danger' ? 'Error' : 'Info'));
    message = String(message || '').trim();
    if (!message) return;

    const el = document.createElement('div');
    el.className = 'toast app-flash-toast mb-2 app-flash-' + type;
    el.setAttribute('role', 'alert');
    el.setAttribute('aria-live', 'assertive');
    el.setAttribute('aria-atomic', 'true');
    el.innerHTML =
      '<div class="toast-header">' +
        '<span class="app-flash-icon me-2"><i class="bi"></i></span>' +
        '<strong class="me-auto"></strong>' +
        '<small class="text-muted"></small>' +
        '<button type="button" class="btn-close ms-2" data-bs-dismiss="toast" aria-label="Close"></button>' +
      '</div>' +
      '<div class="toast-body"></div>';
    const i = el.querySelector('.app-flash-icon i');
    i.className = 'bi ' + iconFor(type) + ' ' + clsFor(type);
    el.querySelector('strong').textContent = title;
    el.querySelector('.toast-body').textContent = message;
    container.appendChild(el);
    const toast = new bootstrap.Toast(el, { autohide: true, delay: 5000 });
    el.addEventListener('hidden.bs.toast', () => { try { el.remove(); } catch (e) {} });
    toast.show();
  };

  window.appNotify = (type, message, title) => { showToast(type, title || '', message || ''); };
  window.appNotifySuccess = (message, title) => { showToast('success', title || '', message || ''); };
  window.appNotifyError = (message, title) => { showToast('danger', title || '', message || ''); };
  window.appNotifyWarning = (message, title) => { showToast('warning', title || '', message || ''); };
  window.appNotifyInfo = (message, title) => { showToast('info', title || '', message || ''); };

  if (Array.isArray(queue)) {
    queue.forEach((t) => showToast(t.type, t.title, t.message));
  }

  document.querySelectorAll('.alert').forEach((a) => {
    const cls = a.className || '';
    const type = cls.includes('alert-success') ? 'success'
      : cls.includes('alert-warning') ? 'warning'
      : cls.includes('alert-danger') ? 'danger'
      : cls.includes('alert-info') ? 'info'
      : null;
    if (!type) return;
    const msg = (a.textContent || '').replace(/\s+/g, ' ').trim();
    if (msg) showToast(type, '', msg);
    try { a.remove(); } catch (e) {}
  });
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-recognition-rotation]').forEach(function (root) {
    const items = Array.from(root.querySelectorAll('.df-recognition-rotation-item'));
    if (items.length < 2) return;
    const intervalMs = Math.max(3000, parseInt(root.getAttribute('data-interval-ms') || '6000', 10) || 6000);
    let idx = 0;
    window.setInterval(function () {
      items[idx].classList.remove('is-active');
      idx = (idx + 1) % items.length;
      items[idx].classList.add('is-active');
    }, intervalMs);
  });
});
</script>

</body>
</html>
