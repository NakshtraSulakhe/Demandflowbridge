<?php
$layoutTitle = $pageTitle ?? 'Login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">
  <title><?php echo htmlspecialchars($layoutTitle); ?>TGS - DemandFlow Bridge</title>
  <link rel="icon" type="image/png" href="../../assets/images/logos/Only-Logo.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="../../assets/css/metis.css">
  <script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
  <style>
    body { display:flex; align-items:center; justify-content:center; min-height:100vh; }
    .auth-card { 
      width:100%; 
      max-width:900px; 
      border-radius: 2rem; 
      border: 1px solid var(--app-border); 
      background: var(--app-surface); 
      box-shadow: 0 0 0 1px rgba(0,255,255,.28), 0 12px 48px rgba(0,255,255,.35), 0 0 64px -24px rgba(0,255,255,.5); 
      overflow:hidden; 
      margin: 1rem;
    }
    @media (max-width: 768px) {
      .auth-card { border-radius: 1.5rem; margin: 0.5rem; }
      .auth-body { padding: 15px; }
      .border-start-md { border-top: 1px solid var(--app-border); border-left: 0 !important; }
    }
    @media (min-width: 768px) {
      .border-start-md { border-left: 1px solid var(--app-border) !important; }
    }
    .brand-logo { height: 36px; }
  </style>
  <script>
    window.addEventListener('pageshow', function (event) {
      const nav = window.performance && performance.getEntriesByType ? performance.getEntriesByType('navigation') : [];
      const navType = nav.length ? String(nav[0].type || '') : '';
      if (event.persisted || navType === 'back_forward') {
        window.location.reload();
      }
    });
  </script>
</head>
<body class="bg-light">
  <div class="auth-card">
    <div class="auth-body">
