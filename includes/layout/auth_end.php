    </div>
  </div>

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
      '<div class=\"toast-header\">' +
        '<span class=\"app-flash-icon me-2\"><i class=\"bi\"></i></span>' +
        '<strong class=\"me-auto\"></strong>' +
        '<small class=\"text-muted\"></small>' +
        '<button type=\"button\" class=\"btn-close ms-2\" data-bs-dismiss=\"toast\" aria-label=\"Close\"></button>' +
      '</div>' +
      '<div class=\"toast-body\"></div>';
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

  if (Array.isArray(queue)) queue.forEach((t) => showToast(t.type, t.title, t.message));
  document.querySelectorAll('.alert').forEach((a) => {
    const cls = a.className || '';
    const type = cls.includes('alert-success') ? 'success'
      : cls.includes('alert-warning') ? 'warning'
      : cls.includes('alert-danger') ? 'danger'
      : cls.includes('alert-info') ? 'info'
      : null;
    if (!type) return;
    const msg = (a.textContent || '').replace(/\\s+/g, ' ').trim();
    if (msg) showToast(type, '', msg);
    try { a.remove(); } catch (e) {}
  });
});
</script>

</body>
</html>
