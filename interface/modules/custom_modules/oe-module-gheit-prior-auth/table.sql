-- oe-module-gheit-prior-auth
-- Run at module install time (wired up via the module installer's SQL hook).

CREATE TABLE IF NOT EXISTS `cds_hooks_services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `base_url` varchar(512) NOT NULL COMMENT 'e.g. https://cds.example.org/cds-services',
  `fhir_server` varchar(512) NULL COMMENT 'URL of the FHIR server',
  `tenant_id` int(11) NOT NULL,
  `service_hash` varchar(255) NOT NULL,
  `service_id` varchar(255) NOT NULL COMMENT 'id field from the vendor discovery doc',
  `hook` varchar(64) NOT NULL DEFAULT 'patient-view',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `auth_token` varchar(512) DEFAULT NULL COMMENT 'static bearer token fallback, if no OAuth2 fields set',
  `token_url` varchar(512) DEFAULT NULL COMMENT 'OAuth2 client_credentials token endpoint, e.g. Nucural auth server',
  `client_id` varchar(255) DEFAULT NULL,
  `client_secret` varchar(512) DEFAULT NULL COMMENT 'consider encrypting at rest, not just DB access control',
  `cached_token` varchar(2048) DEFAULT NULL,
  `cached_token_expires_at` datetime DEFAULT NULL,
  `timeout_seconds` int(11) NOT NULL DEFAULT 3,
  `date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cds_hooks_service` (`base_url`(255), `service_id`),
  KEY `idx_cds_hooks_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cds_hooks_crd_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) DEFAULT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `status` varchar(32) NOT NULL COMMENT 'no-pa, pa-required, or unknown',
  `card_summary` varchar(512) DEFAULT NULL,
  `date` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_crd_log_order` (`order_id`),
  KEY `idx_crd_log_patient` (`patient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cds_hooks_crd_status` (
    `order_id`        INT NOT NULL PRIMARY KEY,
    `patient_id`      INT NOT NULL,
    `status`          VARCHAR(32) NOT NULL,
    `action`          VARCHAR(32) NOT NULL,
    `dtr_launch_url`  TEXT NULL,
    `card_summary`    TEXT NULL,
    `updated_at`      DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Master feature flag, shows up under Administration -> Globals once installed.
INSERT IGNORE INTO `globals` (`gl_name`, `gl_index`, `gl_value`)
VALUES ('enable_cds_hooks', 0, '0');
