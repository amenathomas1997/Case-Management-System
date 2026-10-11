-- Feature expansion: scheduling, invoicing, templates, documents, assignment, acks, resets
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS scheduled_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  track_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  start_at DATETIME NOT NULL,
  end_at DATETIME NOT NULL,
  location VARCHAR(190) NULL,
  notes VARCHAR(255) NULL,
  status ENUM('Scheduled','Completed','Cancelled','No Show') NOT NULL DEFAULT 'Scheduled',
  work_log_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  track_id INT UNSIGNED NOT NULL,
  invoice_number VARCHAR(40) NOT NULL UNIQUE,
  status ENUM('Draft','Submitted','Paid') NOT NULL DEFAULT 'Draft',
  hours DECIMAL(7,2) NOT NULL DEFAULT 0,
  hours_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  mileage DECIMAL(8,1) NOT NULL DEFAULT 0,
  mileage_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at DATE NULL,
  paid_at DATE NULL,
  FOREIGN KEY (track_id) REFERENCES training_tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE work_logs ADD COLUMN invoice_id INT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS goal_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(80) NOT NULL,
  specific_goal TEXT NOT NULL,
  measurable_metric TEXT NOT NULL,
  focus_area VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS assessment_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  primary_tech ENUM('Screen Reader','Magnification','Braille Display','Speech-to-Text','Other') NOT NULL DEFAULT 'Screen Reader',
  hardware_details VARCHAR(255) NULL,
  software_version VARCHAR(190) NULL,
  summary TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS client_documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  category ENUM('Authorization','IPE','Release','Report','Resource','Other') NOT NULL DEFAULT 'Other',
  title VARCHAR(190) NOT NULL,
  orig_name VARCHAR(190) NOT NULL,
  path VARCHAR(255) NOT NULL,
  uploaded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE clients ADD COLUMN assigned_to INT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS report_acks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  track_id INT UNSIGNED NOT NULL,
  token CHAR(48) NOT NULL UNIQUE,
  sent_to VARCHAR(190) NOT NULL,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  acked_at DATETIME NULL,
  ack_ip VARCHAR(45) NULL,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  used TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed: SMART goal templates
INSERT INTO goal_templates (category, specific_goal, measurable_metric, focus_area) VALUES
('JAWS / Screen Reader', 'Navigate and edit Microsoft Word documents independently using JAWS', 'Client will open, edit, format, and save a Word document at 30 WPM using keyboard shortcuts with no more than 2 verbal prompts', 'Word processing'),
('JAWS / Screen Reader', 'Manage email independently using Outlook with JAWS', 'Client will read, reply to, forward, and file 10 emails with attachments in under 15 minutes unassisted', 'Email productivity'),
('JAWS / Screen Reader', 'Browse and complete web forms using JAWS', 'Client will navigate by headings/landmarks and complete a multi-page online form including dropdowns and checkboxes with 100% accuracy, unassisted', 'Web navigation'),
('JAWS / Screen Reader', 'Use Excel for basic data tasks with JAWS', 'Client will create a worksheet, enter data, use SUM/AVERAGE formulas, and read cell contents/headers accurately in 3 consecutive sessions', 'Spreadsheets'),
('Magnification', 'Configure and use ZoomText/Fusion for daily computer tasks', 'Client will independently adjust magnification level, color enhancement, and pointer settings, and maintain reading speed of 100+ WPM on screen', 'Screen magnification'),
('Magnification', 'Use magnification with dual monitors for work tasks', 'Client will manage windows across two displays and complete a document-comparison task in under 20 minutes with fewer than 2 prompts', 'Workplace productivity'),
('Braille Display', 'Pair and use a refreshable braille display with primary device', 'Client will connect the display via Bluetooth/USB, navigate menus, and read/write documents using braille input in 3 consecutive sessions unassisted', 'Braille technology'),
('Braille Display', 'Use braille display for note-taking in meetings', 'Client will take accurate notes during a 15-minute simulated meeting and retrieve them afterward, unassisted, in 2 consecutive sessions', 'Note-taking'),
('iOS / Mobile', 'Master VoiceOver gestures for daily iPhone use', 'Client will demonstrate flick/rotor navigation, app switching, and text entry at 20 WPM using the on-screen keyboard or braille screen input, unassisted', 'Mobile accessibility'),
('iOS / Mobile', 'Use transportation and navigation apps independently', 'Client will plan and complete a route using transit/rideshare apps including booking and live tracking, with no sighted assistance, twice', 'Community travel'),
('Speech-to-Text', 'Compose documents using Dragon/voice dictation', 'Client will dictate, correct, and format a one-page document with 95% accuracy in under 20 minutes, unassisted, in 2 consecutive sessions', 'Dictation'),
('Windows / General', 'Manage files and folders independently', 'Client will create, rename, move, and back up files across local and cloud storage (OneDrive) with 100% accuracy, unassisted', 'File management'),
('Windows / General', 'Set up and personalize accessibility settings on a new computer', 'Client will independently enable and configure their required accessibility features (narrator/magnifier/contrast/keyboard settings) on a fresh Windows profile', 'Device setup'),
('Employment Skills', 'Complete online job applications with assistive technology', 'Client will search postings, tailor a resume, and submit 2 complete online applications independently using their primary AT', 'Job seeking'),
('Employment Skills', 'Participate in video meetings using assistive technology', 'Client will join a Teams/Zoom meeting, manage audio/video and chat, and share a document, unassisted, in 2 consecutive sessions', 'Remote work');

-- Seed: assessment templates
INSERT INTO assessment_templates (name, primary_tech, hardware_details, software_version, summary) VALUES
('JAWS workstation assessment', 'Screen Reader', 'Windows laptop, 16GB RAM, external keyboard', 'JAWS (current version)', "Areas evaluated:\n- Current typing/keyboard proficiency\n- Screen reader experience level\n- Primary computer tasks required (work/school/daily living)\n- Recommended training hours and focus areas\n\nFindings:\n\nRecommendations:"),
('Low vision / magnification assessment', 'Magnification', 'Windows laptop, 24in external monitor', 'ZoomText / Fusion (current version)', "Areas evaluated:\n- Functional visual acuity at screen\n- Preferred magnification level and color settings\n- Lighting and ergonomic needs\n- Task endurance and fatigue factors\n\nFindings:\n\nRecommendations:"),
('Braille technology assessment', 'Braille Display', 'Refreshable braille display (40-cell), paired device', 'Device firmware + screen reader (current)', "Areas evaluated:\n- Braille reading fluency (grade 1/2/UEB)\n- Device pairing and navigation skills\n- Note-taking and document workflows\n\nFindings:\n\nRecommendations:"),
('iOS accessibility assessment', 'Other', 'iPhone (client device)', 'iOS (current version), VoiceOver/Zoom', "Areas evaluated:\n- Current gesture proficiency\n- Communication, navigation, and productivity app needs\n- Braille screen input / external keyboard needs\n\nFindings:\n\nRecommendations:");
