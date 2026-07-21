-- TaskFlow: persistent "Keep me logged in" tokens (remember-me).
-- One row per remembered device. Lets a user stay signed in even after the PHP
-- session itself is garbage-collected / the session store is wiped — until the
-- cookie is cleared or the token is revoked (logout).
--
-- Security model (selector/validator):
--   * selector       — public lookup key sent in the cookie (indexed).
--   * validator_hash  — SHA-256 of the secret validator. Only the hash is stored,
--                       so a leaked database cannot be used to forge a cookie.
--
-- Binds to the SHARED MagDyn users(id) — same convention as the other tf_ tables.
-- Apply once (idempotent):
--   C:\xampp74\mysql\bin\mysql.exe -u root magdyn < taskflow\tf_remember_tokens.sql

CREATE TABLE IF NOT EXISTS tf_remember_tokens (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  selector       CHAR(24)  NOT NULL,            -- 12 random bytes, hex
  validator_hash CHAR(64)  NOT NULL,            -- sha256(validator hex); never store raw
  expires_at     DATETIME  NOT NULL,
  user_agent     VARCHAR(255) DEFAULT NULL,     -- informational only
  created_at     DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at   DATETIME  DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tf_remember_selector (selector),
  KEY idx_tf_remember_user (user_id),
  KEY idx_tf_remember_expires (expires_at),
  CONSTRAINT fk_tf_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
