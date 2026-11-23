-- KelderInventory web app schema (FSD_Web_Inventory.md, chapter 5).
-- All tables use the inv_ prefix: the LWS database is shared with verba (§4.2).
-- Idempotent: safe to run again.

CREATE TABLE IF NOT EXISTS inv_items (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  box             VARCHAR(8)   NOT NULL,
  bin             VARCHAR(8)   NOT NULL,
  label1          VARCHAR(60)  NOT NULL,
  label2          VARCHAR(60)  NOT NULL DEFAULT '',
  label3          VARCHAR(60)  NOT NULL DEFAULT '',
  keywords        VARCHAR(255) NOT NULL DEFAULT '',
  comments        TEXT         NOT NULL,
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NOT NULL,
  last_printed_at DATETIME     NULL,
  KEY idx_box_bin (box, bin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Key/value settings: label font sizes, layout, last used box/bin.
CREATE TABLE IF NOT EXISTS inv_settings (
  name  VARCHAR(40)  NOT NULL PRIMARY KEY,
  value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single admin account (id is always 1).
CREATE TABLE IF NOT EXISTS inv_admin (
  id                  TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  password_hash       VARCHAR(255) NOT NULL,
  password_changed_at DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin sessions: only the SHA-256 of the cookie token is stored.
CREATE TABLE IF NOT EXISTS inv_sessions (
  token_hash  CHAR(64)    NOT NULL PRIMARY KEY,
  csrf_token  CHAR(64)    NOT NULL,
  created_at  DATETIME    NOT NULL,
  last_seen   DATETIME    NOT NULL,
  user_agent  VARCHAR(200) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every login attempt, for per-IP and global lockout. IPs are stored as a peppered hash.
CREATE TABLE IF NOT EXISTS inv_login_attempts (
  id       INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip_hash  CHAR(64)  NOT NULL,
  at       DATETIME  NOT NULL,
  success  TINYINT(1) NOT NULL,
  KEY idx_ip_at (ip_hash, at),
  KEY idx_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Public search rate limiting: one row per IP hash per minute window.
CREATE TABLE IF NOT EXISTS inv_rate (
  ip_hash      CHAR(64) NOT NULL,
  window_start DATETIME NOT NULL,
  hits         INT UNSIGNED NOT NULL,
  PRIMARY KEY (ip_hash, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Change history: every create/update/delete with old and new values.
CREATE TABLE IF NOT EXISTS inv_audit (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  at        DATETIME NOT NULL,
  action    VARCHAR(16) NOT NULL,
  item_id   INT UNSIGNED NULL,
  old_json  TEXT NULL,
  new_json  TEXT NULL,
  KEY idx_at (at),
  KEY idx_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Print queue (Phase 3). Each row is one label with a snapshot of its text and layout.
CREATE TABLE IF NOT EXISTS inv_print_jobs (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_id    CHAR(16)  NOT NULL,
  item_id     INT UNSIGNED NULL,
  payload     TEXT      NOT NULL,
  status      VARCHAR(16) NOT NULL,
  error       VARCHAR(255) NOT NULL DEFAULT '',
  created_at  DATETIME  NOT NULL,
  claimed_at  DATETIME  NULL,
  finished_at DATETIME  NULL,
  KEY idx_status (status, id),
  KEY idx_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Print agent heartbeat (Phase 3). One row.
CREATE TABLE IF NOT EXISTS inv_agent (
  id            TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  token_hash    CHAR(64) NOT NULL,
  last_seen     DATETIME NULL,
  agent_version VARCHAR(32) NOT NULL DEFAULT '',
  printer_state VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
