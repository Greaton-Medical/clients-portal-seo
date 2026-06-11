-- =====================================
-- GreatonMedical Portal - DB Schema
-- =====================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Clients (organizations using the portal)
CREATE TABLE IF NOT EXISTS clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(50) UNIQUE NOT NULL,
  monday_board_id BIGINT NOT NULL,
  monday_group_id VARCHAR(50) NOT NULL,
  accent_color VARCHAR(7) DEFAULT '#0066cc',
  logo_url VARCHAR(255) DEFAULT NULL,
  form_iframe_url VARCHAR(500) NOT NULL DEFAULT '',
  submitted_by_column_id VARCHAR(50) DEFAULT NULL COMMENT 'monday column ID for Submitted By text field; NULL disables per-user tracking',
  active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Users (each user belongs to one client)
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  username VARCHAR(50) NOT NULL,
  email VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(100),
  role ENUM('user', 'client_admin') DEFAULT 'user',
  active TINYINT(1) DEFAULT 1,
  last_login TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_per_client (client_id, username),
  INDEX idx_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Submissions (mapping to monday items)
CREATE TABLE IF NOT EXISTS submissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  client_id INT NOT NULL,
  monday_item_id BIGINT NOT NULL,
  task_name VARCHAR(255) NOT NULL,
  content_category VARCHAR(50),
  is_mock TINYINT(1) DEFAULT 0,
  files_uploaded INT DEFAULT 0,
  subitems_count INT DEFAULT 0,
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  INDEX idx_user (user_id),
  INDEX idx_client (client_id),
  INDEX idx_monday (monday_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admins (GreatonMedical internal users — separate from client portal users)
CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE NOT NULL,
  email VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(100),
  active TINYINT(1) DEFAULT 1,
  last_login TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Login attempts (rate limiting)
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip_address VARCHAR(45) NOT NULL,
  username VARCHAR(50),
  success TINYINT(1) DEFAULT 0,
  attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Review actions (rate limiting for approve/request_changes)
CREATE TABLE IF NOT EXISTS review_actions (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NOT NULL,
  action         ENUM('approve', 'request_changes') NOT NULL,
  monday_item_id BIGINT NOT NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_time (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Task approvals (tracks when each task was approved for comment filtering)
CREATE TABLE IF NOT EXISTS task_approvals (
  monday_item_id      BIGINT    NOT NULL,
  approved_by_user_id INT       NOT NULL,
  approved_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (monday_item_id),
  FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================
-- SEED DATA
-- =====================================

-- Client 1: CAPS Medical (real monday board)
INSERT INTO clients (name, slug, monday_board_id, monday_group_id, accent_color, form_iframe_url, submitted_by_column_id)
VALUES ('CAPS Medical', 'caps', 18409671597, 'group_mkznjcej', '#d63036', 'https://forms.monday.com/forms/embed/b16448bb312a6e0a3153510878741848?r=use1', 'short_text5nn96kew');

-- Client 2: Test Client (dummy IDs - works only in MOCK_MONDAY=true mode)
INSERT INTO clients (name, slug, monday_board_id, monday_group_id, accent_color, form_iframe_url)
VALUES ('Test Client Inc.', 'test', 99999999, 'dummy_group', '#0d9488', '');

-- User for CAPS - login: caps_user / caps1234
INSERT INTO users (client_id, username, email, password_hash, full_name, role)
VALUES (
  1, 'caps_user', 'user@caps.local',
  '$2b$10$2e/Yf48DhrlGyr6INnLCaeaPgCXd7slajuJfxqFSnxVfOwC8g0ZhK',
  'CAPS Test User', 'client_admin'
);

-- User for Test Client - login: test_user / test1234
INSERT INTO users (client_id, username, email, password_hash, full_name, role)
VALUES (
  2, 'test_user', 'user@test.local',
  '$2b$10$sjRxGL09VIfsVBJsppmEnOcvlidhTQx6lD3qMvQQAIa3j8nE3yGpe',
  'Test Client User', 'client_admin'
);

-- Admin seed: login admin / admin1234 (CHANGE ON PRODUCTION!)
INSERT INTO admins (username, email, password_hash, full_name)
VALUES ('admin', 'admin@greatonmedical.com',
        '$2b$10$DzWhekLOVpPCR8PJ0artXunlDg0KYWUPo4rh4CtFn1oRJGllZ654q',
        'GreatonMedical Admin');

SET FOREIGN_KEY_CHECKS = 1;
