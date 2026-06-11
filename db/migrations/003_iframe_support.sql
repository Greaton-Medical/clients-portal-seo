-- Phase 4: Add iframe form URL column to clients
ALTER TABLE clients ADD COLUMN form_iframe_url VARCHAR(500) NOT NULL DEFAULT '';

-- Set per-client form URLs via admin panel or a follow-up UPDATE for each client slug.
