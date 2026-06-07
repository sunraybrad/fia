<?php
/**
 * header.php — Inspector portal shared header
 * Set $page_title and $active_nav before including.
 */
$page_title = isset($page_title) ? h($page_title) : 'Inspector Portal';
$active_nav = $active_nav ?? '';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $page_title ?> | FIA Inspector</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/fia.css">
</head>
<body>

<nav class="navbar navbar-expand-md insp-navbar mb-3" aria-label="Inspector navigation">
    <div class="container-fluid">

        <a class="navbar-brand" href="/inspector/index.php">
            <img src="/images/logo/logo_horiz_600_inspect.png" alt="Florida Inspection Associates">
        </a>

        <button class="navbar-toggler" type="button"
                data-bs-toggle="collapse" data-bs-target="#inspNav"
                aria-controls="inspNav" aria-expanded="false">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="inspNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link <?= $active_nav === 'dashboard' ? 'active' : '' ?>"
                       href="/inspector/index.php">
                        <i class="bi bi-house"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active_nav === 'jobs' ? 'active' : '' ?>"
                       href="/inspector/jobs.php">
                        <i class="bi bi-clipboard-check"></i> My Jobs
                    </a>
                </li>
            </ul>
            <ul class="navbar-nav ms-auto align-items-center">
                <?php if (!empty($_SESSION['inspector_name'])): ?>
                <li class="nav-item">
                    <span class="navbar-text me-3">
                        <i class="bi bi-person-badge"></i>
                        <?= h($_SESSION['inspector_name']) ?>
                    </span>
                </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link" href="/inspector/logout.php">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                </li>
            </ul>
        </div>

    </div>
</nav>

<div class="container-fluid px-3 px-md-4">

<?php if (!empty($flash)): ?>
<div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
    <?= h($flash['msg']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
