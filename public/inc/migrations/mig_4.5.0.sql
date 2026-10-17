-- 4.5.0: the lists once held in database enum columns become text columns, so an
-- administrator can add choices on Lists and time limits. Every existing value is kept.
-- Requires MariaDB 10.4 or newer. Safe to run again.

ALTER TABLE `clients` MODIFY `client_type` varchar(60) NOT NULL DEFAULT 'VR';
ALTER TABLE `service_bundles` MODIFY `client_type` varchar(60) NOT NULL DEFAULT 'Any';
ALTER TABLE `training_tracks` MODIFY `service_type` varchar(60) NOT NULL DEFAULT 'Assistive Technology';
ALTER TABLE `client_notes` MODIFY `note_type` varchar(60) NOT NULL DEFAULT 'Case Note';
ALTER TABLE `client_documents` MODIFY `category` varchar(60) NOT NULL DEFAULT 'Other';
ALTER TABLE `assessment_templates` MODIFY `primary_tech` varchar(60) NOT NULL DEFAULT 'Screen Reader';
ALTER TABLE `tech_assessments` MODIFY `primary_tech` varchar(60) NOT NULL DEFAULT 'Screen Reader';
ALTER TABLE `equipment` MODIFY `category` varchar(60) NOT NULL DEFAULT 'Other', MODIFY `item_condition` varchar(60) NOT NULL DEFAULT 'Good';
ALTER TABLE `equipment_maintenance` MODIFY `service_type` varchar(60) NOT NULL DEFAULT 'Maintenance';
ALTER TABLE `equipment_loans` MODIFY `condition_out` varchar(60) DEFAULT NULL, MODIFY `condition_in` varchar(60) DEFAULT NULL;

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.5.0') ON DUPLICATE KEY UPDATE `value` = '4.5.0';
