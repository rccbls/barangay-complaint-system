# Development Roadmap

## Barangay Complaint Prioritization and Routing System

**Purpose:** Control build order so the AI implements **one phase at a time** instead of every module at once. **Source documents:** PRD.md, RULES.md, SCHEMA.md, DESIGN.md, ARCHITECTURE.md (FR-IDs refer to PRD §6). The phase ordering below is a suggested sequence based on dependencies between those documents.

**Fresh-start scope:** The system is designed from the beginning as a two-sided web application. Residents can create accounts, submit complaints online without personally visiting the barangay office, and monitor their own complaints. Authorized barangay personnel receive, review, prioritize, route, monitor, resolve, and report complaints. Staff-encoded complaints from approved non-online channels remain part of the same centralized system.

---

## Instructions for the AI (read first)

1. Work on **only the phase the user names**. Do not create files, tables, routes, or UI for any later phase.
2. Follow PRD.md, RULES.md, SCHEMA.md, DESIGN.md, and ARCHITECTURE.md. If this roadmap conflicts with them, stop and ask.
3. Do not invent client rules (priority criteria, SLA targets, status transitions, categories, resident-visible information). Use the proposed defaults in the documents and label them as placeholders.
4. Reuse shared helpers (authentication, CSRF, escaping, DB connection, validation, authorization) instead of duplicating code.
5. Use PDO prepared statements, server-side authorization on every protected action, and output escaping in every phase.
6. Use synthetic test data only.
7. A resident must never be able to access another resident's complaint by changing a URL, request parameter, hidden field, or reference number.
8. When the phase is complete, list what was built, how to test it against the "Done when" checks, and **stop**. Do not continue to the next phase.
9. If a requirement is unclear or blocked by a client decision, say so instead of guessing.

**Suggested prompt per session:**

> We are on Phase X only. Follow the project .md files and ROADMAP.md. Do not build anything from later phases. Stop when the "Done when" checks pass.

---

## Progress tracker

Check a box only after the user has tested it.

### Phase 0: Project setup

- Folder structure per ARCHITECTURE §6 (`public/`, `app/`, `config/`, `database/`, `storage/`, `tests/`)
- Local XAMPP (Apache, PHP 8.x, MySQL) running
- Git initialized; credentials kept out of source control
- `config/database.php` using PDO with prepared statements
- Timezone convention decided and documented (SCHEMA §1 recommends storing UTC, displaying Philippine time)
- Shared layout (header, sidebar, footer) using Bootstrap 5
- Separate resident and staff navigation areas planned in the shared layout

**Done when:** a blank page loads through Apache and connects to MySQL.

### Phase 1: Database foundation

- Run SCHEMA §3 to create all tables for a fresh installation
- Seed the five roles in SCHEMA §4: Resident, Administrator, Complaint Officer, Personnel, Management
- Controlled setup script creates the first administrator with `password_hash()` (no plaintext in SQL)
- Synthetic test data only

**Done when:** tables and foreign keys exist, and one administrator row exists with a hashed password.

### Phase 2: Authentication and role enforcement (FR-001, FR-002, FR-020)

- Resident registration and login
- Staff/admin login and logout
- Session ID regenerated after login; logout invalidates the session
- Inactive users cannot log in
- Central authorization middleware checks the role on every protected request
- Resident record-level authorization is established as a reusable rule
- CSRF token helper and output-escaping helper are implemented once and reused

**Done when:** residents and staff can authenticate appropriately, invalid credentials are rejected, and a user cannot open a protected page belonging to another role by typing the URL.

### Phase 3: Resident profile and account management

- Resident account/profile structure follows SCHEMA §2–§5
- Resident registration collects only client-approved fields
- Administrator can activate/deactivate accounts as authorized
- Resident can maintain only permitted profile information
- Password handling uses secure hashing; passwords are never stored in plaintext
- Resident account is linked to the resident profile used by complaints

**Done when:** a resident account can be created, authenticated, deactivated, and linked to exactly one resident profile without exposing another resident's data.

### Phase 4: Administrator user and category management

- Administrator can create, edit, activate, and deactivate staff accounts
- Administrator can manage complaint categories
- Resident accounts remain protected from unauthorized administrative changes
- Guard against deactivating a user or category that would leave records without a valid handling path
- Default priority and default assignee remain suggestions only

**Done when:** an administrator can manage approved accounts and categories, while non-administrators are blocked server-side.

### Phase 5: Complaint registration and online submission (FR-003, FR-004, FR-021, FR-023)

