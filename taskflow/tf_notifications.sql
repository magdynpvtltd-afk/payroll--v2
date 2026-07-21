-- TaskFlow: per-recipient notification records for task events.
-- One row per (recipient, event). This is the source of truth that makes push
-- delivery idempotent and read-aware:
--   * pushed_at — set once the row has been web-pushed, so it is NEVER pushed twice.
--   * read_at   — set when the user opens the task, or clicks/clears the OS
--                 notification (reported by the service worker). A read/cleared
--                 notification is never shown or popped again.
--
-- Recipients are a task's participants (creator + assignee) minus the actor.
-- Binds to the SHARED MagDyn users(id) and tf_tasks(id) — same convention as
-- the other tf_ tables.
--
-- Apply once (idempotent):
--   C:\xampp74\mysql\bin\mysql.exe -u root magdyn < taskflow\tf_notifications.sql

CREATE TABLE IF NOT EXISTS tf_notifications (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,                              -- recipient
  task_id    INT UNSIGNED NOT NULL,
  actor_id   INT UNSIGNED DEFAULT NULL,                          -- who triggered it (informational)
  type       ENUM('created','commented','status') NOT NULL,
  title      VARCHAR(160) NOT NULL,
  body       VARCHAR(255) NOT NULL,
  url        VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pushed_at  DATETIME DEFAULT NULL,                              -- set once web-pushed (dedupe / push-once)
  read_at    DATETIME DEFAULT NULL,                              -- set on open / click / clear (never re-show)
  PRIMARY KEY (id),
  KEY idx_notif_user_unread (user_id, read_at),
  KEY idx_notif_user_task (user_id, task_id),
  KEY idx_notif_task (task_id),
  CONSTRAINT fk_tf_notif_user FOREIGN KEY (user_id) REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_tf_notif_task FOREIGN KEY (task_id) REFERENCES tf_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
