<?php
/** Sign-in for residents and staff (FR-001, FR-020). */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/init.php';
require_guest();

$error    = null;
$username = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $username = trim(input_string($_POST, 'username'));
    $result   = auth_login(db(), $username, input_string($_POST, 'password'));
    if ($result['ok']) {
        redirect(home_path_for_role(current_user()['role_name']));
    }
    $error = $result['error'];
}

$pageTitle   = 'Sign in';
$layoutArea  = 'public';
$currentUser = null;
require APP_ROOT . '/app/Views/layouts/header.php';
?>

<div class="row justify-content-center py-4">
    <div class="col-md-7 col-lg-5 col-xl-4">
        <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
            <div class="bg-primary bg-gradient p-4 text-center text-white">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-white text-primary mb-3 shadow-sm" style="width: 58px; height: 58px; font-size: 1.75rem;">
                    <i class="bi bi-shield-shaded"></i>
                </div>
                <h2 class="h4 fw-bold mb-1">Barangay Portal</h2>
                <p class="text-white-50 small mb-0">Complaint Prioritization &amp; Routing System</p>
            </div>
            
            <div class="card-body p-4 p-md-4">
                <div class="mb-3 text-center">
                    <h3 class="h5 fw-bold text-dark mb-1">Welcome Back</h3>
                    <p class="text-muted small">Sign in to your resident or staff account</p>
                </div>

                <?php if ($error !== null): ?>
                    <div class="alert alert-danger d-flex align-items-center py-2 px-3 small rounded-3" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                        <div><?= e($error) ?></div>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= e(app_url('login.php')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="username" class="form-label small fw-semibold text-secondary">Username or Email</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light text-secondary border-end-0"><i class="bi bi-person"></i></span>
                            <input type="text" class="form-control border-start-0 ps-0" id="username" name="username" value="<?= e($username) ?>"
                                   maxlength="60" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="Enter username" required autofocus>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label small fw-semibold text-secondary">Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light text-secondary border-end-0"><i class="bi bi-lock"></i></span>
                            <input type="password" class="form-control border-start-0 ps-0" id="password" name="password"
                                   maxlength="1000" autocomplete="current-password" placeholder="Enter password" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100 py-2 mt-2 shadow-sm fw-semibold">
                        Sign in <i class="bi bi-box-arrow-in-right ms-1"></i>
                    </button>
                </form>

                <div class="mt-4 pt-3 border-top text-center">
                    <p class="text-muted small mb-0">
                        New resident? <a href="<?= e(app_url('register.php')) ?>" class="fw-semibold text-primary text-decoration-none">Create an account &rarr;</a>
                    </p>
                </div>
            </div>
        </div>

        <!-- Helpful quick-credentials callout for testing -->
        <div class="card border-0 bg-white shadow-sm rounded-3 mt-3 p-3">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="bi bi-info-circle-fill text-primary"></i>
                <span class="small fw-bold text-dark">Demo &amp; Testing Accounts</span>
            </div>
            <div class="small text-muted" style="font-size: 0.8rem; line-height: 1.5;">
                <div><strong>Admin:</strong> <code>admin</code> &bull; <strong>Officer:</strong> <code>officer1</code></div>
                <div><strong>Personnel:</strong> <code>personnel1</code> &bull; <strong>Resident:</strong> <code>resident1</code></div>
                <div class="mt-1 text-secondary">Password for all: <code>Password123!</code></div>
            </div>
        </div>
    </div>
</div>

<?php require APP_ROOT . '/app/Views/layouts/footer.php'; ?>
