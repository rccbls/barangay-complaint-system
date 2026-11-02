<?php
/**
 * Shared layout - opening half.
 *
 * Set before including:
 *   $pageTitle    string      Page title (required)
 *   $layoutArea   string      'public' | 'resident' | 'staff'   (default 'public')
 *   $currentUser  array|null  Signed-in user from require_role()/require_login() (default null)
 *
 * The layout never queries the database; pages pass the user in.
 */
declare(strict_types=1);

$layoutArea = $layoutArea ?? 'public';
if (!in_array($layoutArea, ['public', 'resident', 'staff'], true)) {
    $layoutArea = 'public';
}
$currentUser   = $currentUser ?? null;
$pageTitle     = $pageTitle ?? APP_NAME;
$showPhaseTags = false; // Production clean styling
$currentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

// Show only the navigation items allowed for the signed-in role (all items in a signed-out preview).
$navItems = array_values(array_filter(
    (require APP_ROOT . '/app/Views/partials/nav_items.php')[$layoutArea] ?? [],
    static function (array $item) use ($currentUser): bool {
        return $currentUser === null || !isset($item['roles']) || in_array($currentUser['role_name'], $item['roles'], true);
    }
));

/** Render the nav entries. Items without an href are disabled placeholders. */
$renderNav = static function (array $items, string $class) use ($showPhaseTags, $currentScript): void {
    echo '<ul class="' . e($class) . '">';
    foreach ($items as $item) {
        echo '<li class="nav-item">';
        if ($item['href'] !== null) {
            $isActive = $currentScript === basename((string) $item['href']);
            echo '<a class="nav-link' . ($isActive ? ' active' : '') . '" href="' . e(app_url($item['href'])) . '"' . ($isActive ? ' aria-current="page"' : '') . '>';
            if (isset($item['icon'])) {
                echo '<i class="bi ' . e($item['icon']) . ' nav-icon" aria-hidden="true"></i>';
            }
            echo '<span>' . e($item['label']) . '</span></a>';
        } else {
            echo '<span class="nav-link disabled" aria-disabled="true">' . e($item['label']);
            if ($showPhaseTags) {
                echo ' <small class="text-body-secondary">(Phase ' . (int) $item['phase'] . ')</small>';
            }
            echo '</span>';
        }
        echo '</li>';
    }
    echo '</ul>';
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/app.css')) ?>">
</head>
<body class="app-area-<?= e($layoutArea) ?>">
<a class="visually-hidden-focusable app-skip-link" href="#main">Skip to main content</a>

<header class="app-topbar">
    <div class="app-topbar-inner d-flex align-items-center gap-2 px-3 py-2">
        <?php if ($layoutArea === 'staff'): ?>
            <button class="btn btn-outline-secondary btn-sm d-lg-none app-menu-button" type="button"
                    data-bs-toggle="offcanvas" data-bs-target="#staffSidebar"
                    aria-controls="staffSidebar" aria-label="Open menu"><i class="bi bi-list me-1"></i>Menu</button>
        <?php endif; ?>
        <?php if ($currentUser !== null): ?>
            <a class="app-brand text-decoration-none" href="<?= e(app_url(home_path_for_role($currentUser['role_name']))) ?>">
                <span class="app-brand-icon"><i class="bi bi-shield-check"></i></span>
                <span><?= e(APP_NAME) ?></span>
            </a>
        <?php else: ?>
            <span class="app-brand">
                <span class="app-brand-icon"><i class="bi bi-shield-check"></i></span>
                <span><?= e(APP_NAME) ?></span>
            </span>
        <?php endif; ?>

        <div class="ms-auto d-flex align-items-center gap-2 small">
            <?php if ($currentUser !== null): ?>

                <span class="app-user-chip d-none d-sm-inline-flex">
                    <span class="app-user-avatar"><?= e(strtoupper(substr((string) $currentUser['full_name'], 0, 1))) ?></span>
                    <span><?= e($currentUser['full_name']) ?></span>
                    <span class="text-body-secondary">(<?= e($currentUser['role_name']) ?>)</span>
                </span>
                <form method="post" action="<?= e(app_url('logout.php')) ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-box-arrow-right me-1"></i>Sign out</button>
                </form>
            <?php elseif ($layoutArea === 'public'): ?>
                <a href="<?= e(app_url('login.php')) ?>">Sign in</a>
                <a href="<?= e(app_url('register.php')) ?>">Register</a>
            <?php else: ?>
                <span class="text-body-secondary">Layout preview (not signed in)</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($layoutArea === 'resident'): ?>
        <nav class="app-resident-nav px-3" aria-label="Resident navigation">
            <?php $renderNav($navItems, 'nav nav-underline flex-nowrap'); ?>
        </nav>
    <?php endif; ?>
</header>

<?php if ($layoutArea === 'staff'): ?>
<div class="app-shell">
    <aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="staffSidebar" aria-labelledby="staffSidebarLabel">
        <div class="offcanvas-header">
            <h2 class="offcanvas-title h6" id="staffSidebarLabel">Menu</h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#staffSidebar" aria-label="Close menu"></button>
        </div>
        <div class="offcanvas-body">
            <nav aria-label="Staff navigation" class="w-100">
                <?php $renderNav($navItems, 'nav flex-column'); ?>
            </nav>
        </div>
    </aside>
    <main id="main" class="app-main" tabindex="-1">
<?php else: ?>
<div class="app-shell">
    <main id="main" class="app-main app-main-narrow" tabindex="-1">
<?php endif; ?>
<?php if (function_exists('flash_render')) { flash_render(); } ?>
