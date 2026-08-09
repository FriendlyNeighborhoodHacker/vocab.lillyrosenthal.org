-- Typing quizzes ("Guess the Word" / "Fill in the Blank"): one row per answer
-- typed, with the points it earned. was_overridden records the user claiming a
-- scoreless answer as right anyway (synonyms), which awards partial credit
-- without rewriting what they actually typed.
-- Idempotent: safe to run more than once. (schema.sql already includes this
-- table for fresh installs.)

CREATE TABLE IF NOT EXISTS quiz_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  word_id INT NOT NULL,
  quiz_mode ENUM('guess_word','fill_blank') NOT NULL,
  answer_text VARCHAR(255) NOT NULL DEFAULT '',
  result ENUM('correct','close','synonym','incorrect') NOT NULL,
  was_overridden TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'User claimed this answer was right anyway',
  points_awarded INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_qa_user_created (user_id, created_at),
  KEY idx_qa_user_word (user_id, word_id),
  CONSTRAINT fk_qa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_qa_word FOREIGN KEY (word_id) REFERENCES words(id) ON DELETE CASCADE
) ENGINE=InnoDB;
