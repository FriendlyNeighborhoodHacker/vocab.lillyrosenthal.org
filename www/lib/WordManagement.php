<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

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

    public static function addWord(UserContext $ctx, string $word, string $definition, ?int $sortOrder = null): int {
        self::assertAdmin($ctx);

        $word = self::normalizeWord($word);
        $definition = trim($definition);
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
            'INSERT INTO words (word, definition, sort_order, created_by_user_id) VALUES (?,?,?,?)'
        );
        $st->execute([$word, $definition, $sortOrder, $ctx->id]);
        $id = (int)self::pdo()->lastInsertId();

        ActivityLog::log($ctx, 'word.create', ['word_id' => $id, 'word' => $word]);
        return $id;
    }

    public static function updateWord(UserContext $ctx, int $wordId, string $word, string $definition, int $sortOrder): bool {
        self::assertAdmin($ctx);

        $word = self::normalizeWord($word);
        $definition = trim($definition);
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
            'UPDATE words SET word = ?, definition = ?, sort_order = ? WHERE id = ?'
        );
        $ok = $st->execute([$word, $definition, $sortOrder, $wordId]);

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

    public static function listWordsInGlobalOrder(): array {
        $st = self::pdo()->query('SELECT * FROM words ORDER BY sort_order, id');
        return $st->fetchAll();
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