- Resident complaint submission form uses only client-approved fields
- Online complaints use `source='Online'` and are associated with the authenticated resident account
- Staff can also encode approved non-online complaints such as walk-in, phone, or referral
- System generates a unique, non-identifying reference number
- Server-side validation and normalization
- Priority is recorded using the approved/manual placeholder process; deterministic rules come later
- Single transaction writes the complaint and initial status/priority history
- Double-submit protection
- Resident sees the reference number after successful online submission

**Done when:** a resident can submit one valid online complaint and receive a reference number, while staff can still encode approved non-online complaints.

### Phase 6: Complaint list, details, and resident "My Complaints" (FR-005, FR-022)

- Staff complaint list supports search/filtering by approved fields
- Resident "My Complaints" lists only complaints submitted by the authenticated resident
- Complaint details page shows role-appropriate information
- Resident cannot access another resident's complaint by changing an ID, URL, request parameter, or reference number
- Timeline displays permitted status and priority events
- Priority and status badges use text labels, not color alone

**Done when:** staff can retrieve authorized complaint records and residents can retrieve only their own records.

### Phase 7: Status workflow (FR-012, FR-013, FR-014)

- Transition table defined in one central place (RULES §7)
- Server rejects invalid transitions
- Required reason for Rejected / Invalid; required resolution details before Resolved
- Every change writes status history (old, new, user, timestamp, remarks)
- Only authorized roles can close; closed records are read-only except approved reopen/correction flow
- Resident can view only the status/history information approved for residents

**Done when:** invalid transitions are rejected, history is never erased, and resident-visible status is correctly scoped.

### Phase 8: Assignment and routing (FR-009, FR-010)

- Officer assigns to active personnel only
- Reassignment closes the previous assignment (`unassigned_at`) and creates a new row
- At most one active assignment per complaint
- Category-based assignee shown as a suggestion; officer confirms (no auto-assign)
- Assignment and related status change occur in one transaction

**Done when:** assignment history shows every assignment and reassignment and unauthorized users cannot perform assignment actions.

### Phase 9: Personnel workspace (FR-011)

- "My Assignments" page limited to the personnel user's assigned complaints
- Action and progress notes and permitted status updates
- Submit resolution details for review
- Officer review and close flow

**Done when:** personnel cannot view or edit complaints outside their assigned scope.

### Phase 10: Priority rules and override (FR-006, FR-007, FR-008)

- Urgency assessment questions and deterministic rules only after client approval
- Priority reason displayed
- Officer override requires a reason and writes priority history
- Emergency-contact instruction shown only with client-approved wording
- Resident-submitted urgency information must not automatically become official priority unless approved

**Done when:** every priority change has a history entry with a reason and the final priority follows the approved criteria.

### Phase 11: Dashboard (FR-015)

- Staff dashboard summary cards and recent complaints table from database queries
- Priority summary and complaint volume by category/period
- Authorized workload/assignment summaries
- Resident dashboard remains limited to the resident's own complaints
- No system-wide complaint statistics are exposed to residents unless approved

**Done when:** dashboard counts match corresponding SQL queries and resident record scoping is correct.

### Phase 12: Reports (FR-016)

- Filters by date, category, priority, status, and other approved fields
- Filters applied server-side
- Reports respect user permissions
- Report labels include date range, generation time, and applied filters
- Metric definitions such as "pending" and "unresolved" are documented and used consistently

**Done when:** report results match selected filters and authorized scope.

### Phase 13: P1 features

- CSV/print export with formula-injection protection (FR-017)
- Restricted attachments (FR-018)
- Overdue flag only after client-approved service targets (FR-019)

**Done when:** each P1 feature passes its security, permission, and functional checks.

### Phase 14: Hardening and UAT

- Authorization and security failure tests
- Resident-to-resident access tests
- Staff-to-resident and resident-to-staff boundary tests
- Backup and restore test
- Responsive check at small widths
- Client UAT and sign-off

**Done when:** critical UAT scenarios pass and the client approves the release scope.

---

## Client decisions that can block phases

| Blocks | Needs client confirmation |
|---|---|
| Phase 2–3 | Resident registration fields, identity requirements, account activation process |
| Phase 4–5 | Official categories, required complaint fields, anonymous complaints, approved complaint channels |
| Phase 7 | Final status transitions, who can close/reopen, resident-visible status/history |
| Phase 10 | Priority criteria, escalation rules, emergency wording |
| Phase 12–13 | Response-time targets, export formats, attachment types/sizes |

If a client decision is not yet available, label the proposed behavior as a placeholder and do not present it as an official barangay rule.
