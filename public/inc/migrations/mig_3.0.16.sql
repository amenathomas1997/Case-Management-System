-- Workflow and AI quality features. Additive and safe to run again.
ALTER TABLE ai_usage ADD COLUMN IF NOT EXISTS succeeded tinyint(1) DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS duration_ms int unsigned DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS truncated tinyint(1) NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS ai_feedback (
 id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
 usage_id int unsigned NOT NULL,
 user_id int unsigned NOT NULL,
 rating varchar(24) NOT NULL,
 comment varchar(1000) NOT NULL DEFAULT '',
 created_at datetime NOT NULL DEFAULT current_timestamp(),
 UNIQUE KEY usage_user (usage_id,user_id),
 CONSTRAINT fk_feedback_usage FOREIGN KEY (usage_id) REFERENCES ai_usage(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS referral_waitlist (
 referral_id int unsigned NOT NULL PRIMARY KEY,
 status varchar(24) NOT NULL DEFAULT 'Waiting',
 expertise varchar(500) NOT NULL DEFAULT '',
 languages varchar(190) NOT NULL DEFAULT '',
 areas varchar(190) NOT NULL DEFAULT '',
 modality varchar(24) NOT NULL DEFAULT 'Both',
 availability varchar(1000) NOT NULL DEFAULT '',
 followup_date date DEFAULT NULL,
 version int unsigned NOT NULL DEFAULT 1,
 created_at datetime NOT NULL DEFAULT current_timestamp(),
 updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 CONSTRAINT fk_waitlist_referral FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS homework_practice (
 id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
 homework_id int unsigned NOT NULL,
 minutes smallint unsigned NOT NULL,
 difficulty varchar(24) NOT NULL,
 notes varchar(2000) NOT NULL DEFAULT '',
 request_key char(32) NOT NULL,
 created_at datetime NOT NULL DEFAULT current_timestamp(),
 UNIQUE KEY practice_request (request_key),
 KEY homework_date (homework_id,created_at),
 CONSTRAINT fk_practice_homework FOREIGN KEY (homework_id) REFERENCES homework(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS documentation_reviews (
 note_id int unsigned NOT NULL PRIMARY KEY,
 fingerprint char(64) NOT NULL,
 reviewer_id int unsigned NOT NULL,
 rationale varchar(1000) NOT NULL,
 reviewed_at datetime NOT NULL DEFAULT current_timestamp(),
 CONSTRAINT fk_review_note FOREIGN KEY (note_id) REFERENCES client_notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
