# System Architecture
## Barangay Complaint Prioritization and Routing System
**Client:** Barangay Santolan, Pasig City (proposed client; confirm requirements with client)  
**Status:** Initial development blueprint — resident-facing submission included; operational rules remain subject to client validation

## 1. Purpose
Describe the proposed software architecture for a web-based system that allows residents to submit complaints online without personally visiting the barangay office, while enabling authorized barangay personnel to receive, classify, prioritize, route, monitor, resolve, and report those complaints.

The system may also record complaints received through other approved channels, such as walk-in, phone, or referral, when barangay staff need to encode them into the centralized record.

## 2. Scope
Core capabilities:
- Resident account registration, login, and access to the resident's own complaints
- Online complaint submission and reference-number generation
- Staff authentication and role-based access
- Complaint registration and records management
- Complaint category and priority classification
- Personnel assignment and reassignment
- Status and action-history tracking
- Resident status viewing for complaints submitted through the resident account
- Dashboard and reports

Out of initial scope unless approved:
- SMS/email gateway or external notification integration
- AI classification or automated decision-making
- Emergency dispatch
- GPS/live map services
- Native mobile application
- Public access to complaint records

## 3. Architecture style
Use a conventional client-server web application with a modular PHP backend and relational MySQL database. Render server-side pages and use JavaScript for interactive elements.

```text
[Resident Browser]                  [Barangay Staff Browser]
       |                                      |
       +------------------+-------------------+
                          |
                          | HTTPS (deployed) / HTTP (local development)
                          v
                  [Apache Web Server]
                          |
                          v
                   [PHP Application]
       | Authentication & Authorization       |
       | Resident Portal                      |
       | Complaint Management                  |
       | Classification & Priority Rules       |
       | Assignment & Routing                  |
       | Status/History                        |
       | Dashboard & Reports                   |
                          |
                          | PDO prepared statements
                          v
                   [MySQL Database]

Optional file uploads --> [Restricted file storage]
```

The same application and database serve both sides, but authorization determines which records and actions each user can access.

## 4. Technology baseline
- Frontend: HTML5, CSS3, Bootstrap 5, JavaScript
- Backend: PHP 8.x (use a currently supported release available in the deployment environment)
- Database: MySQL 8.x or compatible supported version
- Local development: XAMPP or equivalent Apache/PHP/MySQL environment
- Version control: Git
- Charts: Chart.js, if required
- PDF/CSV exports: choose a maintained PHP library or use CSV and browser print as the first release

Confirm actual versions and hosting compatibility before deployment.

## 5. Application layers
### Presentation layer
Responsibilities:
- Render resident forms, resident complaint lists, staff pages, tables, filters, and dashboards
- Show validation messages and role-appropriate navigation
- Show residents only their own complaint information
- Never serve as the sole enforcement point for authorization

### Application layer
Responsibilities:
- Validate and normalize input
- Enforce role and record-level permissions
- Enforce workflow transitions
- Apply priority rules and routing suggestions
- Use database transactions for multi-step operations
- Record auditable status, priority, assignment, and consequential account changes

### Data layer
Responsibilities:
- Persist normalized records and relationships
- Link resident accounts to complaints they submitted
- Enforce primary keys, foreign keys, unique constraints, and appropriate indexes
- Provide data through parameterized queries

## 6. Suggested project structure
```text
barangay-complaint-system/
├── public/                 # Web document root
│   ├── index.php
│   ├── login.php
│   ├── register.php        # Resident registration
│   ├── logout.php
│   ├── dashboard.php       # Role-appropriate dashboard
│   ├── resident/
│   │   ├── complaints/
│   │   └── profile/
│   ├── complaints/
│   ├── assignments/
│   ├── personnel/
│   ├── categories/
│   ├── reports/
│   └── assets/
├── app/
│   ├── Controllers/
│   ├── Services/
│   ├── Repositories/
│   ├── Middleware/
│   └── Views/
├── config/
│   └── database.php
├── storage/
│   └── uploads/            # Outside public web root; deny executable files
├── database/
│   ├── schema.sql
│   └── seed.sql
├── tests/
└── README.md
```
This is a suggested organization. A simpler procedural PHP structure may be used for a student prototype, provided shared authentication, database, validation, and authorization code are centralized.

