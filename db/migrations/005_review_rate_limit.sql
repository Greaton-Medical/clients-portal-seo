-- Phase 2.5: rate-limit table for client review actions (approve / request-changes).
-- Max 10 actions per user per hour is enforced in the API endpoints.
CREATE TABLE IF NOT EXISTS review_actions (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NOT NULL,
  action         ENUM('approve', 'request_changes') NOT NULL,
  monday_item_id BIGINT NOT NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_time (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
