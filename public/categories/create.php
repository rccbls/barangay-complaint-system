<?php
/** Administrator: add a complaint category (Phase 4). */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/init.php';
require_once APP_ROOT . '/app/Views/partials/form_helpers.php';
$currentUser = require_role([ROLE_ADMIN]);
$pdo         = db();

$errors = [];
$values = ['category_name' => '', 'description' => '', 'default_priority' => '', 'default_assignee_id' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    [$errors, $clean] = category_validate($pdo, $_POST);
    $values = [
        'category_name'       => $clean['category_name'],
        'description'         => (string) $clean['description'],
        'default_priority'    => (string) $clean['default_priority'],
        'default_assignee_id' => input_string($_POST, 'default_assignee_id'),
    ];
    if ($errors === []) {
        $result = category_create($pdo, $currentUser, $clean);
        if ($result['ok']) {
            flash_set('success', 'Category "' . $clean['category_name'] . '" was added.');
            redirect('categories/index.php');
        }
        $errors = $result['errors'];
    }
}

$assignees   = category_eligible_assignees($pdo);
$selfAction  = app_url('categories/create.php');
$categoryId  = null;
$submitLabel = 'Add category';
$pageTitle   = 'Add category';
$layoutArea  = 'staff';
require APP_ROOT . '/app/Views/layouts/header.php';
?>
<h1 class="h3 mb-3">Add category</h1>
<?php require APP_ROOT . '/app/Views/partials/category_form.php'; ?>
<?php require APP_ROOT . '/app/Views/layouts/footer.php'; ?>
