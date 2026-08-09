-- SAT Vocab Flashcards (vocab.lillyrosenthal.org) application schema
-- Create the database, then load this file. This file always represents the
-- complete current schema; migrations in db_migrations/ exist only to upgrade
-- older production installations.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ===== Users =====
-- Email is the login identifier. An empty password_hash means the user cannot
-- sign in yet (admin-created accounts gain a password via the emailed
-- activation link). shuffle_seed / deck_position hold the user's place in the
-- global flashcard deck: a NULL seed means the deck is in its original
-- sort_order; a non-null seed means a per-user deterministic shuffle.
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(255) DEFAULT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL DEFAULT '',
  is_admin TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'App administrator',
  email_verify_token VARCHAR(64) DEFAULT NULL,
  email_verified_at DATETIME DEFAULT NULL,
  password_reset_token_hash CHAR(64) DEFAULT NULL,
  password_reset_expires_at DATETIME DEFAULT NULL,
  shuffle_seed INT UNSIGNED DEFAULT NULL COMMENT 'NULL = original order; set = per-user shuffled deck order',
  deck_position INT NOT NULL DEFAULT 0 COMMENT 'Resume point in the full deck (index of the next card)',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_users_email_verify_token ON users(email_verify_token);
CREATE INDEX idx_users_pwreset_expires ON users(password_reset_expires_at);

-- ===== Settings key-value table =====
CREATE TABLE settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  key_name VARCHAR(191) NOT NULL UNIQUE,
  value LONGTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO settings (key_name, value) VALUES
  ('site_title', 'Lilly''s Vocab'),
  ('timezone', 'America/New_York'),
  ('site_base_url', 'https://vocab.lillyrosenthal.org')
ON DUPLICATE KEY UPDATE value=VALUES(value);

-- ===== Activity Log =====
-- Every write action and login is recorded here (see docs/php-guidelines.md).
CREATE TABLE activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id INT NULL,
  action_type VARCHAR(64) NOT NULL,
  json_metadata LONGTEXT NULL,
  CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_al_created_at ON activity_log(created_at);
CREATE INDEX idx_al_user_id ON activity_log(user_id);
CREATE INDEX idx_al_action_type ON activity_log(action_type);

-- ===== Email Log =====
CREATE TABLE emails_sent (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_by_user_id INT NULL,
  to_email VARCHAR(255) NOT NULL,
  to_name VARCHAR(255) DEFAULT NULL,
  cc_email VARCHAR(255) DEFAULT NULL,
  subject VARCHAR(500) NOT NULL,
  body_html LONGTEXT NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  error_message TEXT DEFAULT NULL,
  CONSTRAINT fk_emails_sent_user FOREIGN KEY (sent_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_emails_sent_created_at ON emails_sent(created_at);
CREATE INDEX idx_emails_sent_user_id ON emails_sent(sent_by_user_id);
CREATE INDEX idx_emails_sent_to_email ON emails_sent(to_email);
CREATE INDEX idx_emails_sent_success ON emails_sent(success);

-- ===== Words =====
-- The global flashcard deck, shared by every user. sort_order is the deck's
-- "original order"; CSV imports append new words at MAX(sort_order)+1 so
-- everyone's progress positions stay meaningful.
CREATE TABLE words (
  id INT AUTO_INCREMENT PRIMARY KEY,
  word VARCHAR(100) NOT NULL UNIQUE,
  definition TEXT NOT NULL,
  sentences TEXT DEFAULT NULL COMMENT 'Example sentence(s) using the word; NULL = none provided',
  synonyms TEXT DEFAULT NULL COMMENT 'Synonyms as one string, e.g. "reduce, diminish"; NULL = none provided',
  sort_order INT NOT NULL,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_words_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_words_sort_order ON words(sort_order);

-- ===== Per-user word state =====
-- One row per (user, word) the user has interacted with: the flag toggle and
-- the running Got it / Need More Review tallies. last_mark drives the score
-- ("mastered" = last_mark is got_it) and the "Review misses" deck filter.
CREATE TABLE user_word_state (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  word_id INT NOT NULL,
  is_flagged TINYINT(1) NOT NULL DEFAULT 0,
  last_mark ENUM('got_it','needs_review') DEFAULT NULL,
  got_it_count INT NOT NULL DEFAULT 0,
  needs_review_count INT NOT NULL DEFAULT 0,
  last_reviewed_at DATETIME DEFAULT NULL,
  UNIQUE KEY uq_uws_user_word (user_id, word_id),
  CONSTRAINT fk_uws_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_uws_word FOREIGN KEY (word_id) REFERENCES words(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_uws_user_flagged ON user_word_state(user_id, is_flagged);
CREATE INDEX idx_uws_user_mark ON user_word_state(user_id, last_mark);

-- ===== Word review events =====
-- Append-only: one row per Got it / Need More Review click, for the
-- "reviewed today" score and the stats page's daily activity chart.
CREATE TABLE word_review_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  word_id INT NOT NULL,
  mark ENUM('got_it','needs_review') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_wre_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_wre_word FOREIGN KEY (word_id) REFERENCES words(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_wre_user_created ON word_review_events(user_id, created_at);

-- Seed admin so a fresh install can be signed into immediately: email "lilly",
-- password "lilly". Change the password after first login. Regenerate the hash
-- with: php -r "echo password_hash('lilly', PASSWORD_DEFAULT);"
INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verified_at)
VALUES ('Lilly', 'Rosenthal', 'lilly', '$2y$12$.qTehBjCTALDaatSldro8ePNcWm1EFBwCFIk47SwauiRk00cKnOLa', 1, NOW());
