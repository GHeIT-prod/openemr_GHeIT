-- This table definition is loaded and then executed when the OpenEMR interface's install button is clicked.
CREATE TABLE IF NOT EXISTS `mod_custom_skeleton_records`(
    `id` INT(11)  PRIMARY KEY AUTO_INCREMENT NOT NULL
    ,`name` VARCHAR(255) NOT NULL
);


CREATE TABLE IF NOT EXISTS `custom_mrn_sequence` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);