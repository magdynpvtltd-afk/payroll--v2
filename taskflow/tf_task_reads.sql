-- TaskFlow: per-user comment read state.
-- One row per (user, task) recording the highest comment id that user has seen.
-- Used to compute the "unread comments" balloon and the "Unread" task filter:
-- a task's unread count = comments by OTHER users whose id is greater than the
-- user's last_read_comment_id for that task.
--
-- last_read_comment_id is bumped to the task's newest comment id whenever the
-- user opens the task (task_view.php). Binds to the SHARED MagDyn users(id) and
-- to tf_tasks(id) — same convention as the other tf_ tables.
--
-- Apply once (idempotent):
--   C:\xampp74\mysql\bin\mysql.exe -u root magdyn < taskflow\tf_task_reads.sql

CREATE TABLE IF NOT EXISTS tf_task_reads (
  user_id              INT UNSIGNED NOT NULL,
  task_id              INT UNSIGNED NOT NULL,
  last_read_comment_id INT UNSIGNED NOT NULL DEFAULT 0,   -- highest tf_comments.id this user has seen on the task
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, task_id),
  KEY idx_tr_task (task_id),
  CONSTRAINT fk_tr_user FOREIGN KEY (user_id) REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_tr_task FOREIGN KEY (task_id) REFERENCES tf_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
