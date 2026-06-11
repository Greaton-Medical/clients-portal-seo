-- Phase 2.5: track when each task was approved so task detail page can
-- hide internal team comments posted after approval from client view.
-- PRIMARY KEY on monday_item_id: one approval record per task (re-approval overwrites).
CREATE TABLE IF NOT EXISTS task_approvals (
    monday_item_id      BIGINT       NOT NULL,
    approved_by_user_id INT          NOT NULL,
    approved_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (monday_item_id),
    FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
