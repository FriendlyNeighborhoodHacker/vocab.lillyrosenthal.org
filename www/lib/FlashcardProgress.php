<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

// Per-user flashcard progress: deck ordering (shuffle), Got it / Need More
// Review marks, flags, and the score/stat aggregates. All SQL touching
// user_word_state, word_review_events, and the users deck columns lives here.
class FlashcardProgress {
    public const MARK_GOT_IT = 'got_it';
    public const MARK_NEEDS_REVIEW = 'needs_review';

    public const DECK_ALL = 'all';
    public const DECK_FLAGGED = 'flagged';
    public const DECK_NEEDS_REVIEW = 'needs_review';

    private static function pdo(): PDO {
        return pdo();
    }

    private static function assertLoggedIn(?UserContext $ctx): void {
        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
    }

    private static function assertValidMark(string $mark): void {
        if (!in_array($mark, [self::MARK_GOT_IT, self::MARK_NEEDS_REVIEW], true)) {
            throw new InvalidArgumentException('Unknown mark: ' . $mark);
        }
    }

    private static function shuffleSeedForUser(int $userId): ?int {
        $st = self::pdo()->prepare('SELECT shuffle_seed FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $row = $st->fetch();
        if (!$row) return null;
        return $row['shuffle_seed'] === null ? null : (int)$row['shuffle_seed'];
    }

    /**
     * The user's deck, in their current order. A NULL shuffle_seed means the
     * global sort_order; a set seed orders by SHA2(seed:word_id) — a stable
     * per-user permutation that automatically places later-imported words
     * without any backfill.
     *
     * $deckFilter: 'all', 'flagged' (user's flagged words), or 'needs_review'
     * (words whose latest mark was Need More Review). $tagId additionally
     * limits the deck to words carrying that global tag (e.g. the "Green" deck).
     * Returns rows of [id, word, definition, sentences, synonyms, is_flagged, last_mark].
     */
    public static function getDeckForUser(int $userId, string $deckFilter = self::DECK_ALL, ?int $tagId = null): array {
        if (!in_array($deckFilter, [self::DECK_ALL, self::DECK_FLAGGED, self::DECK_NEEDS_REVIEW], true)) {
            throw new InvalidArgumentException('Unknown deck filter: ' . $deckFilter);
        }

        $seed = self::shuffleSeedForUser($userId);

        $sql = 'SELECT w.id, w.word, w.definition, w.sentences, w.synonyms,
                       COALESCE(s.is_flagged, 0) AS is_flagged,
                       s.last_mark
                FROM words w
                LEFT JOIN user_word_state s ON s.word_id = w.id AND s.user_id = :user_id';

        if ($tagId !== null) {
            $sql .= ' INNER JOIN word_tags wt ON wt.word_id = w.id AND wt.tag_id = :tag_id';
        }

        if ($deckFilter === self::DECK_FLAGGED) {
            $sql .= ' WHERE s.is_flagged = 1';
        } elseif ($deckFilter === self::DECK_NEEDS_REVIEW) {
            $sql .= " WHERE s.last_mark = 'needs_review'";
        }

        if ($seed === null) {
            $sql .= ' ORDER BY w.sort_order, w.id';
        } else {
            $sql .= ' ORDER BY SHA2(CONCAT(:seed, \':\', w.id), 256)';
        }

        $st = self::pdo()->prepare($sql);
        $st->bindValue(':user_id', $userId, PDO::PARAM_INT);
        if ($tagId !== null) {
            $st->bindValue(':tag_id', $tagId, PDO::PARAM_INT);
        }
        if ($seed !== null) {
            $st->bindValue(':seed', $seed, PDO::PARAM_INT);
        }
        $st->execute();
        return $st->fetchAll();
    }

    /**
     * Record a Got it / Need More Review click: upsert the word's state row,
     * bump its counter, append a review event, and (when given) persist the
     * user's position in the deck being reviewed — the full deck when $tagId
     * is null, or that tag's deck. Returns the fresh score summary so the
     * caller can repaint the score chip.
     */
    public static function markWord(UserContext $ctx, int $wordId, string $mark, ?int $deckPosition = null, ?int $tagId = null): array {
        self::assertLoggedIn($ctx);
        self::assertValidMark($mark);

        $counterColumn = $mark === self::MARK_GOT_IT ? 'got_it_count' : 'needs_review_count';

        $pdo = self::pdo();
        $st = $pdo->prepare(
            "INSERT INTO user_word_state (user_id, word_id, last_mark, {$counterColumn}, last_reviewed_at)
             VALUES (?,?,?,1,NOW())
             ON DUPLICATE KEY UPDATE
               last_mark = VALUES(last_mark),
               {$counterColumn} = {$counterColumn} + 1,
               last_reviewed_at = NOW()"
        );
        $st->execute([$ctx->id, $wordId, $mark]);

        $st = $pdo->prepare('INSERT INTO word_review_events (user_id, word_id, mark) VALUES (?,?,?)');
        $st->execute([$ctx->id, $wordId, $mark]);

        if ($deckPosition !== null) {
            self::saveDeckPosition($ctx, $deckPosition, $tagId);
        }

        ActivityLog::log($ctx, 'word.marked', ['word_id' => $wordId, 'mark' => $mark]);

        return self::getScoreSummary($ctx->id);
    }

    // Toggle-save the per-user flag on a word. Returns the stored value.
    public static function setWordFlag(UserContext $ctx, int $wordId, bool $flagged): bool {
        self::assertLoggedIn($ctx);

        $st = self::pdo()->prepare(
            'INSERT INTO user_word_state (user_id, word_id, is_flagged) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE is_flagged = VALUES(is_flagged)'
        );
        $st->execute([$ctx->id, $wordId, $flagged ? 1 : 0]);

        ActivityLog::log($ctx, 'word.flag_toggled', ['word_id' => $wordId, 'is_flagged' => $flagged]);
        return $flagged;
    }

    // Re-deal the deck: a new random seed, starting every deck from its first
    // card (the seed reorders tag decks too, so their saved positions reset).
    public static function shuffleDeck(UserContext $ctx): int {
        self::assertLoggedIn($ctx);

        $seed = random_int(1, 2147483647);
        $st = self::pdo()->prepare('UPDATE users SET shuffle_seed = ?, deck_position = 0 WHERE id = ?');
        $st->execute([$seed, $ctx->id]);
        self::pdo()->prepare('DELETE FROM user_deck_positions WHERE user_id = ?')->execute([$ctx->id]);

        ActivityLog::log($ctx, 'deck.shuffled', ['seed' => $seed]);
        return $seed;
    }

    // Back to the global sort_order, starting every deck from its first card.
    public static function restoreOriginalOrder(UserContext $ctx): void {
        self::assertLoggedIn($ctx);

        $st = self::pdo()->prepare('UPDATE users SET shuffle_seed = NULL, deck_position = 0 WHERE id = ?');
        $st->execute([$ctx->id]);
        self::pdo()->prepare('DELETE FROM user_deck_positions WHERE user_id = ?')->execute([$ctx->id]);

        ActivityLog::log($ctx, 'deck.order_restored', []);
    }

    // Persist the resume point (piggybacks on mark saves): the full deck when
    // $tagId is null, otherwise that tag's deck.
    public static function saveDeckPosition(UserContext $ctx, int $position, ?int $tagId = null): void {
        self::assertLoggedIn($ctx);
        $position = max(0, $position);

        if ($tagId === null) {
            $st = self::pdo()->prepare('UPDATE users SET deck_position = ? WHERE id = ?');
            $st->execute([$position, $ctx->id]);
        } else {
            $st = self::pdo()->prepare(
                'INSERT INTO user_deck_positions (user_id, tag_id, position) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE position = VALUES(position)'
            );
            $st->execute([$ctx->id, $tagId, $position]);
        }
    }

    public static function deckPositionForUser(int $userId, ?int $tagId = null): int {
        if ($tagId === null) {
            $st = self::pdo()->prepare('SELECT deck_position FROM users WHERE id = ? LIMIT 1');
            $st->execute([$userId]);
            $row = $st->fetch();
            return (int)($row['deck_position'] ?? 0);
        }
        $st = self::pdo()->prepare('SELECT position FROM user_deck_positions WHERE user_id = ? AND tag_id = ? LIMIT 1');
        $st->execute([$userId, $tagId]);
        $row = $st->fetch();
        return (int)($row['position'] ?? 0);
    }

    public static function isDeckShuffledForUser(int $userId): bool {
        return self::shuffleSeedForUser($userId) !== null;
    }

    // The header score chip: mastered = words whose latest mark is Got it.
    // Returns ['mastered' => int, 'total_words' => int, 'reviewed_today' => int].
    public static function getScoreSummary(int $userId): array {
        $pdo = self::pdo();

        $st = $pdo->prepare("SELECT COUNT(*) AS c FROM user_word_state WHERE user_id = ? AND last_mark = 'got_it'");
        $st->execute([$userId]);
        $mastered = (int)$st->fetch()['c'];

        $total = (int)$pdo->query('SELECT COUNT(*) AS c FROM words')->fetch()['c'];

        $st = $pdo->prepare('SELECT COUNT(*) AS c FROM word_review_events WHERE user_id = ? AND created_at >= CURDATE()');
        $st->execute([$userId]);
        $reviewedToday = (int)$st->fetch()['c'];

        return [
            'mastered' => $mastered,
            'total_words' => $total,
            'reviewed_today' => $reviewedToday,
        ];
    }

    // Everything the stats page shows: the score summary plus needs-review /
    // flagged counts, the all-time review total, and daily review counts for
    // the last $days days (['date' => 'Y-m-d', 'count' => int], oldest first,
    // including zero days).
    public static function getStatsForUser(int $userId, int $days = 14): array {
        $pdo = self::pdo();
        $stats = self::getScoreSummary($userId);

        $st = $pdo->prepare("SELECT COUNT(*) AS c FROM user_word_state WHERE user_id = ? AND last_mark = 'needs_review'");
        $st->execute([$userId]);
        $stats['needs_review'] = (int)$st->fetch()['c'];

        $st = $pdo->prepare('SELECT COUNT(*) AS c FROM user_word_state WHERE user_id = ? AND is_flagged = 1');
        $st->execute([$userId]);
        $stats['flagged'] = (int)$st->fetch()['c'];

        $st = $pdo->prepare('SELECT COUNT(*) AS c FROM word_review_events WHERE user_id = ?');
        $st->execute([$userId]);
        $stats['total_reviews'] = (int)$st->fetch()['c'];

        $days = max(1, $days);
        $st = $pdo->prepare(
            'SELECT DATE(created_at) AS day, COUNT(*) AS c
             FROM word_review_events
             WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)'
        );
        $st->execute([$userId, $days - 1]);
        $byDay = [];
        foreach ($st->fetchAll() as $row) {
            $byDay[(string)$row['day']] = (int)$row['c'];
        }

        $daily = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $daily[] = ['date' => $date, 'count' => $byDay[$date] ?? 0];
        }
        $stats['daily_reviews'] = $daily;

        return $stats;
    }
}
