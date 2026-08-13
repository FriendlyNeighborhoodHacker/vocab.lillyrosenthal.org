<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/WordSentences.php';

// The global word list (shared flashcard deck). All SQL touching the words
// table lives here; every write takes a UserContext and is activity-logged.
class WordManagement {
    private static function pdo(): PDO {
        return pdo();
    }

    private static function assertAdmin(?UserContext $ctx): void {
        if (!$ctx || !$ctx->admin) {
            throw new RuntimeException('Admins only');
        }
    }

    private static function normalizeWord(string $word): string {
        return trim($word);
    }

    // Optional text fields (synonyms) store NULL when blank. Sentences have
    // their own shape — a JSON array — so they go through WordSentences.
    private static function normalizeOptionalText(?string $value): ?string {
        if ($value === null) return null;
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    // $sentences is what the admin typed or the CSV held: a JSON array of
    // sentences, or plain text with one sentence per line.
    public static function addWord(UserContext $ctx, string $word, string $definition, ?string $sentences = null, ?string $synonyms = null, ?int $sortOrder = null): int {
        self::assertAdmin($ctx);

        $word = self::normalizeWord($word);
        $definition = trim($definition);
        $sentences = WordSentences::normalizeInput($sentences);
        $synonyms = self::normalizeOptionalText($synonyms);
        if ($word === '') {
            throw new InvalidArgumentException('Word is required.');
        }
        if (mb_strlen($word) > 100) {
            throw new InvalidArgumentException('Word must be 100 characters or fewer.');
        }
        if ($definition === '') {
            throw new InvalidArgumentException('Definition is required.');
        }
        if (self::findByWordText($word)) {
            throw new InvalidArgumentException('That word is already in the list.');
        }

        if ($sortOrder === null) {
            $sortOrder = self::nextSortOrder();
        }

        $st = self::pdo()->prepare(
            'INSERT INTO words (word, definition, sentences, synonyms, sort_order, created_by_user_id) VALUES (?,?,?,?,?,?)'
        );
        $st->execute([$word, $definition, $sentences, $synonyms, $sortOrder, $ctx->id]);
        $id = (int)self::pdo()->lastInsertId();

        ActivityLog::log($ctx, 'word.create', ['word_id' => $id, 'word' => $word]);
        return $id;
    }

    public static function updateWord(UserContext $ctx, int $wordId, string $word, string $definition, ?string $sentences, ?string $synonyms, int $sortOrder): bool {
        self::assertAdmin($ctx);

        $word = self::normalizeWord($word);
        $definition = trim($definition);
        $sentences = WordSentences::normalizeInput($sentences);
        $synonyms = self::normalizeOptionalText($synonyms);
        if ($word === '') {
            throw new InvalidArgumentException('Word is required.');
        }
        if ($definition === '') {
            throw new InvalidArgumentException('Definition is required.');
        }

        $existing = self::findByWordText($word);
        if ($existing && (int)$existing['id'] !== $wordId) {
            throw new InvalidArgumentException('Another entry already uses that word.');
        }

        $st = self::pdo()->prepare(
            'UPDATE words SET word = ?, definition = ?, sentences = ?, synonyms = ?, sort_order = ? WHERE id = ?'
        );
        $ok = $st->execute([$word, $definition, $sentences, $synonyms, $sortOrder, $wordId]);

        if ($ok) {
            ActivityLog::log($ctx, 'word.update', ['word_id' => $wordId, 'word' => $word]);
        }
        return $ok;
    }

    // Deleting a word also removes every user's state and review events for it
    // (ON DELETE CASCADE).
    public static function deleteWord(UserContext $ctx, int $wordId): bool {
        self::assertAdmin($ctx);

        $st = self::pdo()->prepare('DELETE FROM words WHERE id = ?');
        $ok = $st->execute([$wordId]);

        if ($ok) {
            ActivityLog::log($ctx, 'word.delete', ['word_id' => $wordId]);
        }
        return $ok;
    }

    public static function findById(int $wordId): ?array {
        $st = self::pdo()->prepare('SELECT * FROM words WHERE id = ? LIMIT 1');
        $st->execute([$wordId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // Case-insensitive lookup by the word text (used to match CSV imports).
    public static function findByWordText(string $word): ?array {
        $word = self::normalizeWord($word);
        if ($word === '') return null;
        $st = self::pdo()->prepare('SELECT * FROM words WHERE LOWER(word) = LOWER(?) LIMIT 1');
        $st->execute([$word]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // Word rows with a "tags" column ("Green, White and Blue"); optionally
    // limited to one tag (deck).
    public static function listWordsInGlobalOrder(?int $tagId = null): array {
        $sql = 'SELECT w.*,
                       (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR \', \')
                        FROM word_tags wt JOIN tags t ON t.id = wt.tag_id
                        WHERE wt.word_id = w.id) AS tags
                FROM words w';
        $params = [];
        if ($tagId !== null) {
            $sql .= ' INNER JOIN word_tags wtf ON wtf.word_id = w.id AND wtf.tag_id = ?';
            $params[] = $tagId;
        }
        $sql .= ' ORDER BY w.sort_order, w.id';
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    // Just the word texts, alphabetized. An empty $tagIds means every word;
    // several tag ids means the union of those decks (the same contract as
    // quiz rounds, so a round's answer is always in the list).
    public static function listWordTexts(array $tagIds = []): array {
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds), fn($id) => $id > 0)));

        $sql = 'SELECT word FROM words';
        if ($tagIds) {
            $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
            $sql .= ' WHERE id IN (SELECT word_id FROM word_tags WHERE tag_id IN (' . $placeholders . '))';
        }
        $sql .= ' ORDER BY word';

        $st = self::pdo()->prepare($sql);
        $st->execute($tagIds);
        return array_map(fn($row) => (string)$row['word'], $st->fetchAll());
    }

    // ===== Tags (decks) =====

    // "White and Blue; Green" or "Green, Red" -> ['White and Blue', 'Green'].
    // Splits on commas/semicolons, trims, drops blanks, dedupes case-insensitively.
    public static function parseTagList(string $tags): array {
        $names = [];
        foreach (preg_split('/[;,]/', $tags) ?: [] as $name) {
            $name = trim($name);
            if ($name === '') continue;
            $key = mb_strtolower($name);
            if (!isset($names[$key])) {
                $names[$key] = $name;
            }
        }
        return array_values($names);
    }

    // All tags with how many words carry each, alphabetical.
    public static function listAllTags(): array {
        $st = self::pdo()->query(
            'SELECT t.id, t.name, COUNT(wt.id) AS word_count
             FROM tags t LEFT JOIN word_tags wt ON wt.tag_id = t.id
             GROUP BY t.id, t.name
             ORDER BY t.name'
        );
        return $st->fetchAll();
    }

    public static function findTagById(int $tagId): ?array {
        $st = self::pdo()->prepare('SELECT * FROM tags WHERE id = ? LIMIT 1');
        $st->execute([$tagId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // A word's tag names, alphabetical.
    public static function tagNamesForWord(int $wordId): array {
        $st = self::pdo()->prepare(
            'SELECT t.name FROM word_tags wt JOIN tags t ON t.id = wt.tag_id
             WHERE wt.word_id = ? ORDER BY t.name'
        );
        $st->execute([$wordId]);
        return array_map(fn($row) => (string)$row['name'], $st->fetchAll());
    }

    // Replace a word's tags with exactly $tagNames, creating missing tags.
    // Logs the change; use syncWordTagLinks() from flows that log in aggregate.
    public static function setWordTags(UserContext $ctx, int $wordId, array $tagNames): void {
        self::assertAdmin($ctx);
        self::syncWordTagLinks($wordId, $tagNames);
        ActivityLog::log($ctx, 'word.tags_set', ['word_id' => $wordId, 'tags' => array_values($tagNames)]);
    }

    // The unlogged sync underneath setWordTags(); the CSV import calls this
    // per row inside its transaction and logs one aggregate entry instead.
    public static function syncWordTagLinks(int $wordId, array $tagNames): void {
        $pdo = self::pdo();
        $pdo->prepare('DELETE FROM word_tags WHERE word_id = ?')->execute([$wordId]);
        foreach ($tagNames as $name) {
            $name = trim((string)$name);
            if ($name === '') continue;
            $tagId = self::findOrCreateTagIdByName($name);
            $pdo->prepare('INSERT IGNORE INTO word_tags (word_id, tag_id) VALUES (?,?)')
                ->execute([$wordId, $tagId]);
        }
    }

    // Tag names match case-insensitively (utf8mb4 CI collation on tags.name).
    private static function findOrCreateTagIdByName(string $name): int {
        if (mb_strlen($name) > 100) {
            throw new InvalidArgumentException('Tag names must be 100 characters or fewer.');
        }
        $st = self::pdo()->prepare('SELECT id FROM tags WHERE name = ? LIMIT 1');
        $st->execute([$name]);
        $id = $st->fetchColumn();
        if ($id) return (int)$id;

        $st = self::pdo()->prepare('INSERT INTO tags (name) VALUES (?)');
        $st->execute([$name]);
        return (int)self::pdo()->lastInsertId();
    }

    public static function countWords(): int {
        $st = self::pdo()->query('SELECT COUNT(*) AS c FROM words');
        $row = $st->fetch();
        return (int)($row['c'] ?? 0);
    }

    // New words append to the end of the global order.
    public static function nextSortOrder(): int {
        $st = self::pdo()->query('SELECT COALESCE(MAX(sort_order), 0) AS m FROM words');
        $row = $st->fetch();
        return (int)($row['m'] ?? 0) + 1;
    }
}
