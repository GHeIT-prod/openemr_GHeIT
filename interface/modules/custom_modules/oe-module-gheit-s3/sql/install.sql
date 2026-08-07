-- oe-module-gheit-s3 :: sql/install.sql
-- Run once when installing the module (also mirrored as a versioned
-- patch if you fold this into core's sql/patch.sql / sql/database.sql).

CREATE TABLE IF NOT EXISTS `file_storage` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `uuid` binary(16) DEFAULT NULL,
  `storage_provider` varchar(16) NOT NULL DEFAULT 's3',
  `storage_bucket` varchar(63) NOT NULL,
  `storage_key` varchar(512) DEFAULT NULL,
  `storage_version_id` varchar(1024) DEFAULT NULL,
  `original_filename` varchar(255) NOT NULL,
  `mime_type` varchar(255) NOT NULL,
  `file_size` bigint(20) NOT NULL,
  `checksum_sha256` char(64) DEFAULT NULL,
  `storage_status` varchar(32) NOT NULL DEFAULT 'pending' COMMENT 'pending, uploaded, failed, deleting, deleted, unavailable',
  `scan_status` varchar(32) NOT NULL DEFAULT 'pending' COMMENT 'pending, clean, infected, failed',
  `created_by` bigint(20) DEFAULT NULL COMMENT 'fk to users.id',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `parent_file_id` bigint(20) DEFAULT NULL COMMENT 'fk to file_storage.id for thumbnails and other derivatives',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unq_file_storage_uuid` (`uuid`),
  UNIQUE KEY `unq_file_storage_object` (`storage_provider`, `storage_bucket`, `storage_key`),
  KEY `idx_file_storage_status` (`storage_status`, `scan_status`),
  KEY `idx_file_storage_created_by` (`created_by`),
  KEY `idx_file_storage_deleted_at` (`deleted_at`),
  KEY `idx_file_storage_parent_file_id` (`parent_file_id`)
) ENGINE=InnoDB COMMENT='Canonical S3 object metadata for application binaries';

-- Link legacy `documents` rows to their file_storage-backed object.
-- NULL means "still on the legacy local-disk / pre-migration path" —
-- read paths must check this column and fall back accordingly.
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'documents'
      AND COLUMN_NAME = 'storage_file_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `documents` ADD COLUMN `storage_file_id` INT UNSIGNED DEFAULT NULL AFTER `url`, ADD KEY `idx_documents_storage_file_id` (`storage_file_id`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Module registration (name, enabled state, ACLs) is handled by OpenEMR's
-- own Module Manager UI (Modules -> Manage Modules -> Register) and does
-- not need a manual row here.
