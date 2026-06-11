-- Phase 5: monday_group_id is now used only for form submission routing (where new items land),
-- not for dashboard fetching (which queries the entire board via items_page).
ALTER TABLE clients
    MODIFY COLUMN monday_group_id VARCHAR(50) NOT NULL DEFAULT ''
    COMMENT 'Target group for new monday form submissions; not used for dashboard/admin fetching (whole board is queried)';
