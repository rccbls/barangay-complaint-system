# Product Requirements Document (PRD)
## Barangay Complaint Prioritization and Routing System

| Document item | Details |
|---|---|
| Version | 0.2 — Resident-facing scope update |
| Product type | Web-based resident complaint submission and barangay complaint management system |
| Intended client | Barangay Santolan, Pasig City (to be confirmed) |
| Audience | Capstone researchers, developers, client representatives, testers, resident test users |
| Status | Proposed; requires client validation |

## 1. Product summary
The system provides residents with a convenient web-based way to submit complaints without personally visiting the barangay office. Submitted complaints are centralized for authorized barangay personnel to review, classify, prioritize, route, monitor, resolve, and report.

The system may also allow authorized staff to encode complaints received through approved non-online channels, such as walk-in, phone, or referral, so that those records can be managed through the same workflow.

The application is not an emergency dispatch system and does not replace the judgment or authority of barangay officials.

## 2. Problem statement
Residents may need to personally visit or otherwise contact the barangay to report concerns. A web-based submission process can provide an additional convenient channel for submitting complaints and receiving a reference number, while a centralized staff workflow can help organize, prioritize, route, and monitor the resulting records.

The exact current process, available channels, and pain points must be confirmed through client interviews and observation.

## 3. Goals
- Provide residents with a convenient online complaint-submission channel.
- Give each submitted complaint a unique reference number.
- Allow residents to view the status of complaints they submitted through their accounts.
- Maintain a centralized and searchable complaint register for authorized barangay personnel.
- Apply client-approved categories and priority criteria consistently.
- Make complaint assignment and reassignment traceable.
- Show current status and handling history to authorized staff.
- Identify pending and unresolved complaints.
- Provide authorized management with filtered summaries and reports.

## 4. Non-goals for initial release
- Emergency response dispatch or guaranteed emergency response
- AI-generated decisions
- SMS, email, or external messaging notifications
- Live GPS tracking or map-based dispatch
- Native mobile application
- Public access to other residents' complaint records
- Replacement of official barangay records systems

## 5. User roles
### Resident
- Register and maintain an approved resident account
- Log in and submit complaints online
- View the complaints submitted through their own account
- View the status and resident-approved updates of their own complaints
- Cannot view or modify another resident's complaint
- Cannot assign personnel or determine official barangay priority unless the client explicitly approves such a function

### Administrator
- Create, edit, activate, and deactivate user accounts
- Configure categories and approved system settings
- View system-wide records as authorized
- Does not bypass audit/history requirements

### Complaint Officer
- Register and review complaints
- Review complaints submitted online by residents
- Set or confirm category and priority
- Assign and reassign complaints
- Update status where authorized
- Review resolution and close cases if authorized
- Generate reports

### Personnel
- View complaints assigned to them
- Record actions and progress
- Update permitted statuses on assigned complaints
- Submit resolution details for review

### Management
- View authorized dashboard and reports
- Filter records by date, category, priority, and status
- No routine editing of complaint records unless separately authorized

## 6. Functional requirements
Priority: P0 = required for initial working release; P1 = important; P2 = optional/future.

| ID | Requirement | Priority |
|---|---|---|
| FR-001 | Users can log in and log out securely. | P0 |
| FR-002 | System enforces role-based and record-level access on the server. | P0 |
| FR-003 | Authorized staff can register a complaint with required fields. | P0 |
| FR-004 | System generates a unique complaint reference number. | P0 |
| FR-005 | Authorized users can search, filter, view, and edit permitted complaint fields. | P0 |
| FR-006 | Complaints have a category and client-approved priority. | P0 |
| FR-007 | System displays the reason for a priority classification. | P0 |
| FR-008 | Authorized officers can change priority with a required reason and history record. | P0 |
| FR-009 | Officers can assign and reassign complaints to active personnel. | P0 |
| FR-010 | System records assignment history and assignment timestamps. | P0 |
| FR-011 | Assigned personnel can view their assigned complaints. | P0 |
| FR-012 | Authorized users can update status through permitted transitions. | P0 |
| FR-013 | System records status changes, actions, user, and timestamp. | P0 |
| FR-014 | Resolved and closed complaints retain their history. | P0 |
| FR-015 | Dashboard displays counts derived from database records. | P0 |
| FR-016 | Authorized users can filter reports by date, category, priority, and status. | P0 |
| FR-017 | Authorized users can export an approved report format. | P1 |
| FR-018 | System supports restricted complaint attachments. | P1 |
| FR-019 | System flags overdue complaints based on client-approved service targets. | P1 |
| FR-020 | Residents can register and authenticate using resident accounts. | P0 |
| FR-021 | Authenticated residents can submit complaints online and receive a unique reference number. | P0 |
| FR-022 | Authenticated residents can view only their own submitted complaints and permitted status/history information. | P0 |
| FR-023 | The system can retain staff-encoded complaints from approved non-online channels. | P0 |

