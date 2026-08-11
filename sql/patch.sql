--
--  Comment Meta Language Constructs:
--
--  #IfNotTable
--    argument: table_name
--    behavior: if the table_name does not exist,  the block will be executed

--  #IfTable
--    argument: table_name
--    behavior: if the table_name does exist, the block will be executed

--  #IfMissingColumn
--    arguments: table_name colname
--    behavior:  if the colname in the table_name table does not exist,  the block will be executed

--  #IfNotColumnType
--    arguments: table_name colname value
--    behavior:  If the table table_name does not have a column colname with a data type equal to value, then the block will be executed

--  #IfNotRow
--    arguments: table_name colname value
--    behavior:  If the table table_name does not have a row where colname = value, the block will be executed.

--  #IfNotRow2D
--    arguments: table_name colname value colname2 value2
--    behavior:  If the table table_name does not have a row where colname = value AND colname2 = value2, the block will be executed.

--  #IfNotRow3D
--    arguments: table_name colname value colname2 value2 colname3 value3
--    behavior:  If the table table_name does not have a row where colname = value AND colname2 = value2 AND colname3 = value3, the block will be executed.

--  #IfNotRow4D
--    arguments: table_name colname value colname2 value2 colname3 value3 colname4 value4
--    behavior:  If the table table_name does not have a row where colname = value AND colname2 = value2 AND colname3 = value3 AND colname4 = value4, the block will be executed.

--  #IfNotRow2Dx2
--    desc:      This is a very specialized function to allow adding items to the list_options table to avoid both redundant option_id and title in each element.
--    arguments: table_name colname value colname2 value2 colname3 value3
--    behavior:  The block will be executed if both statements below are true:
--               1) The table table_name does not have a row where colname = value AND colname2 = value2.
--               2) The table table_name does not have a row where colname = value AND colname3 = value3.

--  #IfNotIndex
--    desc:      This function will allow adding of indexes/keys.
--    arguments: table_name colname
--    behavior:  If the index does not exist, it will be created

--  #EndIf
--    all blocks are terminated with and #EndIf statement.

#IfNotTable file_storage
CREATE TABLE `file_storage` (
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
#EndIf

#IfMissingColumn documents storage_file_id
ALTER TABLE `documents`
  ADD `storage_file_id` bigint(20) DEFAULT NULL COMMENT 'fk to file_storage.id; NULL means legacy/non-S3 binary';
#EndIf

#IfNotIndex documents idx_documents_storage_file_id
ALTER TABLE `documents` ADD KEY `idx_documents_storage_file_id` (`storage_file_id`);
#EndIf
