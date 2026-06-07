<?php
/**
 * header.php — Office portal shared header
 *
 * Set before including:
 *   $page_title  (string)  Page title — used in <title> and heading. Default: 'Office'.
 *   $active_nav  (string)  Current nav slug for active highlight. Default: ''.
 *   $flash       (array)   ['type' => 'success|danger|warning|info', 'msg' => '...']
 */

$page_title = isset($page_title) ? htmlspecialchars($page_title, ENT_QUOTES) : 'Office';
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
    <title><?= $page_title ?> | FIA Office</title>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/fia.css">
</head>
<body>

<nav class="navbar navbar-expand-md fia-navbar office-navbar mb-3" aria-label="Office navigation">
    <div class="container-fluid">

        <a class="navbar-brand" href="/office/">
            <img src="/images/logo/logo_horiz_600_admin.png" alt="Florida Inspection Associates">
        </a>

        <button class="navbar-toggler" type="button"
                data-bs-toggle="collapse" data-bs-target="#officeNav"
                aria-controls="officeNav" aria-expanded="false"
                aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="officeNav">
            <ul class="navbar-nav me-auto">

                <li class="nav-item">
                    <a class="nav-link <?= ($active_nav === 'dashboard' || $active_nav === 'inspections') ? 'active' : '' ?>"
                       href="/office/index.php">
                        <i class="bi bi-clipboard-check"></i> Inspections
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active_nav === 'inspectors' ? 'active' : '' ?>"
                       href="/office/inspectors.php">
                        <i class="bi bi-people"></i> Inspectors
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active_nav === 'clients' ? 'active' : '' ?>"
                       href="/office/warranty_cos.php">
                        <i class="bi bi-building"></i> Warranty Cos
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active_nav === 'messages' ? 'active' : '' ?>"
                       href="/office/messages.php">
                        <i class="bi bi-megaphone"></i> Messages
                    </a>
                </li>

            </ul>

            <ul class="navbar-nav ms-auto align-items-center">
                <?php if (!empty($_SESSION['office_name'])): ?>
                <li class="nav-item">
                    <span class="navbar-text me-3">
                        <i class="bi bi-person-circle"></i>
                        <?= htmlspecialchars($_SESSION['office_name'], ENT_QUOTES) ?>
                    </span>
                </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link" href="/office/logout.php">
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
    <?= htmlspecialchars($flash['msg'], ENT_QUOTES) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>
