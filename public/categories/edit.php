<?php
/** Administrator: edit a complaint category (Phase 4). Activation is on the separate status page. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/init.php';
require_once APP_ROOT . '/app/Views/partials/form_helpers.php';
$currentUser = require_role([ROLE_ADMIN]);
$pdo         = db();

$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($isPost) {
    require_csrf();
}
$id       = (int) input_string($isPost ? $_POST : $_GET, $isPost ? 'category_id' : 'id');
$category = $id > 0 ? category_find($pdo, $id) : null;
if ($category === null) {
    abort_page(404, 'Not found', 'That category could not be found.', $currentUser);
}

$errors = [];
$values = [
    'category_name'       => $category['category_name'],
    'description'         => (string) $category['description'],
    'default_priority'    => (string) $category['default_priority'],
    'default_assignee_id' => (string) $category['default_assignee_id'],
];

if ($isPost) {
    [$errors, $clean] = category_validate($pdo, $_POST);
    $values = [
        'category_name'       => $clean['category_name'],
        'description'         => (string) $clean['description'],
        'default_priority'    => (string) $clean['default_priority'],
        'default_assignee_id' => input_string($_POST, 'default_assignee_id'),
    ];
    if ($errors === []) {
        $result = category_edit($pdo, $currentUser, $id, $clean);
        if ($result['ok']) {
            flash_set('success', 'Category "' . $clean['category_name'] . '" was updated.');
            redirect('categories/index.php');
        }
        $errors = $result['errors'];
    }
}

$assignees   = category_eligible_assignees($pdo);
$selfAction  = app_url('categories/edit.php');
$categoryId  = $id;
$submitLabel = 'Save changes';
$pageTitle   = 'Edit category';
$layoutArea  = 'staff';
require APP_ROOT . '/app/Views/layouts/header.php';
?>
<h1 class="h3 mb-3">Edit category</h1>
<?php require APP_ROOT . '/app/Views/partials/category_form.php'; ?>
<?php require APP_ROOT . '/app/Views/layouts/footer.php'; ?>
