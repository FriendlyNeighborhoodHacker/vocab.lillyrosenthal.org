-- Add global tags ("decks") on words: e.g. "White and Blue", "Green".
-- Idempotent: safe to run more than once. (schema.sql already includes these
-- tables for fresh installs.)

CREATE TABLE IF NOT EXISTS tags (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS word_tags (
  id INT AUTO_INCREMENT PRIMARY KEY,
  word_id INT NOT NULL,
  tag_id INT NOT NULL,
  UNIQUE KEY uq_wt_word_tag (word_id, tag_id),
  KEY idx_wt_tag (tag_id),
  CONSTRAINT fk_wt_word FOREIGN KEY (word_id) REFERENCES words(id) ON DELETE CASCADE,
  CONSTRAINT fk_wt_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB;
