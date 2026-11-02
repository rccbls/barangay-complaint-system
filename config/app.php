<?php
/**
 * Application constants (Phase 0).
 *
 * TIMEZONE CONVENTION (SCHEMA.md §1 recommendation; confirm with client)
 *  - STORE:   every DATETIME is stored in UTC.
 *             PHP default timezone is UTC, and each MySQL connection runs
 *             SET time_zone = '+00:00' (see config/database.php), so
 *             CURRENT_TIMESTAMP defaults in the schema are also UTC.
 *  - DISPLAY: convert to Philippine time (Asia/Manila) only when rendering,
 *             using format_display_datetime() from app/bootstrap.php.
 */
declare(strict_types=1);

const APP_NAME             = 'Barangay Complaint System';
const APP_STORAGE_TIMEZONE = 'UTC';
const APP_DISPLAY_TIMEZONE = 'Asia/Manila';

// PLACEHOLDER password rules (not client-approved). Max is a hard limit: bcrypt ignores bytes past 72.
const AUTH_PASSWORD_MIN_LENGTH = 12;
const AUTH_PASSWORD_MAX_BYTES  = 72;

// PLACEHOLDER priority levels (RULES §5). The client must define exact criteria and examples.
const PRIORITY_LEVELS = ['Critical', 'High', 'Medium', 'Low'];

// PLACEHOLDER definition of an "open" complaint, used only by Phase 4 deactivation guards.
// The client must define "pending"/"unresolved" (RULES §12.5). Queries alias complaints as "c".
const OPEN_COMPLAINT_CONDITION = "c.status NOT IN ('Closed', 'Rejected / Invalid')";
