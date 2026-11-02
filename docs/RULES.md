# System Rules and Business Logic
## Barangay Complaint Prioritization and Routing System

**Status:** Initial proposed rules. All operational rules marked for client approval must be confirmed before production use.

## 1. Rule authority
- Approved barangay procedures and authorized client decisions take precedence over these draft rules.
- The software supports residents and barangay staff; it does not replace official authority, emergency services, or due process.
- Maintain a record of approved changes to categories, priority criteria, roles, and workflow.

## 2. Authentication and authorization
1. Every protected page and action requires an authenticated user.
2. Enforce permissions on the server for every request.
3. Administrator manages accounts and approved configuration.
4. Complaint Officer may register, review, assign, reassign, and update complaints within approved permissions.
5. Personnel may view and update only complaints assigned to them, except for explicitly authorized broader access.
6. Management access is read-only unless separately approved.
7. Resident users may create complaints and view only complaints submitted through their own resident account, subject to approved disclosure rules.
8. A resident must never be able to access another resident's complaint by changing a URL, hidden field, request parameter, or reference ID.
9. Inactive users cannot log in or perform actions.
10. Users must not share accounts or credentials.
11. Log out invalidates the session; regenerate session IDs after login.

## 3. Resident account and online complaint submission
1. Resident registration collects only client-approved account/profile information.
2. A resident's identity is taken from the authenticated session when creating an online complaint; the resident cannot select another user as the complainant.
3. The server must associate an online complaint with the authenticated resident account.
4. A resident complaint must contain the client-approved required complaint fields, such as category, description, and location when applicable.
5. The system generates a unique, non-identifying reference number.
6. The server sets the submission/created timestamp.
7. Validate and normalize all resident-submitted input server-side.
8. Online complaint creation and its initial history records should be committed in one transaction.
9. Do not create duplicate records when a resident double-clicks submit or retries a request; use a server-side submission token or idempotency strategy.
10. After successful submission, show the reference number and make the complaint available in the resident's My Complaints area.
11. Resident-facing status/history views must expose only information approved for resident disclosure. Internal staff notes and internal audit details are not automatically resident-visible.
12. The resident should not be allowed to set the official priority or assign personnel unless the client explicitly approves such a function.

## 4. Staff complaint registration
1. Authorized staff may encode complaints received through approved non-online channels such as walk-in, phone, or referral.
2. A staff-encoded complaint must have a category, description, receiving user, source, priority, priority reason, and initial status, subject to the final client-approved field list.
3. Complainant details are collected only when approved and necessary; support null/anonymous details if client policy permits.
4. Staff-encoded and resident-submitted complaints use the same downstream classification, assignment, status, and reporting workflow.
5. System generates a unique, non-identifying reference number.
6. The server sets received/created timestamps; users must not be able to backdate system receipt time without an authorized correction workflow.
7. Validate and normalize all input server-side.
8. Initial complaint creation, initial status history, and initial priority history should be committed in one transaction.
9. Do not create duplicate records when a user double-clicks submit; use server-side safeguards such as a submission token or idempotency strategy.

## 5. Priority rules
The following four levels are a proposed starting point only:
- Critical
- High
- Medium
- Low

The client must define the exact criteria, examples, and escalation procedure for each level.

Proposed decision approach:
1. Authorized staff answers client-approved urgency assessment questions.
2. System applies approved, deterministic rules and displays the reason.
3. Complaint Officer reviews the result.
4. Authorized officer may override priority only with a reason.
5. Record old priority, new priority, reason, user, and timestamp for every change.
6. Reassess priority if material new information is recorded.

Residents may provide complaint information that indicates urgency, but resident input is not automatically the official priority classification unless the client explicitly approves that behavior.

Do not infer risk solely from complaint category. Category may suggest a default, but approved urgency criteria and human review determine the final handling priority.

### Emergency handling
If information indicates immediate danger or a threat to life or safety, display the client-approved instruction to contact appropriate emergency responders immediately. The system must not imply that submitting a complaint automatically summons emergency assistance or guarantees response.

## 6. Routing and assignment
1. Only authorized officers may assign or reassign cases.
2. Only active, eligible personnel may receive new assignments.
3. Category-to-personnel mapping is a suggestion based on client-approved routing configuration.
4. Officer confirms the assignee; automatic assignment should not be enabled unless specifically approved.
5. Keep assignment history; do not overwrite previous assignment records.
6. Reassignment requires a reason or note if required by client policy.
7. At most one active primary assignment per complaint unless multiple assignees are explicitly approved.
8. Assignment and complaint status changes must be committed consistently in a transaction.

