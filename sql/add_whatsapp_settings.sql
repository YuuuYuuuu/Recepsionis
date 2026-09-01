-- This migration uses INFORMATION_SCHEMA to be compatible with older MySQL versions
-- It will add the `whatsapp_sent` column to `staff_calls` only if it does not already exist
-- and then insert default `wa_*` settings (or update if they exist).

-- 1) Add column if not exists (compatible method)
-- If the table did not exist, create it now (safe create with minimal schema)
CREATE TABLE IF NOT EXISTS `recepsionis_db`.`staff_calls` (
	`id` INT AUTO_INCREMENT PRIMARY KEY,
	`visitor_name` VARCHAR(200) NOT NULL,
	`visitor_phone` VARCHAR(50) NOT NULL,
	`host_id` INT NULL,
	`call_type` VARCHAR(50) DEFAULT 'general',
	`message` TEXT,
	`status` ENUM('pending','answered','cancelled') DEFAULT 'pending',
	`answered_by` INT NULL,
	`answered_at` TIMESTAMP NULL DEFAULT NULL,
	`whatsapp_sent` TINYINT(1) DEFAULT 0,
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	`updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1) Add column if not exists (compatible method)
DELIMITER $$
DROP PROCEDURE IF EXISTS add_whatsapp_column$$
CREATE PROCEDURE add_whatsapp_column()
BEGIN
	IF NOT EXISTS (
		SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
		WHERE TABLE_SCHEMA = 'recepsionis_db'
			AND TABLE_NAME = 'staff_calls'
			AND COLUMN_NAME = 'whatsapp_sent'
	) THEN
		ALTER TABLE `recepsionis_db`.`staff_calls` ADD COLUMN whatsapp_sent TINYINT(1) DEFAULT 0;
	END IF;
END$$
CALL add_whatsapp_column()$$
DROP PROCEDURE IF EXISTS add_whatsapp_column$$
DELIMITER ;

-- 2) Insert default settings (safe: ON DUPLICATE KEY UPDATE)
INSERT INTO settings (setting_key, setting_value)
VALUES
	('wa_enabled', '0'),
	('wa_api_url', 'https://whatsapp.cloudify.id/api'),
	('wa_api_token', ''),
	('wa_session_id', ''),
	('wa_admin_phones', '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
