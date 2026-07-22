-- ---------------------------------------------------------------------------
-- MagDyn — register the "SO Pending List" page in the sidebar.
-- Created: 2026-07-22 IST
--
-- Adds one modules row under the Job Order group so /so_pending.php shows up
-- as a child of Job Cards / ATS. That page lists pending sales-order quantities
-- computed from job_cards (status not yet Billing Pending / Closed).
-- No permission rows are needed: nav visibility
-- inherits from job_card.view / ats.view via $navInherit in
-- includes/permissions.php, and the page itself gates on those permissions.
--
-- Idempotent and environment-independent:
--   * resolves the Job Order group by CODE (not a hardcoded id), so it works
--     on any DB where the group id differs from local;
--   * INSERT IGNORE + the uq_code unique key make re-runs a no-op.
--
-- Run on every environment (local + production):
--   mysql -uroot magdyn < db/seed_so_pending_module.sql
-- ---------------------------------------------------------------------------

SET @grp := (SELECT id FROM modules WHERE code = 'job_order' AND is_group = 1 LIMIT 1);

INSERT IGNORE INTO modules
    (code, name, description, is_group, parent_id, icon, virtual_url, sort_order, is_active)
VALUES
    ('so_pending',
     'SO Pending List',
     'Pending sales-order quantities by part, from job cards not yet Billing Pending / Closed.',
     0, @grp, '⏳', '/so_pending.php', 30, 1),
    ('so_pending_card',
     'SO Pending Card',
     'Same pending job-card data as SO Pending List, laid out as per-part cards.',
     0, @grp, '🗂️', '/so_pending_card.php', 40, 1);