## 7. Status workflow
Proposed statuses:
- Received
- For Assignment
- Assigned
- In Progress
- On Hold
- Resolved
- Closed
- Rejected / Invalid

Proposed permitted transitions (final transitions require client approval):
```text
Received -> For Assignment
Received -> Rejected / Invalid
For Assignment -> Assigned
For Assignment -> Rejected / Invalid
Assigned -> In Progress
Assigned -> For Assignment (unassign/reassign)
In Progress -> On Hold
On Hold -> In Progress
In Progress -> Resolved
Resolved -> Closed
Resolved -> In Progress (reopen with authorization and reason)
Closed -> Reopened only if client policy explicitly permits
```

Rules:
1. Validate every transition server-side.
2. Record old status, new status, user, timestamp, and action/remarks.
3. Require rejection reason for Rejected / Invalid.
4. Require action/resolution details before marking Resolved.
5. Only authorized roles may close a complaint.
6. Closed complaints are read-only except for an approved reopen/correction process.
7. Never silently erase status history.
8. A resident may view the current status and approved resident-facing history but may not change the official status.

## 8. Response time and overdue logic
- Do not invent service-level targets.
- The client must define whether targets apply to acknowledgment, assignment, first action, resolution, or another milestone.
- Define business hours, holidays, pause rules (e.g., On Hold), and escalation responsibility with the client.
- Mark a complaint overdue only when an approved target and calculation rule exist.
- Display elapsed time and target context clearly; do not imply that an automated flag is an official finding of fault.

## 9. Editing, deletion, and correction
1. Restrict editing of submitted records by role.
2. Residents may edit or withdraw a submitted complaint only if the client explicitly approves the function and the workflow supports it.
3. Preserve a history or audit entry for consequential edits.
4. Do not hard-delete complaints, assignments, or history through normal user workflows.
5. Correct errors through authorized edits that record who changed what, when, and why.
6. Apply client-approved retention and archival schedules.

## 10. Privacy and information access
1. Collect the minimum personal data necessary for approved purposes.
2. Restrict resident contact details and sensitive complaint narratives by role.
3. A resident may access only complaints associated with that resident account.
4. Do not expose personal information in reference numbers, URLs, or public dashboards.
5. Do not expose staff-only notes, internal routing details, or private audit records to residents unless explicitly approved.
6. Do not export sensitive fields unless the user's role and purpose are authorized.
7. Use approved privacy notices, retention, disclosure, and incident-handling procedures.
8. Use synthetic or anonymized data for development and demonstrations unless use of actual records is explicitly authorized and safeguarded.

## 11. Attachments
If enabled:
- Allow only approved file types and sizes.
- Verify file content and MIME type server-side; do not trust filename or browser metadata.
- Generate a random stored filename.
- Store files outside the public web root and serve through an authorization-checked endpoint.
- Block executable content and scan uploads where an approved scanning tool is available.
- Record uploader, timestamp, original filename, and stored filename.
- A resident may access only attachments belonging to complaints that resident is authorized to view.

## 12. Reports and dashboard
1. All totals must be calculated from persisted records using documented definitions.
2. Report filters must be applied server-side.
3. Respect the same record-level permissions used by complaint pages.
4. Label date ranges, generation time, and applied filters.
5. Define metrics such as “pending,” “unresolved,” “resolved,” and “response time” consistently with the client.
6. Resident dashboards must not expose system-wide complaint counts or other residents' information unless explicitly approved.
7. Protect CSV exports against spreadsheet formula injection.

## 13. Audit and operational safeguards
Record, at minimum, consequential account, assignment, status, priority, and closure actions with actor and timestamp. Limit access to audit data. Do not log passwords, authentication tokens, or unnecessary sensitive content.

Before production:
- Confirm role/permission matrix including Resident
- Confirm resident-visible fields and status/history information
- Confirm priority and routing tables
- Confirm status transitions and closure authority
- Confirm data retention and privacy procedures
- Test backup restoration
- Test resident-to-record authorization, including ID/URL tampering attempts
- Test authorization and common security failure cases
- Obtain client acceptance of UAT results
