<?php
$layoutTitle = $pageTitle ?? 'Dashboard';
$layoutUser = getCurrentUser();
$layoutPage = basename($_SERVER['PHP_SELF'] ?? '');
if ($layoutPage !== '' && stripos($layoutPage, '.php') === false) {
    $layoutPage = basename($_SERVER['SCRIPT_NAME'] ?? $layoutPage);
}
$layoutBackUrl = $layoutBackUrl ?? appBackUrl('');
$layoutBase = function_exists('appBasePath') ? appBasePath() : '';
$layoutModulesBase = $layoutBase . '/modules';
$layoutAssetsBase = $layoutBase . '/assets';
$layoutDefaultTheme = function_exists('getAppSetting') ? (string)(getAppSetting('ui.theme.default', 'light') ?? 'light') : 'light';
$layoutDefaultTheme = $layoutDefaultTheme === 'dark' ? 'dark' : 'light';
$layoutAllowUserOverride = function_exists('getAppSetting') ? ((string)(getAppSetting('ui.theme.allow_user_override', '1') ?? '1') === '1') : true;
$layoutAccentLight = function_exists('getAppSetting') ? (string)(getAppSetting('ui.theme.accent_light', '#0EA5E9') ?? '#0EA5E9') : '#0EA5E9';
$layoutAccentDark = function_exists('getAppSetting') ? (string)(getAppSetting('ui.theme.accent_dark', '#00FFFF') ?? '#00FFFF') : '#00FFFF';
$layoutNormalizeHex = function(string $v, string $fallback): string {
    $v = trim($v);
    if ($v === '') return $fallback;
    if ($v[0] !== '#') $v = '#' . $v;
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $v)) return $fallback;
    return strtoupper($v);
};
$layoutAccentLight = $layoutNormalizeHex($layoutAccentLight, '#0EA5E9');
$layoutAccentDark = $layoutNormalizeHex($layoutAccentDark, '#00FFFF');
$layoutHexToRgb = function(string $hex): array {
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
};
$layoutDarkenHex = function(string $hex, float $factor) use ($layoutHexToRgb): string {
    [$r, $g, $b] = $layoutHexToRgb($hex);
    $r = max(0, min(255, (int)round($r * $factor)));
    $g = max(0, min(255, (int)round($g * $factor)));
    $b = max(0, min(255, (int)round($b * $factor)));
    return sprintf('#%02X%02X%02X', $r, $g, $b);
};
$layoutAccentLight700 = $layoutDarkenHex($layoutAccentLight, 0.86);
$layoutAccentDark700 = $layoutDarkenHex($layoutAccentDark, 0.86);
[$lr, $lg, $lb] = $layoutHexToRgb($layoutAccentLight);
[$dr, $dg, $db] = $layoutHexToRgb($layoutAccentDark);
$layoutThemeVarsCss = ':root{' .
    '--sidebar-neon:' . $layoutAccentLight . ';' .
    '--sidebar-border:rgba(' . $lr . ',' . $lg . ',' . $lb . ',.40);' .
    '--sidebar-glow:rgba(' . $lr . ',' . $lg . ',' . $lb . ',.25);' .
    '--sidebar-hover:rgba(' . $lr . ',' . $lg . ',' . $lb . ',.10);' .
    '--sidebar-active-bg:rgba(' . $lr . ',' . $lg . ',' . $lb . ',.16);' .
    '--sidebar-active-ring:rgba(' . $lr . ',' . $lg . ',' . $lb . ',.34);' .
    '--sidebar-brand-border:rgba(' . $lr . ',' . $lg . ',' . $lb . ',.22);' .
    '--app-primary:' . $layoutAccentLight . ';' .
    '--app-primary-700:' . $layoutAccentLight700 . ';' .
    '}' .
    '[data-bs-theme="dark"]{' .
    '--sidebar-neon:' . $layoutAccentDark . ';' .
    '--sidebar-border:rgba(' . $dr . ',' . $dg . ',' . $db . ',.34);' .
    '--sidebar-glow:rgba(' . $dr . ',' . $dg . ',' . $db . ',.34);' .
    '--sidebar-hover:rgba(' . $dr . ',' . $dg . ',' . $db . ',.08);' .
    '--sidebar-active-bg:rgba(' . $dr . ',' . $dg . ',' . $db . ',.14);' .
    '--sidebar-active-ring:rgba(' . $dr . ',' . $dg . ',' . $db . ',.34);' .
    '--sidebar-brand-border:rgba(' . $dr . ',' . $dg . ',' . $db . ',.22);' .
    '--app-primary:' . $layoutAccentDark . ';' .
    '--app-primary-700:' . $layoutAccentDark700 . ';' .
    '}';
