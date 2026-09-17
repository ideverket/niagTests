<?php
require __DIR__.'/lib/bootstrap.php';
headers_private();
$adminPage = isset($_GET['admin']);
$version = max(filemtime(__DIR__.'/assets/app.js'), filemtime(__DIR__.'/assets/style.css'));
?><!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $adminPage ? 'Administration' : 'Kunskapstest' ?> · NIAG</title>
<link rel="icon" href="assets/niag-logo.jpg?v=<?= $version ?>">
<link rel="stylesheet" href="assets/style.css?v=<?= $version ?>">
<script src="assets/app.js?v=<?= $version ?>" defer></script>
</head>
<body data-page="<?= $adminPage ? 'admin' : 'exam' ?>">
<a class="skip" href="#main">Till innehållet / Skip to content</a>
<header class="topbar"><a class="brand" href="index.php"><img src="assets/niag-logo.jpg?v=<?= $version ?>" alt="NIAG" width="152" height="46"></a><div class="brand-divider"></div><span id="site-label">Kunskap & kompetens</span><nav id="topnav" aria-label="Huvudmeny"></nav></header>
<div id="notice" class="notice" role="status" aria-live="polite" hidden></div>
<div id="app"><main id="main" class="loading" tabindex="-1">Laddar / Loading…</main></div>
<footer>NORDIC INSPECTION & AUDIT GROUP AB <span>NIAG · Kunskapstest</span></footer>
</body></html>
