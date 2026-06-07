<?php
/**
 * header.php — WarCo portal shared header
 *
 * Set before including:
 *   $page_title  (string)  Page title. Default: 'Client Portal'.
 *   $active_nav  (string)  Nav slug for active highlight. Default: ''.
 *   $flash       (array)   ['type' => 'success|danger|warning|info', 'msg' => '...']
 */

$page_title = isset($page_title) ? htmlspecialchars($page_title, ENT_QUOTES) : 'Client Portal';
$active_nav = $active_nav ?? '';
$flash      = $flash      ?? null;
if ($flash !== null && !in_array($flash['type'] ?? '', ['success', 'danger', 'warning', 'info'], true)) {
    $flash['type'] = 'info';
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $page_title ?> | FIA Client Portal</title>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/fia.css">
</head>
<body>

<nav class="navbar navbar-expand-md fia-navbar client-navbar mb-3" aria-label="Client navigation">
    <div class="container-fluid">

        <a class="navbar-brand" href="/client/">
            <img src="/images/logo/logo_horiz_600_client.png" alt="Florida Inspection Associates">
        </a>

        <button class="navbar-toggler" type="button"
                data-bs-toggle="collapse" data-bs-target="#warcoNav"
                aria-controls="warcoNav" aria-expanded="false"
                aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="warcoNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link <?= $active_nav === 'inspections' ? 'active' : '' ?>"
                       href="/client/index.php">
                        <i class="bi bi-clipboard-check"></i> My Inspections
                    </a>
                </li>
            </ul>

            <ul class="navbar-nav ms-auto align-items-center">
                <?php if (!empty($_SESSION['warco_name'])): ?>
                <li class="nav-item">
                    <span class="navbar-text me-3" style="font-size:.85rem;">
                        <i class="bi bi-building"></i>
                        <?= htmlspecialchars($_SESSION['warco_name'], ENT_QUOTES) ?>
                    </span>
                </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link" href="/client/logout.php">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                </li>
            </ul>
        </div>

    </div>
</nav>

<div class="container-fluid px-3 px-md-4">

<?php if ($flash): ?>
<div class="alert alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES) ?> alert-dismissible fade show fia-flash" role="alert">
    <?= $flash['msg'] ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>