$layoutDashUrl = '#';
$permEnabled = function_exists('getAccessRolePermissionsConfig') && getAccessRolePermissionsConfig() !== null;
if ($permEnabled && function_exists('userHasPermission')) {
    if (userHasPermission('dashboard.admin')) $layoutDashUrl = $layoutModulesBase . '/dashboard/admin-dashboard';
    elseif (userHasPermission('dashboard.sales')) $layoutDashUrl = $layoutModulesBase . '/dashboard/sales-dashboard';
    elseif (userHasPermission('dashboard.operations')) $layoutDashUrl = $layoutModulesBase . '/dashboard/operations-dashboard';
    elseif (userHasPermission('dashboard.marketing')) $layoutDashUrl = $layoutModulesBase . '/dashboard/email-marketing-dashboard';
    elseif (userHasPermission('dashboard.qa')) $layoutDashUrl = $layoutModulesBase . '/qa/dashboard';
    elseif (userHasPermission('dashboard.client')) $layoutDashUrl = $layoutModulesBase . '/dashboard/client-dashboard';
    elseif (userHasPermission('dashboard.vendor')) $layoutDashUrl = $layoutModulesBase . '/dashboard/vendor-dashboard';
    elseif (userHasPermission('dbms.access')) $layoutDashUrl = $layoutModulesBase . '/dbms';
    elseif (userHasPermission('dashboard.access')) $layoutDashUrl = $layoutModulesBase . '/dashboard/admin-dashboard';
} else {
    if (isAdmin() || isDirector()) $layoutDashUrl = $layoutModulesBase . '/dashboard/admin-dashboard';
    elseif (isSales()) $layoutDashUrl = $layoutModulesBase . '/dashboard/sales-dashboard';
    elseif (isAgent()) $layoutDashUrl = $layoutModulesBase . '/dashboard/operations-dashboard';
    elseif (isFormFiller()) $layoutDashUrl = $layoutModulesBase . '/dashboard/email-marketing-dashboard';
    elseif (isQA()) $layoutDashUrl = $layoutModulesBase . '/qa/dashboard';
    elseif (function_exists('isVendor') && isVendor()) $layoutDashUrl = $layoutModulesBase . '/dashboard/vendor-dashboard';
    elseif (function_exists('isClient') && isClient()) $layoutDashUrl = $layoutModulesBase . '/dashboard/client-dashboard';
    elseif (function_exists('userHasPermission') && userHasPermission('dbms.access')) $layoutDashUrl = $layoutModulesBase . '/dbms';
}
$layoutAppCssVer = @filemtime(__DIR__ . '/../../assets/css/app.css');
$layoutAppCssVer = $layoutAppCssVer ? (string)$layoutAppCssVer : (string)time();

$layoutIsAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
$layoutAccept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
$layoutWantsJson = str_contains($layoutAccept, 'application/json');
if (!$layoutIsAjax && !$layoutWantsJson && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !headers_sent()) {
    $flashType = null;
    $flashMsg = null;
    $flashTitle = '';

    if (array_key_exists('success', $_GET)) { $flashType = 'success'; $flashMsg = (string)($_GET['success'] ?? ''); }
    elseif (array_key_exists('error', $_GET)) { $flashType = 'danger'; $flashMsg = (string)($_GET['error'] ?? ''); }
    elseif (array_key_exists('warning', $_GET)) { $flashType = 'warning'; $flashMsg = (string)($_GET['warning'] ?? ''); }
    elseif (array_key_exists('info', $_GET)) { $flashType = 'info'; $flashMsg = (string)($_GET['info'] ?? ''); }

    $fallbackMsg = trim((string)($_GET['message'] ?? $_GET['msg'] ?? ''));
    $isFlag = $flashMsg !== null && (trim($flashMsg) === '' || trim($flashMsg) === '1' || trim($flashMsg) === 'true');
    if ($flashType !== null) {
        if ($isFlag) $flashMsg = $fallbackMsg;
        $flashMsg = trim((string)$flashMsg);
        if ($flashMsg === '') {
            $flashMsg = $flashType === 'success' ? 'Action completed successfully.'
                : ($flashType === 'warning' ? 'Please review the warning.'
                : ($flashType === 'danger' ? 'Action failed.' : 'Done.'));
        }
        if (function_exists('appAddFlashToast')) appAddFlashToast($flashType, $flashMsg, $flashTitle);

        $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
        $q = $_GET;
        foreach (['success','error','warning','info','message','msg'] as $k) unset($q[$k]);
        $qs = http_build_query($q);
        $dest = $path . ($qs !== '' ? ('?' . $qs) : '');
        header('Location: ' . $dest);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($layoutTitle . ' - TGS - DemandFlow Bridge'); ?></title>
  <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($layoutAssetsBase); ?>/images/logos/Only-Logo.png">
  <script>
    (() => {
      try {
        const defTheme = <?php echo json_encode($layoutDefaultTheme); ?>;
        const allowOverride = <?php echo $layoutAllowUserOverride ? 'true' : 'false'; ?>;
        const stored = allowOverride ? localStorage.getItem('lms_theme') : null;
        const theme = (stored === 'dark' || stored === 'light') ? stored : defTheme;
        if (allowOverride && !stored) localStorage.setItem('lms_theme', theme);
        const apply = (el) => {
          if (!el) return;
          el.setAttribute('data-theme', theme);
          el.setAttribute('data-bs-theme', theme);
        };
        apply(document.documentElement);
        if (document.body) apply(document.body);
        else document.addEventListener('DOMContentLoaded', () => apply(document.body), { once: true });
      } catch (e) {}
    })();
  </script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($layoutAssetsBase); ?>/css/metis.css">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($layoutAssetsBase); ?>/css/hr.css">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($layoutAssetsBase); ?>/css/tables.css">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($layoutAssetsBase); ?>/css/app.css?v=<?php echo htmlspecialchars($layoutAppCssVer); ?>">
  <style id="app-theme-vars"><?php echo $layoutThemeVarsCss; ?></style>
  <style>
    #app-preloader{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.92);backdrop-filter:saturate(120%) blur(6px);opacity:1;visibility:visible;transition:opacity .28s ease,visibility .28s ease}
    [data-bs-theme="dark"] #app-preloader{background:rgba(10,14,22,.88)}
    #app-preloader.ap-hidden{opacity:0;visibility:hidden}
    #app-preloader .ap-wrap{position:relative;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:12px}
    #app-preloader .ap-spinner{width:min(180px,44vw);height:auto;filter:drop-shadow(0 10px 22px rgba(0,0,0,.10))}
    [data-bs-theme="dark"] #app-preloader .ap-spinner{filter:drop-shadow(0 10px 24px rgba(0,0,0,.35))}
    #app-preloader .ap-text{font-size:.85rem;letter-spacing:.04em;text-transform:uppercase;color:rgba(60,60,60,.65)}
    [data-bs-theme="dark"] #app-preloader .ap-text{color:rgba(230,235,255,.65)}
  </style>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    (() => {
      const start = performance && performance.now ? performance.now() : Date.now();
      const minMs = 260;
      const show = () => {
        const el = document.getElementById('app-preloader');
        if (!el) return;
        el.classList.remove('ap-hidden');
      };
      const hide = () => {
        const el = document.getElementById('app-preloader');
        if (!el) return;
        const now = performance && performance.now ? performance.now() : Date.now();
        const wait = Math.max(0, minMs - (now - start));
        window.setTimeout(() => {
          el.classList.add('ap-hidden');
          window.setTimeout(() => { try { el.remove(); } catch (e) {} }, 340);
        }, wait);
      };
      window.__appLoader = { show, hide };

      // Hide preloader as soon as DOM is ready to improve perceived speed
      document.addEventListener('DOMContentLoaded', hide);
      // Fallback: hide if window.load takes too long (e.g. slow assets)
      window.setTimeout(hide, 2000);

      document.addEventListener('DOMContentLoaded', () => {
        const aHandler = (e) => {
          if (e.defaultPrevented) return;
          if (e.button !== 0) return;
          if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
          const a = e.target && e.target.closest ? e.target.closest('a') : null;
          if (!a) return;
          if (a.hasAttribute('download')) return;
          const target = (a.getAttribute('target') || '').toLowerCase();
          if (target && target !== '_self') return;
          const href = a.getAttribute('href') || '';
          if (!href || href === '#' || href.startsWith('javascript:')) return;
          if (href.startsWith('mailto:') || href.startsWith('tel:')) return;
          if (a.getAttribute('data-no-loader') === '1') return;
          let url;
          try { url = new URL(href, window.location.href); } catch (err) { return; }
          if (url.origin !== window.location.origin) return;
          if (url.pathname.endsWith('.php')) {
            e.preventDefault();
            url.pathname = url.pathname.slice(0, -4);
            show();
            window.location.href = url.toString();
            return;
          }
          if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return;
          show();
        };
        document.addEventListener('click', aHandler, true);
        document.addEventListener('submit', (e) => {
          if (e.defaultPrevented) return;
          try {
            const f = e.target;
            if (f && f.tagName === 'FORM') {
              const method = (f.getAttribute('method') || 'get').toLowerCase();
              if (method === 'post' && f.getAttribute('data-no-double-submit') !== '1') {
                if (f.dataset.submitted === '1') { e.preventDefault(); return; }
                f.dataset.submitted = '1';


                try {
                  const s = e.submitter;
                  const n = s && typeof s.name === 'string' ? s.name : '';
                  if (n) {
                    let found = false;
                    f.querySelectorAll('input[type="hidden"][data-submit-proxy="1"]').forEach((x) => {
                      if (x && x.name === n) found = true;
                    });
                    if (!found) {
                      const h = document.createElement('input');
                      h.type = 'hidden';
                      h.setAttribute('data-submit-proxy', '1');
                      h.name = n;
                      const v = s && typeof s.value === 'string' ? s.value : '';
                      h.value = v !== '' ? v : '1';
                      f.appendChild(h);
                    }
                  }
                } catch (err) {}
                f.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((btn) => {
                  try { btn.disabled = true; } catch (err) {}
                });
              }
              const action = f.getAttribute('action') || '';
              if (action && !action.startsWith('#') && !action.startsWith('javascript:')) {
                const u = new URL(action, window.location.href);
                if (u.origin === window.location.origin && u.pathname.endsWith('.php')) {
                  u.pathname = u.pathname.slice(0, -4);
                  f.setAttribute('action', u.toString());
                }
              }
            }
          } catch (err) {}
          show();
        }, true);
        window.addEventListener('beforeunload', () => { show(); });
      });
      window.addEventListener('load', hide);
    })();
  </script>
</head>
<body>
<div id="app-preloader" role="status" aria-live="polite">
  <div class="ap-wrap">
    <img class="ap-spinner" src="<?php echo htmlspecialchars($layoutAssetsBase); ?>/images/logos/infinite-spinner.svg" alt="Loading">
    <div class="ap-text">Loading</div>
  </div>
</div>
<div class="app-shell">
  <aside class="app-sidebar">
    <div class="brand">
      <a href="<?php echo htmlspecialchars($layoutDashUrl); ?>" class="brand-link d-flex align-items-center gap-2">
        <span class="brand-full">
          <img class="brand-logo brand-logo-light" src="<?php echo htmlspecialchars($layoutAssetsBase); ?>/images/logos/Only_name_logo_Blue.png" alt="DemandFlow Bridge">
          <img class="brand-logo brand-logo-dark" src="<?php echo htmlspecialchars($layoutAssetsBase); ?>/images/logos/Only_Demand_white.png" alt="DemandFlow Bridge">
        </span>
        <span class="brand-mini">
          <img class="brand-logo-mini" src="<?php echo htmlspecialchars($layoutAssetsBase); ?>/images/logos/Only-Logo.png" alt="DemandFlow">
        </span>
      </a>
    </div>
    <?php include __DIR__ . '/sidebar.php'; ?>
  </aside>

  <div class="app-main">
    <header class="app-topbar">
      <button type="button" class="btn btn-light border btn-sm" data-sidebar-toggle>
        <i class="bi bi-list"></i>
      </button>
      <h1 class="topbar-title ms-2"><?php echo htmlspecialchars($layoutTitle); ?></h1>
      <?php include __DIR__ . '/topbar.php'; ?>
    </header>
    <main class="app-content">
      <?php
        $layoutRoute = function_exists('matchAccessRouteRegistry') ? matchAccessRouteRegistry() : null;
        $layoutRouteKey = strtolower((string)($layoutRoute['key'] ?? ''));
        $layoutShowRecognition = $layoutRouteKey !== '' && (str_contains($layoutRouteKey, '.dashboard.') || str_contains($layoutRouteKey, 'dashboard.'));
        if ($layoutShowRecognition && function_exists('dfDashboardAllBannersHtml')) {
            $layoutRecognition = dfDashboardAllBannersHtml((int)($layoutUser['id'] ?? 0));
            $layoutRecognitionHtml = trim((string)($layoutRecognition['html'] ?? ''));
            if ($layoutRecognitionHtml !== '') {
                echo $layoutRecognitionHtml;
            }
        }
      ?>
