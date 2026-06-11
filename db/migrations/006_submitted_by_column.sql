-- Phase 4.5: per-user submission tracking via monday URL prefill.
-- The column stores the monday form column ID used to track which portal user submitted each task.
-- NULL = tracking not configured for that client (dashboard shows all tasks unified).
ALTER TABLE clients
    ADD COLUMN submitted_by_column_id VARCHAR(50) DEFAULT NULL
    COMMENT 'monday column ID for Submitted By text field; NULL disables per-user tracking';

-- Set per-client column IDs via admin panel or a follow-up UPDATE for each client slug.
