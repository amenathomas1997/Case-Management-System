-- Feature expansion v4: equipment, homework, expenses, TOTP, capacity
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS equipment (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  category ENUM('Braille Display','Screen Reader License','Magnifier','Tablet/Phone','Computer','Keyboard/Input','Audio','Other') NOT NULL DEFAULT 'Other',
  serial VARCHAR(120) NULL,
  item_condition ENUM('New','Good','Fair','Needs Repair') NOT NULL DEFAULT 'Good',
  value DECIMAL(9,2) NULL,
  notes VARCHAR(255) NULL,
  status ENUM('Available','On Loan','Repair','Retired') NOT NULL DEFAULT 'Available',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS equipment_loans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  equipment_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  loaned_at DATE NOT NULL,
  due_date DATE NULL,
  returned_at DATE NULL,
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS homework (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  track_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  instructions TEXT NULL,
  assigned_at DATE NOT NULL,
  due_date DATE NULL,
  status ENUM('Assigned','Completed') NOT NULL DEFAULT 'Assigned',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (track_id) REFERENCES training_tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS track_expenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  track_id INT UNSIGNED NOT NULL,
  expense_date DATE NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(9,2) NOT NULL,
  is_billed TINYINT(1) NOT NULL DEFAULT 0,
  invoice_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (track_id) REFERENCES training_tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE invoices ADD COLUMN expenses_amount DECIMAL(10,2) NOT NULL DEFAULT 0;

ALTER TABLE users
  ADD COLUMN totp_secret VARCHAR(64) NULL,
  ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN weekly_hours DECIMAL(5,2) NOT NULL DEFAULT 25;
