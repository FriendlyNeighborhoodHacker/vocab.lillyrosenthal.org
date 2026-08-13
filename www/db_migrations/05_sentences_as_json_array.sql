-- Store words.sentences as a JSON array of strings instead of one text blob, so
-- a word can carry several example sentences and the flashcard can show them
-- all. Existing values were plain text with one sentence per line, so they are
-- split on newlines and re-encoded, keeping the order they were written in.
--
-- Idempotent: rows already holding a JSON array are left alone. Requires MySQL
-- 8.0 (recursive CTE + JSON functions).

ALTER TABLE words
  MODIFY COLUMN sentences TEXT DEFAULT NULL
  COMMENT 'Example sentences as a JSON array of strings, e.g. ["The storm abated."]; NULL = none provided';

-- Whitespace-only values mean "none provided"; NULL says so properly and keeps
-- them out of the rewrite below.
UPDATE words SET sentences = NULL WHERE sentences IS NOT NULL AND TRIM(sentences) = '';

-- GROUP_CONCAT truncates at 1KB by default, which would build broken JSON for a
-- word with several long sentences.
SET SESSION group_concat_max_len = 1048576;

WITH RECURSIVE sentence_lines (id, seq, line, rest) AS (
  SELECT id, 1,
         SUBSTRING_INDEX(text, '\n', 1),
         IF(LOCATE('\n', text) > 0, SUBSTRING(text, LOCATE('\n', text) + 1), NULL)
  FROM (
    SELECT id, REPLACE(REPLACE(sentences, '\r\n', '\n'), '\r', '\n') AS text
    FROM words
    WHERE sentences IS NOT NULL
      AND NOT (JSON_VALID(sentences) AND JSON_TYPE(sentences) = 'ARRAY')
  ) legacy
  UNION ALL
  SELECT id, seq + 1,
         SUBSTRING_INDEX(rest, '\n', 1),
         IF(LOCATE('\n', rest) > 0, SUBSTRING(rest, LOCATE('\n', rest) + 1), NULL)
  FROM sentence_lines
  WHERE rest IS NOT NULL
)
UPDATE words w
JOIN (
  SELECT id,
         CONCAT('[', GROUP_CONCAT(JSON_QUOTE(TRIM(line)) ORDER BY seq SEPARATOR ','), ']') AS json_sentences
  FROM sentence_lines
  WHERE TRIM(line) <> ''
  GROUP BY id
) rewritten ON rewritten.id = w.id
SET w.sentences = rewritten.json_sentences;
