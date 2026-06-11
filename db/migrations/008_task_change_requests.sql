-- Migration 008: Add task_change_requests table for local "Changes Requested" state tracking.
-- Inserted when a client clicks "Request Changes". Resolved when the task re-enters CLIENT REVIEW.

CREATE TABLE IF NOT EXISTS task_change_requests (
    id                   INT           PRIMARY KEY AUTO_INCREMENT,
    monday_item_id       BIGINT        NOT NULL,
    requested_by_user_id INT           NOT NULL,
    requested_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    comment_text         TEXT          NOT NULL,
    resolved_at          TIMESTAMP     NULL DEFAULT NULL
                             COMMENT 'Set when task next enters CLIENT REVIEW or APPROVED',
    INDEX idx_item   (monday_item_id),
    INDEX idx_user   (requested_by_user_id),
    INDEX idx_unresolved (resolved_at, monday_item_id),
    FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
