# UI/UX Design Specification
## Barangay Complaint Prioritization and Routing System

**Status:** Initial design direction; resident-facing workflow included. Validate with client and representative users.

## 1. Design goals
- Make online complaint submission simple for residents who should not need to visit the barangay office merely to file a complaint.
- Make frequent staff tasks easy: receive, find, assign, update, and close a complaint.
- Use clear labels and consistent status/priority indicators.
- Keep sensitive complainant information and staff-only notes visible only to authorized roles.
- Prevent residents from seeing other residents' complaints.
- Support desktop, tablet, and common smartphone layouts.
- Avoid visual clutter and unnecessary steps.

## 2. Visual direction
Use a professional, civic-service style:
- Neutral background with white or light content surfaces
- Dark text
- One restrained primary accent color
- Consistent spacing, typography, borders, and button styles
- Use color plus text labels/icons; never communicate priority by color alone

Colors are design suggestions, not official Barangay Santolan branding. Use approved logos, seals, and colors only with permission.

## 3. Global layout
### Resident layout
```text
+--------------------------------------------------------------+
| Barangay Complaint System                  Resident / Logout |
+--------------------------------------------------------------+
| Home / Dashboard | My Complaints | Submit Complaint | Profile|
+--------------------------------------------------------------+
| Page title                                      Primary action|
|--------------------------------------------------------------|
| Main content: cards, complaint list, or submission form     |
+--------------------------------------------------------------+
```

### Staff layout
```text
+--------------------------------------------------------------+
| Barangay Complaint System             User / Role / Logout   |
+----------------------+---------------------------------------+
| Sidebar              | Page title               Primary action|
| - Dashboard          |---------------------------------------|
| - Complaints         | Filters / Search                      |
| - Assignments        |                                       |
| - Personnel*         | Main content: cards, table, or form   |
| - Categories*        |                                       |
| - Reports            |                                       |
| - Settings*          |                                       |
+----------------------+---------------------------------------+
*Visible only to authorized roles
```

## 4. Navigation by role
- Resident: Dashboard/My Complaints, Submit Complaint, Profile, Logout
- Administrator: Dashboard, Complaints, Assignments, Personnel, Categories, Reports, Settings
- Complaint Officer: Dashboard, Complaints, Assignments, Reports
- Personnel: My Assignments, Complaint Details, permitted status/action updates
- Management: Dashboard, Reports, read-only complaint views as approved

Navigation hiding is for usability only. Backend authorization must still protect every route and operation.

## 5. Page specifications
### Resident registration
Elements should be limited to client-approved account fields, for example:
- Full name
- Contact information, if required
- Address, if required
- Username/email, according to the chosen authentication design
- Password
- Password confirmation
- Terms/privacy notice if approved

Do not collect additional personal information merely for convenience.

### Resident login
Elements:
- Username/email according to the approved authentication design
- Password
- Sign-in button
- Generic authentication error message
- Registration link for new residents, if enabled

### Resident dashboard / My Complaints
Show:
- Count of submitted complaints, if useful
- Recent submitted complaints
- Reference number
- Category
- Submitted date
- Current status
- Current priority only if the client approves exposing priority to residents

The list must be filtered by the authenticated resident's user ID on the server.

### Resident complaint submission
Keep the form short and organized:
1. Complaint category
2. Description of the complaint
3. Incident location
4. Incident date/time, if applicable
5. Supporting attachment, if enabled and approved
6. Any other client-approved required information

The resident's identity should come from the authenticated account rather than asking the resident to select another user.

Before submission:
- Show a concise review/confirmation step if useful.
- Prevent accidental duplicate submission.
- Display validation errors beside the relevant fields.

After submission:
- Show a success message.
- Show the generated reference number.
- Provide a link to the resident's complaint details.

### Resident complaint details
Display only information approved for resident access:
- Reference number
- Submission date/time
- Category
- Complaint description
- Incident location/date where appropriate
- Current status
- Resident-visible timeline/status updates
- Resolution summary when approved for disclosure

Do not display:
- Internal staff-only notes
- Other residents' information
- Internal routing/security information unless approved
- Private audit details

### Staff dashboard
- Summary cards: total received, awaiting assignment, in progress, unresolved, resolved/closed
- Priority summary
- Complaint volume by category or period
- Recent complaint table
- Filters for date range where useful
- Every count must be derived from the database, not hard-coded

### Complaint list
- Search by reference number and approved searchable fields
- Filters: date range, category, priority, status, assignee, source
- Paginated table
- Columns: reference, received/submitted date, category, priority, status, assignee (if authorized), source, actions
- Provide empty, loading, and error states

### Staff complaint form
For staff-encoded complaints, group fields as:
1. Complainant information (only approved fields)
2. Complaint details
3. Source/channel
4. Priority assessment
5. Submit/cancel

For complaints already submitted by a resident, staff should review and edit only fields permitted by role and workflow.

### Complaint details for staff
Display:
- Reference/status/priority
- Complaint details
- Resident/complainant information according to role permissions
- Assignment
- Timeline of status, priority, and assignment events
- Action/update form
- Resolution/closure controls for authorized roles

### Assignments
- Officer view: active assignments and relevant complaint information
- Personnel view: only assigned cases within their permissions
- Show assignment date, complaint reference, priority, status, and action link

### Personnel and category management
- Tables with search and active/inactive state
- Forms with server-side validation
- Confirm before deactivating accounts or categories
- Prevent deactivation that would leave records without a valid handling path; require reassignment or officer review

### Reports
- Date range and supported filters
- Clearly label report scope and generation date
- Summary totals and tabular detail
- Export only data the user is authorized to access
- Protect exports from spreadsheet formula injection where relevant

## 6. Status and priority presentation
Use consistent text badges:
- Critical — danger styling + “Critical”
- High — warning styling + “High”
- Medium — informational styling + “Medium”
- Low — neutral styling + “Low”

Status badges should display the full status text. Include accessible contrast and do not rely on color alone.

For residents, expose only the status/priority information approved by the client.

## 7. Interaction and validation rules
- Ask for confirmation before irreversible or consequential actions.
- Require reasons for rejection, priority override, reassignment, and reopening where applicable.
- Show success or failure feedback after server response.
- Preserve form input when validation fails where safe.
- Prevent duplicate complaint submission.
- Use pagination and filters rather than loading all records.
- Provide accessible labels, keyboard focus, and readable error messages.
- Do not rely on hidden navigation links as the security mechanism.

## 8. Responsive behavior
- Desktop: persistent staff sidebar and full data table; resident navigation may use a compact header.
- Tablet: collapsible navigation and horizontally scrollable tables where necessary.
- Mobile: stacked forms/cards, collapsible navigation, and simplified table presentation.
- Test resident complaint submission and complaint-status viewing at small viewport widths before UAT.

## 9. Design assets
Create or obtain only approved assets:
- Barangay seal/logo (client-provided and authorized)
- Favicon
- Status/priority icons
- Optional charts

Do not represent a draft mockup or sample data as actual barangay records.
