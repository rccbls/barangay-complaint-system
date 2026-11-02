<?php
/**
 * Central authorization middleware (Phase 2). RULES.md §2.1–2.2.
 *
 * EVERY protected page must call require_login() or require_role() before doing anything
 * else. tests/phase2_check.php scans public/ and fails if a page is neither protected nor on
 * the short public allow-list.
 */
declare(strict_types=1);

/** Render an error page inside the layout and stop. */
function abort_page(int $code, string $title, string $message, ?array $user = null): never
{
    http_response_code($code);
    $pageTitle   = $title;
    $currentUser = $user;
    $layoutArea  = $user !== null ? layout_area_for_role($user['role_name']) : 'public';
    require APP_ROOT . '/app/Views/layouts/header.php';
    echo '<h1 class="h3">' . e($title) . '</h1><p>' . e($message) . '</p>';
    if ($user !== null) {
        echo '<a class="btn btn-primary" href="' . e(app_url(home_path_for_role($user['role_name']))) . '">Back to my home page</a>';
    } else {
        echo '<a class="btn btn-primary" href="' . e(app_url('login.php')) . '">Sign in</a>';
    }
    require APP_ROOT . '/app/Views/layouts/footer.php';
    exit;
}

/** Require a signed-in, active user. Signed-out visitors are sent to the sign-in page. */
function require_login(): array
{
    header('Cache-Control: no-store, private'); // do not keep protected pages in the back/forward cache
    $user = current_user();
    if ($user === null) {
        redirect('login.php');
    }
    return $user;
}

/**
 * Require a signed-in user whose role is in $roles; otherwise 403.
 * @param list<string> $roles
 */
function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role_name'], $roles, true)) {
        abort_page(403, 'Access denied', 'Your account does not have permission to open this page.', $user);
    }
    return $user;
}

/** For sign-in/registration pages: signed-in users go to their own home page instead. */
function require_guest(): void
{
    $user = current_user();
    if ($user !== null) {
        redirect(home_path_for_role($user['role_name']));
    }
}

/** Call at the top of POST handling. Rejects a missing or wrong CSRF token. */
function require_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        abort_page(405, 'Method not allowed', 'This action must be submitted from a form.', current_user());
    }
    if (!csrf_verify(input_string($_POST, 'csrf_token'))) {
        abort_page(403, 'Request not accepted', 'Your session may have expired. Go back, reload the page, and try again.', current_user());
    }
}

/**
 * Reusable resident record-level guard: only the resident who submitted the complaint passes.
 * Answers 404 (not 403) so a resident cannot learn whether another complaint ID exists.
 * For Resident-role pages that show or act on one complaint (used from Phase 6).
 */
function require_resident_owns_complaint(int $complaintId): array
{
    $user = require_role([ROLE_RESIDENT]);
    if (!resident_owns_complaint(db(), (int) $user['user_id'], $complaintId)) {
        abort_page(404, 'Not found', 'That complaint could not be found.', $user);
    }
    return $user;
}
