-- Add hidden_column_ids to clients table
-- Comma-separated monday column IDs to hide from CLIENT view of task pages.
-- Admin view always shows all columns. Empty/NULL = show all (no regression).
ALTER TABLE clients
ADD COLUMN hidden_column_ids TEXT DEFAULT NULL
COMMENT 'Comma-separated monday column IDs to hide from CLIENT view of task pages. Admin sees all columns regardless. Empty = show all.';
