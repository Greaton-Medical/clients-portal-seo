-- Migration 012: Per-client Approve Copy / Request Revision workflow
-- Adds three columns to clients for the copy-review feature.
-- Also extends review_actions ENUM to cover the two new action types used by existing PHP files.

ALTER TABLE clients
    ADD COLUMN task_status_column_id       VARCHAR(50)  DEFAULT NULL
        COMMENT 'Monday column ID for Task Status on this client''s board',
    ADD COLUMN production_status_column_id VARCHAR(50)  DEFAULT NULL
        COMMENT 'Monday column ID for Production Status on this client''s board',
    ADD COLUMN copy_review_enabled         BOOLEAN      NOT NULL DEFAULT FALSE
        COMMENT 'Show Approve Copy / Request Revision buttons when task is in COPYWRITING + CLIENT REVIEW';

-- Extend enum so existing approve-copy and request-revision inserts don't fail in strict mode
ALTER TABLE review_actions
    MODIFY COLUMN action ENUM('approve','request_changes','approve_copy','request_revision') NOT NULL;