## 7. Core complaint fields
Required fields must be finalized with the client. Proposed fields:
- Complaint category
- Complaint description
- Received/submitted date/time (system generated)
- Receiving staff, when the complaint is staff-encoded
- Submitting resident account, when submitted online
- Source/channel
- Incident location, if applicable/available
- Priority and reason
- Status
- Complainant/resident details only to the extent approved and necessary

## 8. Business workflow
### Online resident complaint
1. Resident registers/logs in.
2. Resident enters the approved complaint information.
3. System validates the submission and generates a reference number.
4. Complaint is recorded with an initial status and priority state according to the approved implementation rules.
5. Barangay personnel review the complaint.
6. Complaint Officer classifies/confirms priority and assigns personnel according to approved rules.
7. Assigned personnel records actions and updates progress.
8. Authorized staff reviews resolution details.
9. Complaint is closed according to approved policy.
10. Resident can view the permitted status/history of the complaint they submitted.

### Staff-recorded complaint
1. Staff records a complaint received through an approved non-online channel.
2. System validates the entry and generates a reference number.
3. Staff/Officer classifies and prioritizes the complaint.
4. Complaint is assigned and handled through the same workflow.

## 9. Non-functional requirements
### Security
- Passwords are securely hashed.
- All protected operations require server-side role and record-level authorization.
- Database access uses prepared statements.
- State-changing requests are protected against CSRF.
- Output is escaped to reduce XSS risk.
- Deployment uses HTTPS.

### Privacy
- Collect only data required for approved complaint handling and resident account use.
- A resident cannot access another resident's complaint or private staff-only notes.
- Use approved retention, disclosure, and deletion/archival rules.
- Do not use real resident data in testing without authorization and safeguards.

### Reliability and integrity
- Use database transactions for related writes.
- Enforce unique reference numbers and foreign-key integrity.
- Preserve status, priority, assignment, and relevant submission history.
- Establish backups and a tested restoration procedure before production use.

### Usability
- Complaint submission should minimize unnecessary data entry.
- Forms should provide clear labels, validation, and error messages.
- Residents should be able to find their submitted complaints without navigating staff-only pages.
- Responsive layout should support common smartphone and desktop browser sizes.

### Performance
- Establish measurable response-time and concurrent-user targets with the client and hosting environment.
- Use pagination for staff complaint lists and indexes for common filters.

## 10. Acceptance criteria
- An authorized user can log in; invalid credentials are rejected.
- A resident can register/login and submit a valid complaint online.
- A resident receives a unique reference number after successful submission.
- A resident cannot access another resident's complaint by changing a URL or request parameter.
- Staff can receive, review, classify, assign, and handle resident-submitted complaints according to permissions.
- A valid complaint is saved once and receives a unique reference number.
- Required-field validation prevents incomplete records.
- Complaint filters return records matching selected criteria.
- Priority changes require a reason and create a history entry.
- Assignment and reassignment are reflected in assignment history.
- Personnel cannot modify complaints outside their permitted scope.
- Invalid status transitions are rejected by the server.
- Dashboard counts match corresponding database queries.
- Closed records remain available to authorized users with history.
- Test cases pass in a client-approved UAT environment.

## 11. Risks and dependencies
- Client confirmation of workflow, categories, priority criteria, roles, resident fields, and resident-visible information
- Availability of authorized client representatives for review and UAT
- Hosting, internet, equipment, and maintenance arrangements
- Privacy and records-retention approval
- Scope growth from SMS, maps, AI, or other integrations

## 12. Open questions for the client
1. Which channels currently receive complaints?
2. What exact information must be recorded from a resident during online submission?
3. Which complaint categories and responsible offices are official?
4. Who may assign, reassign, prioritize, reject, resolve, close, or reopen complaints?
5. What criteria define each priority level?
6. Are there approved response-time targets and escalation rules?
7. Are anonymous complaints accepted, and if so, can they coexist with resident accounts?
8. Which complaint details and status updates may residents view?
9. Should residents be able to edit or withdraw a complaint after submission?
10. What reports and export formats are required?
11. What are the approved hosting, backup, retention, and maintenance procedures?
