-- Roles (SCHEMA.md §4). No administrator row here: it is created by
-- database/create_admin.php so the password is hashed with password_hash().
INSERT INTO roles (role_name, description) VALUES
('Resident', 'Submits complaints online and views own complaint status'),
('Administrator', 'Manages accounts and system configuration'),
('Complaint Officer', 'Registers, reviews, assigns, and closes complaints'),
('Personnel', 'Handles complaints assigned to them'),
('Management', 'Views authorized dashboards and reports');
