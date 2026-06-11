-- Migration 001: Add admins table
-- Run on production DB after deploying Phase 3 code.

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

-- Seed: admin / admin1234 — CHANGE THIS PASSWORD ON PRODUCTION IMMEDIATELY.
-- Generate a new hash via: php -r "echo password_hash('your_password', PASSWORD_BCRYPT);"
INSERT IGNORE INTO admins (username, email, password_hash, full_name)
VALUES ('admin', 'admin@example.com',
        '$2b$10$DzWhekLOVpPCR8PJ0artXunlDg0KYWUPo4rh4CtFn1oRJGllZ654q',
        'Portal Admin');
