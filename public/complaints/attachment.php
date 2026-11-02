<?php
/**
 * Attachment download/serve endpoint (Phase 13: FR-018, RULES §11).
 *
 * Enforces authentication and authorization:
 *  - Staff (Admin, Officer, Management) can view all attachments.
 *  - Assigned Personnel can view attachments of assigned complaints.
 *  - Residents can view only attachments of their own complaints.
 *  - Files stored outside web root are served with secure headers.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/init.php';
require_once APP_ROOT . '/app/Services/ComplaintService.php';

$currentUser = require_login();
$pdo = db();

$attachmentId = (int) input_string($_GET, 'id');
if ($attachmentId <= 0) {
    http_response_code(404);
    exit('Attachment not found.');
}

$attachment = attachment_fetch($pdo, $attachmentId);
if (!$attachment) {
    http_response_code(404);
    exit('Attachment not found.');
}

$complaint = complaint_fetch_by_id($pdo, (int)$attachment['complaint_id']);
if (!$complaint) {
    http_response_code(404);
    exit('Associated complaint not found.');
}

// Authorization checks
$role = $currentUser['role_name'];
$userId = (int)$currentUser['user_id'];
$isAuthorized = false;

if (in_array($role, [ROLE_ADMIN, ROLE_OFFICER, ROLE_MANAGEMENT], true)) {
    $isAuthorized = true;
} elseif ($role === ROLE_PERSONNEL) {
    if (assignment_is_assigned_to_user($pdo, (int)$complaint['complaint_id'], $userId)) {
        $isAuthorized = true;
    }
} elseif ($role === ROLE_RESIDENT) {
    if (resident_owns_complaint($pdo, $userId, (int)$complaint['complaint_id'])) {
        $isAuthorized = true;
    }
}

if (!$isAuthorized) {
    http_response_code(403);
    exit('Forbidden: You are not authorized to access this attachment.');
}

$filePath = APP_ROOT . '/storage/uploads/' . $attachment['stored_filename'];
if (!file_exists($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit('File not found on storage.');
}

// Serve file with security headers
header('Content-Type: ' . $attachment['mime_type']);
header('Content-Length: ' . (string)$attachment['file_size_bytes']);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . rawurlencode($attachment['original_filename']) . '"');
header('Cache-Control: private, max-age=3600');

readfile($filePath);
exit;
