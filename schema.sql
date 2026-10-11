-- Example Agency — Case Management System schema (MySQL 8 / MariaDB, utf8mb4)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','instructor') NOT NULL DEFAULT 'instructor',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_otps (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  consumed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  email VARCHAR(190) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip_time (ip, attempted_at),
  KEY idx_email_time (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS referrals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reason VARCHAR(255) NOT NULL,
  note_type ENUM('Initial Intake','Tech Assessment Request','Plan Amendment','Other') NOT NULL DEFAULT 'Initial Intake',
  subject VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  counselor_name VARCHAR(120) NOT NULL,
  counselor_agency VARCHAR(190) NOT NULL,
  counselor_email VARCHAR(190) NOT NULL,
  counselor_phone VARCHAR(40) NOT NULL,
  client_name VARCHAR(120) NOT NULL,
  client_dob DATE NULL,
  client_phone VARCHAR(40) NULL,
  client_email VARCHAR(190) NULL,
  client_address VARCHAR(255) NULL,
  status ENUM('New','Reviewed','Converted') NOT NULL DEFAULT 'New',
  assigned_to INT UNSIGNED NULL,
  client_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS clients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  dob DATE NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  address VARCHAR(255) NULL,
  status ENUM('Pending Assessment','Active Training','On Hold','Closed - Rehabilitated','Closed - Other') NOT NULL DEFAULT 'Pending Assessment',
  counselor_name VARCHAR(120) NULL,
  counselor_email VARCHAR(190) NULL,
  counselor_phone VARCHAR(40) NULL,
  counselor_agency VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS client_status_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  old_status VARCHAR(40) NULL,
  new_status VARCHAR(40) NOT NULL,
  changed_by INT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS client_notes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  note_type ENUM('Case Note','Phone Call','Progress Report','Monthly Summary','Intake') NOT NULL DEFAULT 'Case Note',
  subject VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  author_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  updated_by INT UNSIGNED NULL,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tech_assessments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  assessment_date DATE NOT NULL,
  primary_disability VARCHAR(190) NULL,
  primary_tech ENUM('Screen Reader','Magnification','Braille Display','Speech-to-Text','Other') NOT NULL DEFAULT 'Screen Reader',
  hardware_details VARCHAR(255) NULL,
  software_version VARCHAR(190) NULL,
  summary TEXT NULL,
  attachment_path VARCHAR(255) NULL,
  attachment_name VARCHAR(190) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS training_tracks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  status ENUM('Active','Completed','Cancelled') NOT NULL DEFAULT 'Active',
  auth_number VARCHAR(80) NOT NULL,
  funding_agency VARCHAR(190) NOT NULL DEFAULT 'State VR',
  authorized_hours DECIMAL(7,2) NOT NULL DEFAULT 0,
  hourly_rate DECIMAL(8,2) NOT NULL DEFAULT 95.00,
  mileage_rate DECIMAL(6,3) NOT NULL DEFAULT 0,
  start_date DATE NULL,
  expiration_date DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS smart_goals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  track_id INT UNSIGNED NOT NULL,
  specific_goal TEXT NOT NULL,
  measurable_metric TEXT NOT NULL,
  focus_area VARCHAR(190) NULL,
  status ENUM('Not Started','In Progress','Achieved') NOT NULL DEFAULT 'Not Started',
  target_date DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (track_id) REFERENCES training_tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sent_alerts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  track_id INT UNSIGNED NOT NULL,
  alert_key VARCHAR(20) NOT NULL,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_track_alert (track_id, alert_key),
  FOREIGN KEY (track_id) REFERENCES training_tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS work_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  track_id INT UNSIGNED NOT NULL,
  work_date DATE NOT NULL,
  hours DECIMAL(5,2) NOT NULL,
  mileage DECIMAL(6,1) NOT NULL DEFAULT 0,
  description TEXT NOT NULL,
  is_billed TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (track_id) REFERENCES training_tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
