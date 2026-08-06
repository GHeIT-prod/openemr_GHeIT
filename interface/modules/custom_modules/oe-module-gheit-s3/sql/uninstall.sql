-- oe-module-gheit-s3 :: sql/uninstall.sql
-- Reversible teardown. Does NOT delete any S3 objects or `documents` rows —
-- only removes this module's own metadata table and linkage column.
-- Run manually; not invoked automatically by module disable/uninstall.

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'documents'
      AND COLUMN_NAME = 'storage_file_id'
);
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE `documents` DROP KEY `idx_documents_storage_file_id`, DROP COLUMN `storage_file_id`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP TABLE IF EXISTS `file_storage`;
