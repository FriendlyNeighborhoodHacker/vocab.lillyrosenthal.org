-- Per-user, per-deck resume positions: where each user left off inside a
-- tag-filtered deck. (The untagged full deck keeps using users.deck_position.)
-- Idempotent: safe to run more than once. (schema.sql already includes this
-- table for fresh installs.)

CREATE TABLE IF NOT EXISTS user_deck_positions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  tag_id INT NOT NULL,
  position INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_udp_user_tag (user_id, tag_id),
  CONSTRAINT fk_udp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_udp_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB;