## 7. Core modules
1. Authentication and account administration
2. Resident registration and resident portal
3. Complaint submission and record management
4. Category management
5. Priority assessment and priority history
6. Personnel assignment and routing
7. Status tracking and action history
8. Dashboard and reporting
9. Audit and security controls

## 8. Main request flows
### Resident registration and login
1. Resident creates an account using the approved registration fields.
2. Server validates the account data and creates a Resident user account.
3. Password is stored only as a password hash.
4. After login, the resident is directed to the resident-facing area.

### Resident complaint submission
1. Authenticated resident opens the complaint form.
2. Server identifies the resident from the authenticated session; the resident cannot submit on behalf of another user by changing an ID.
3. Resident enters the approved complaint details.
4. Server validates the submission and generates a unique reference number.
5. Complaint, resident/complainant linkage, initial status history, and initial priority information are recorded in one transaction.
6. The resident receives a confirmation page containing the reference number.
7. The complaint becomes available to authorized barangay personnel for review and handling.

### Staff-recorded complaint
1. Authorized staff opens the complaint form.
2. Server validates required fields and user permissions.
3. Server creates the complaint reference number and complaint record.
4. Initial status and priority assessment are recorded.
5. Transaction commits; staff sees the reference number.

This path remains useful for approved non-online channels such as walk-in, phone, or referral.

### Assign complaint
1. Officer selects complaint and eligible personnel.
2. Server validates assignment permission and personnel account status.
3. Existing assignment is closed, if applicable.
4. New assignment and history entry are created in one transaction.
5. Complaint status is updated according to the approved workflow.

### Update complaint
1. Assigned personnel or authorized officer opens the record.
2. Server checks role and assignment scope.
3. Valid status transition and required action notes are checked.
4. Complaint and history are updated atomically.
5. Authorized resident views only the resulting status/history information that is approved for resident disclosure.

## 9. Security and privacy architecture
- Use PHP password hashing APIs; never store plaintext passwords.
- Use PDO prepared statements for database access.
- Use server-side role and record-level authorization on every protected action.
- A resident may access only complaints linked to that resident account.
- Staff access must follow the approved role and complaint-handling scope.
- Use CSRF tokens for state-changing forms.
- Escape output to mitigate cross-site scripting.
- Validate uploads by type, size, extension, and content; store outside public web root.
- Use HTTPS in deployment; secure session cookies and regenerate session ID after login.
- Keep credentials outside source control.
- Limit collection and display of personal data to approved operational needs.
- Define backup, retention, access, disclosure, and incident-response procedures with the client.
- Keep logs free of passwords, session tokens, and unnecessary sensitive complaint details.

## 10. Deployment environments
- Development: local machine, synthetic/test records
- UAT: client-approved test environment and authorized test users
- Production: client-approved hosting, HTTPS, least-privilege database account, scheduled backups, and documented recovery procedure

Do not use actual resident data in development without written authorization and approved safeguards.

## 11. Assumptions and decisions requiring client confirmation
- Official complaint categories and responsible offices
- Priority levels, definitions, and escalation criteria
- Staff roles and who may create, assign, reassign, close, or reopen cases
- Required resident registration and complaint fields
- Whether anonymous complaints are allowed; anonymous submissions are separate from authenticated resident accounts
- Which complaint details residents may view after submission
- Official status names and permitted transitions
- Response-time targets and definition of overdue
- Retention, privacy notice, access, disclosure, and report-export rules
- Hosting, connectivity, backup, and maintenance arrangements
