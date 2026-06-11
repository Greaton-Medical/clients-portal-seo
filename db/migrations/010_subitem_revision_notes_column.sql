-- Migration 010: add subitem_revision_notes_column_id to clients
-- This column stores the monday column ID on the subitems board where
-- "Request Changes" feedback is written. Mae's monday automations watch
-- this column and handle status transitions automatically.

ALTER TABLE clients
    ADD COLUMN subitem_revision_notes_column_id VARCHAR(50) DEFAULT NULL
    COMMENT 'Column ID on the subitems board where Request Changes feedback is written. Used by client monday automations to trigger status changes.';

-- Set per-client column IDs via admin panel or a follow-up UPDATE for each client slug.
