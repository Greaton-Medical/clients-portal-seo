-- Migration 011: Multi-client user assignment
-- Creates user_clients pivot (many-to-many) and seeds from existing users.client_id.
-- users.client_id is kept as a safety net; do NOT drop it here.

CREATE TABLE user_clients (
    user_id    INT     NOT NULL,
    client_id  INT     NOT NULL,
    is_primary BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Default client on first login or when active selection is lost',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, client_id),
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES clients(id)  ON DELETE CASCADE,
    INDEX idx_user   (user_id),
    INDEX idx_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed existing relationships from the legacy column so all existing users have pivot rows
INSERT INTO user_clients (user_id, client_id, is_primary)
SELECT id, client_id, TRUE
FROM   users
WHERE  client_id IS NOT NULL;
