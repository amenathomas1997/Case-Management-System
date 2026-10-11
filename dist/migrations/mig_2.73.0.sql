-- AT service workflows. Safe to rerun; no existing records are rewritten.
CREATE TABLE IF NOT EXISTS at_records (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 kind VARCHAR(32) NOT NULL,
 client_id INT UNSIGNED NULL,
 track_id INT UNSIGNED NULL,
 equipment_id INT UNSIGNED NULL,
 staff_id INT UNSIGNED NULL,
 parent_id INT UNSIGNED NULL,
 title VARCHAR(190) NOT NULL,
 status VARCHAR(40) NOT NULL,
 due_date DATE NULL,
 details LONGTEXT NOT NULL,
 source_key VARCHAR(120) NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_by INT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY at_source (source_key),
 KEY at_kind_due (kind, status, due_date),
 KEY at_client (client_id),
 CONSTRAINT at_client_fk FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
 CONSTRAINT at_track_fk FOREIGN KEY (track_id) REFERENCES training_tracks(id) ON DELETE SET NULL,
 CONSTRAINT at_equipment_fk FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE SET NULL,
 CONSTRAINT at_staff_fk FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT at_parent_fk FOREIGN KEY (parent_id) REFERENCES at_records(id) ON DELETE CASCADE,
 CONSTRAINT at_details_json CHECK (JSON_VALID(details))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS at_reminders (
 record_id INT UNSIGNED NOT NULL,
 due_date DATE NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 PRIMARY KEY (record_id, due_date, user_id),
 CONSTRAINT at_reminder_record_fk FOREIGN KEY (record_id) REFERENCES at_records(id) ON DELETE CASCADE,
 CONSTRAINT at_reminder_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
