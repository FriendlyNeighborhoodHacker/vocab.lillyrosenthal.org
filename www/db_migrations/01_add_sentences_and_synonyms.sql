-- Add sentences and synonyms to the global word list. Idempotent: safe to run
-- more than once. (schema.sql already includes these columns for fresh installs.)

SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'words' AND column_name = 'sentences');
SET @sql := IF(@col = 0,
  "ALTER TABLE words ADD COLUMN sentences TEXT DEFAULT NULL COMMENT 'Example sentence(s) using the word; NULL = none provided' AFTER definition",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'words' AND column_name = 'synonyms');
SET @sql := IF(@col = 0,
  "ALTER TABLE words ADD COLUMN synonyms TEXT DEFAULT NULL COMMENT 'Synonyms as one string, e.g. \"reduce, diminish\"; NULL = none provided' AFTER sentences",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
