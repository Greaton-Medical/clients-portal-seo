-- Add files_uploaded count to submissions table
-- Run only if columns do not already exist (MySQL 5.7 doesn't support IF NOT EXISTS)
ALTER TABLE submissions ADD COLUMN files_uploaded INT DEFAULT 0;
ALTER TABLE submissions ADD COLUMN subitems_count INT DEFAULT 0;
