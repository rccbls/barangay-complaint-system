<?php
/** Shared layout — closing half. Pairs with header.php. */
declare(strict_types=1);
?>
    </main>
</div>

<footer class="app-footer px-4 py-3 small text-body-secondary d-flex flex-wrap justify-content-between align-items-center">
    <div>
        &copy; <?= date('Y') ?> <strong><?= e(APP_NAME) ?></strong>. All rights reserved.
    </div>
    <div class="text-body-tertiary">
        Barangay Complaint Management &amp; Routing Portal
    </div>
</footer>

<script src="<?= e(app_url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
</body>
</html>
