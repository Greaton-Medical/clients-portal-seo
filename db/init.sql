-- =====================================
-- Client Portal — Full DB Schema
-- (init.sql incorporates all migrations)
-- =====================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Clients (organizations using the portal)
CREATE TABLE IF NOT EXISTS clients (
  id                              INT AUTO_INCREMENT PRIMARY KEY,
  name                            VARCHAR(100)  NOT NULL,
  slug                            VARCHAR(50)   UNIQUE NOT NULL,
  monday_board_id                 BIGINT        NOT NULL,
  monday_group_id                 VARCHAR(50)   NOT NULL,
  accent_color                    VARCHAR(7)    DEFAULT '#0066cc',
  logo_url                        VARCHAR(255)  DEFAULT NULL,
  form_iframe_url                 VARCHAR(500)  NOT NULL DEFAULT '',
  submitted_by_column_id          VARCHAR(50)   DEFAULT NULL
      COMMENT 'monday column ID for Submitted By text field; NULL disables per-user tracking',
  subitem_revision_notes_column_id VARCHAR(50)  DEFAULT NULL
      COMMENT 'Column ID on the subitems board where Request Changes feedback is written.',
  hidden_column_ids               TEXT          DEFAULT NULL
      COMMENT 'Comma-separated monday column IDs hidden from client task detail view',
  task_status_column_id           VARCHAR(50)   DEFAULT NULL
      COMMENT 'Monday column ID for Task Status on this client board',
  production_status_column_id     VARCHAR(50)   DEFAULT NULL
      COMMENT 'Monday column ID for Production Status on this client board',
  copy_review_enabled             BOOLEAN       NOT NULL DEFAULT FALSE
      COMMENT 'Show Approve Copy / Request Revision buttons when task is COPYWRITING + CLIENT REVIEW',
  active                          TINYINT(1)    DEFAULT 1,
  created_at                      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Users (each user belongs to one or more clients via user_clients)
CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  client_id     INT           NOT NULL COMMENT 'Legacy primary client — kept as safety net',
  username      VARCHAR(50)   NOT NULL,
  email         VARCHAR(100)  NOT NULL,
  password_hash VARCHAR(255)  NOT NULL,
  full_name     VARCHAR(100),
  role          ENUM('user', 'client_admin') DEFAULT 'user',
  active        TINYINT(1)    DEFAULT 1,
  last_login    TIMESTAMP     NULL,
  created_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_per_client (client_id, username),
  INDEX idx_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Many-to-many user ↔ client assignments
CREATE TABLE IF NOT EXISTS user_clients (
  user_id    INT     NOT NULL,
  client_id  INT     NOT NULL,
  is_primary BOOLEAN NOT NULL DEFAULT FALSE
      COMMENT 'Default client on first login or when active selection is lost',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, client_id),
  FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  FOREIGN KEY (client_id) REFERENCES clients(id)  ON DELETE CASCADE,
  INDEX idx_user   (user_id),
  INDEX idx_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Submissions (mapping to monday items)
CREATE TABLE IF NOT EXISTS submissions (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  user_id          INT           NOT NULL,
  client_id        INT           NOT NULL,
  monday_item_id   BIGINT        NOT NULL,
  task_name        VARCHAR(255)  NOT NULL,
  content_category VARCHAR(50),
  is_mock          TINYINT(1)    DEFAULT 0,
  files_uploaded   INT           DEFAULT 0,
  subitems_count   INT           DEFAULT 0,
  submitted_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  INDEX idx_user   (user_id),
  INDEX idx_client (client_id),
  INDEX idx_monday (monday_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admins (internal agency users — separate from client portal users)
CREATE TABLE IF NOT EXISTS admins (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)   UNIQUE NOT NULL,
  email         VARCHAR(100)  NOT NULL,
  password_hash VARCHAR(255)  NOT NULL,
  full_name     VARCHAR(100),
  active        TINYINT(1)    DEFAULT 1,
  last_login    TIMESTAMP     NULL,
  created_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Login attempts (rate limiting)
CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  ip_address   VARCHAR(45) NOT NULL,
  username     VARCHAR(50),
  success      TINYINT(1)  DEFAULT 0,
  attempted_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rate-limit table for client review actions (max 10 per user per hour)
CREATE TABLE IF NOT EXISTS review_actions (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT    NOT NULL,
  action         ENUM('approve','request_changes','approve_copy','request_revision') NOT NULL,
  monday_item_id BIGINT NOT NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_time (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tracks when each task was approved (used to hide post-approval internal comments)
CREATE TABLE IF NOT EXISTS task_approvals (
  monday_item_id      BIGINT    NOT NULL,
  approved_by_user_id INT       NOT NULL,
  approved_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (monday_item_id),
  FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Local tracking of "Request Changes" actions; resolved when task re-enters CLIENT REVIEW
CREATE TABLE IF NOT EXISTS task_change_requests (
  id                   INT       PRIMARY KEY AUTO_INCREMENT,
  monday_item_id       BIGINT    NOT NULL,
  requested_by_user_id INT       NOT NULL,
  requested_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  comment_text         TEXT      NOT NULL,
  resolved_at          TIMESTAMP NULL DEFAULT NULL
      COMMENT 'Set when task next enters CLIENT REVIEW or APPROVED',
  INDEX idx_item       (monday_item_id),
  INDEX idx_user       (requested_by_user_id),
  INDEX idx_unresolved (resolved_at, monday_item_id),
  FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================
-- SEED DATA
-- =====================================

-- Test client — works with MOCK_MONDAY=true (dummy board IDs)
INSERT INTO clients (name, slug, monday_board_id, monday_group_id, accent_color, form_iframe_url)
VALUES ('Test Client Inc.', 'test', 99999999, 'dummy_group', '#A434FF', '');

-- Test user — login: test_user / test1234
INSERT INTO users (client_id, username, email, password_hash, full_name, role)
VALUES (1, 'test_user', 'user@test.local',
        '$2y$12$vVOs/F55SRSHeS1AlGM0Ke6.ekA.HvXBphEVaV.2pDmhq/xZv4tX.',
        'Test User', 'client_admin');

-- Assign test_user to test client in the pivot table
INSERT INTO user_clients (user_id, client_id, is_primary)
VALUES (1, 1, TRUE);

-- Admin account — login: admin / Gs!9mXp2#kLv8wQr  (CHANGE ON PRODUCTION)
INSERT INTO admins (username, email, password_hash, full_name)
VALUES ('admin', 'admin@example.com',
        '$2y$12$SzzkdtRMYW/bySTqKnswbOCAyp2UHeBtI1PpBJvwfXu/6Fmagl7bO',
        'Portal Admin');

SET FOREIGN_KEY_CHECKS = 1;
