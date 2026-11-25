<?php
declare(strict_types=1);

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Route API endpoints
if (str_starts_with($uriPath, '/api/')) {
    require_once __DIR__ . '/../api/router.php';
    handleApiRequest($uriPath, $method);
}

// Serve static assets when run via PHP built-in server
$publicFile = realpath(__DIR__ . $uriPath);
$publicRoot = realpath(__DIR__);
if ($uriPath !== '/' && $publicFile && $publicRoot && str_starts_with($publicFile, $publicRoot) && is_file($publicFile)) {
    $ext = strtolower(pathinfo($publicFile, PATHINFO_EXTENSION));
    $mimeTypes = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'ico' => 'image/x-icon',
        'json' => 'application/json; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
    ];
    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
        readfile($publicFile);
        exit;
    }
    return false;
}

// Serve main SPA HTML5 application
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>FINCAISSE — Gestion Commerciale, Caisse & Finances</title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <div id="app" class="app-shell">
    <div class="boot-loader">
      <div class="boot-spinner"></div>
      <p>Initialisation de FINCAISSE…</p>
    </div>
  </div>
  <div id="toast-root" class="toast-root" aria-live="polite"></div>
  <div id="print-area" class="print-only" aria-hidden="true"></div>
  <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
