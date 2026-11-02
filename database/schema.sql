CREATE DATABASE IF NOT EXISTS barangay_complaints
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE barangay_complaints;

CREATE TABLE roles (
  role_id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_name VARCHAR(40) NOT NULL UNIQUE,
  description VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE users (
  user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id TINYINT UNSIGNED NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(role_id)
) ENGINE=InnoDB;

CREATE TABLE complainants (
  complainant_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL UNIQUE,
  full_name VARCHAR(150) NULL,
  contact_number VARCHAR(30) NULL,
  address VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_complainants_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE categories (
  category_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_name VARCHAR(100) NOT NULL UNIQUE,
  description TEXT NULL,
  default_priority ENUM('Critical','High','Medium','Low') NULL,
  default_assignee_id INT UNSIGNED NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_categories_default_assignee
    FOREIGN KEY (default_assignee_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE complaints (
  complaint_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference_no VARCHAR(32) NOT NULL UNIQUE,
  complainant_id INT UNSIGNED NULL,
  submitted_by_user_id INT UNSIGNED NULL,
  category_id INT UNSIGNED NOT NULL,
  received_by INT UNSIGNED NULL,
  description TEXT NOT NULL,
  incident_location VARCHAR(255) NULL,
  incident_at DATETIME NULL,
  source ENUM('Walk-in','Phone','Online','Referral','Other') NOT NULL DEFAULT 'Walk-in',
  severity ENUM('Low','Moderate','Severe','Critical') NOT NULL DEFAULT 'Low',
  affected_residents INT UNSIGNED NOT NULL DEFAULT 0,
  immediate_danger BOOLEAN NOT NULL DEFAULT FALSE,
  community_impact ENUM('Low','Medium','High') NOT NULL DEFAULT 'Low',
  location_type ENUM('Residential','Public Facility','Roadway','Utility','Other') NOT NULL DEFAULT 'Other',
  priority_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  predicted_priority ENUM('Critical','High','Medium','Low') NULL,
  priority_confirmed BOOLEAN NOT NULL DEFAULT FALSE,
  priority_reviewed_by INT UNSIGNED NULL,
  priority_reviewed_at DATETIME NULL,
  priority ENUM('Critical','High','Medium','Low') NOT NULL,
  priority_reason VARCHAR(500) NOT NULL,
  status ENUM('Received','For Assignment','Assigned','In Progress',
              'On Hold','Resolved','Closed','Rejected / Invalid')
         NOT NULL DEFAULT 'Received',
  rejection_reason VARCHAR(500) NULL,
  resolution_details TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  closed_at DATETIME NULL,
  CONSTRAINT fk_complaints_complainant
    FOREIGN KEY (complainant_id) REFERENCES complainants(complainant_id),
  CONSTRAINT fk_complaints_submitted_by
    FOREIGN KEY (submitted_by_user_id) REFERENCES users(user_id),
  CONSTRAINT fk_complaints_category
    FOREIGN KEY (category_id) REFERENCES categories(category_id),
  CONSTRAINT fk_complaints_received_by
    FOREIGN KEY (received_by) REFERENCES users(user_id),
  CONSTRAINT fk_complaints_priority_reviewer
    FOREIGN KEY (priority_reviewed_by) REFERENCES users(user_id),
  INDEX idx_complaints_status (status),
  INDEX idx_complaints_priority (priority),
  INDEX idx_complaints_created (created_at),
  INDEX idx_complaints_category (category_id),
  INDEX idx_complaints_submitted_by (submitted_by_user_id),
  INDEX idx_complaints_source (source)
) ENGINE=InnoDB;

CREATE TABLE assignments (
  assignment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  complaint_id BIGINT UNSIGNED NOT NULL,
  assigned_to INT UNSIGNED NOT NULL,
  assigned_by INT UNSIGNED NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  assignment_notes TEXT NULL,
  unassigned_at DATETIME NULL,
  CONSTRAINT fk_assignments_complaint
    FOREIGN KEY (complaint_id) REFERENCES complaints(complaint_id),
  CONSTRAINT fk_assignments_assigned_to
    FOREIGN KEY (assigned_to) REFERENCES users(user_id),
  CONSTRAINT fk_assignments_assigned_by
    FOREIGN KEY (assigned_by) REFERENCES users(user_id),
  INDEX idx_assignments_complaint (complaint_id),
  INDEX idx_assignments_assignee_active (assigned_to, unassigned_at)
) ENGINE=InnoDB;

CREATE TABLE status_history (
  status_history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  complaint_id BIGINT UNSIGNED NOT NULL,
  changed_by INT UNSIGNED NOT NULL,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NOT NULL,
  action_taken TEXT NULL,
  remarks TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_status_history_complaint
    FOREIGN KEY (complaint_id) REFERENCES complaints(complaint_id),
  CONSTRAINT fk_status_history_user
    FOREIGN KEY (changed_by) REFERENCES users(user_id),
  INDEX idx_status_history_complaint_date (complaint_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE priority_history (
  priority_history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  complaint_id BIGINT UNSIGNED NOT NULL,
  changed_by INT UNSIGNED NOT NULL,
  old_priority ENUM('Critical','High','Medium','Low') NULL,
  new_priority ENUM('Critical','High','Medium','Low') NOT NULL,
  reason VARCHAR(500) NOT NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_priority_history_complaint
    FOREIGN KEY (complaint_id) REFERENCES complaints(complaint_id),
  CONSTRAINT fk_priority_history_user
    FOREIGN KEY (changed_by) REFERENCES users(user_id),
  INDEX idx_priority_history_complaint_date (complaint_id, changed_at)
) ENGINE=InnoDB;

CREATE TABLE complaint_attachments (
  attachment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  complaint_id BIGINT UNSIGNED NOT NULL,
  uploaded_by INT UNSIGNED NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  stored_filename VARCHAR(255) NOT NULL UNIQUE,
  mime_type VARCHAR(100) NOT NULL,
  file_size_bytes BIGINT UNSIGNED NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_attachments_complaint
    FOREIGN KEY (complaint_id) REFERENCES complaints(complaint_id),
  CONSTRAINT fk_attachments_user
    FOREIGN KEY (uploaded_by) REFERENCES users(user_id)
) ENGINE=InnoDB;
