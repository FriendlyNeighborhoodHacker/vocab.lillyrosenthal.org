<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/WordManagement.php';

// The typing quizzes — a different way to review the same global word list:
//
//   "Guess the Word"     definition -> type the word
//   "Fill in the Blank"  example sentence with the word removed -> type the word
//
// Answers are judged on the server (the browser never receives the answer, only
// the prompt), scored into points, and appended to quiz_attempts. Because a
// definition can legitimately describe more than one word, a wrong answer can be
// claimed as right anyway by the user — that's markAttemptCorrectAnyway(), which
// awards partial credit and keeps the honest record of what was typed.
//
// All SQL touching quiz_attempts lives here. The judging and blanking helpers
// are pure so the unit tests can exercise them directly.
class QuizManagement {
    public const MODE_GUESS_WORD = 'guess_word';
    public const MODE_FILL_BLANK = 'fill_blank';

    public const RESULT_CORRECT = 'correct';     // the word, spelled right
    public const RESULT_CLOSE = 'close';         // the word, one typo off
    public const RESULT_SYNONYM = 'synonym';     // a synonym we list for the word
    public const RESULT_INCORRECT = 'incorrect';

    public const POINTS_CORRECT = 10;
    public const POINTS_CLOSE = 8;
    public const POINTS_OVERRIDE = 5;            // "my answer was right anyway"

    // What a removed word is replaced with in a prompt. The quiz page splits on
    // runs of underscores to draw the blank, so keep this underscores-only.
    public const BLANK = '_____';

    private static function pdo(): PDO {
        return pdo();
    }

    private static function assertLoggedIn(?UserContext $ctx): void {
        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
    }

    public static function isValidMode(string $mode): bool {
        return in_array($mode, [self::MODE_GUESS_WORD, self::MODE_FILL_BLANK], true);
    }

    private static function assertValidMode(string $mode): void {
        if (!self::isValidMode($mode)) {
            throw new InvalidArgumentException('Unknown quiz mode: ' . $mode);
        }
    }

    public static function modeLabel(string $mode): string {
        return $mode === self::MODE_FILL_BLANK ? 'Fill in the Blank' : 'Guess the Word';
    }

    // ===== Building a round =====

    /**
     * A shuffled round of questions drawn from the chosen decks (an empty
     * $tagIds means every word; several tag ids means the union of those decks).
     * $limit caps the round — null takes everything available.
     *
     * Each question is [word_id, prompt, hint, letters, first_letter]. The answer
     * itself is deliberately absent: the client posts what was typed to
     * recordAnswer() and the server decides.
     */
    public static function buildQuizRound(string $mode, array $tagIds = [], ?int $limit = null): array {
        $questions = self::availableQuestions($mode, $tagIds);
        shuffle($questions);
        if ($limit !== null && $limit > 0 && count($questions) > $limit) {
            $questions = array_slice($questions, 0, $limit);
        }
        return $questions;
    }

    // How many questions the chosen decks can actually produce. Lower than the
    // word count in Fill in the Blank, where words without a usable example
    // sentence can't be asked at all.
    public static function countAvailableQuestions(string $mode, array $tagIds = []): int {
        return count(self::availableQuestions($mode, $tagIds));
    }

    private static function availableQuestions(string $mode, array $tagIds): array {
        self::assertValidMode($mode);

        $questions = [];
        foreach (self::fetchWordsForDecks($tagIds, $mode) as $row) {
            $question = self::buildQuestionForWord($row, $mode);
            if ($question !== null) {
                $questions[] = $question;
            }
        }
        return $questions;
    }

    // Candidate words for a round: the union of the given decks (all words when
    // no decks are named), pre-filtered to those the mode could possibly use.
    private static function fetchWordsForDecks(array $tagIds, string $mode): array {
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds), fn($id) => $id > 0)));

        $sql = 'SELECT DISTINCT w.id, w.word, w.definition, w.sentences, w.synonyms FROM words w';
        $params = [];
        if ($tagIds) {
            $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
            $sql .= ' INNER JOIN word_tags wt ON wt.word_id = w.id AND wt.tag_id IN (' . $placeholders . ')';
            $params = $tagIds;
        }
        if ($mode === self::MODE_FILL_BLANK) {
            $sql .= " WHERE w.sentences IS NOT NULL AND w.sentences <> ''";
        }
        $sql .= ' ORDER BY w.sort_order, w.id';

        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    // One question, or null when this word can't be asked in this mode (Fill in
    // the Blank needs an example sentence that actually uses the word).
    private static function buildQuestionForWord(array $row, string $mode): ?array {
        $word = (string)$row['word'];
        $definition = self::maskWordInText((string)$row['definition'], $word);
        $blankedSentence = self::buildFillBlankPrompt((string)($row['sentences'] ?? ''), $word);

        if ($mode === self::MODE_FILL_BLANK) {
            if ($blankedSentence === null) return null;
            $prompt = $blankedSentence;
            $hint = $definition;
        } else {
            $prompt = $definition;
            $hint = $blankedSentence ?? '';
        }

        return [
            'word_id' => (int)$row['id'],
            'prompt' => $prompt,
            'hint' => $hint,
            'letters' => mb_strlen($word),
            'first_letter' => mb_strtoupper(mb_substr($word, 0, 1)),
        ];
    }

    // ===== Blanking out the answer =====

    /**
     * An example sentence with the word blanked out, or null when the stored
     * sentences never actually use the word (nothing to blank = no question).
     * With several sentences, the first one using the word wins.
     */
    public static function buildFillBlankPrompt(string $sentences, string $word): ?string {
        $sentences = trim($sentences);
        if ($sentences === '' || trim($word) === '') return null;

        $pattern = self::wordFormPattern($word);
        foreach (preg_split('/\r\n|\r|\n/', $sentences) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && preg_match($pattern, $line)) {
                return (string)preg_replace($pattern, self::BLANK, $line);
            }
        }
        return null;
    }

    // Blank the word out of any text that happens to contain it — definitions
    // sometimes give the game away ("to abate is to lessen").
    public static function maskWordInText(string $text, string $word): string {
        if (trim($word) === '') return $text;
        return (string)preg_replace(self::wordFormPattern($word), self::BLANK, $text);
    }

    // The forms of the word a sentence actually used, lowercased ("abated",
    // "abating"). Fill in the Blank accepts these as well as the base word,
    // since the blank stands where an inflected form used to be.
    public static function findWordFormsInSentence(string $sentence, string $word): array {
        if (trim($sentence) === '' || trim($word) === '') return [];
        if (!preg_match_all(self::wordFormPattern($word), $sentence, $matches)) return [];
        return array_values(array_unique(array_map('mb_strtolower', $matches[0])));
    }

    // Matches the word and its everyday inflections, so "abate" also covers
    // "abated" / "abating" / "abatement". Longest alternative first so the
    // regex swallows the whole inflected form rather than just its stem.
    private static function wordFormPattern(string $word): string {
        $forms = self::wordFormAlternatives($word);
        $quoted = array_map(fn($form) => preg_quote($form, '/'), $forms);
        return '/\b(?:' . implode('|', $quoted) . ')\b/iu';
    }

    private static function wordFormAlternatives(string $word): array {
        $word = mb_strtolower(trim($word));
        $forms = [$word];

        foreach (['s', 'es', 'd', 'ed', 'ing', 'ly', 'ness', 'ment', 'er', 'est', 'ion', 'al', 'ive'] as $suffix) {
            $forms[] = $word . $suffix;
        }
        // "abate" -> "abating", "abation": the silent e is dropped first.
        if (str_ends_with($word, 'e')) {
            $stem = mb_substr($word, 0, -1);
            foreach (['ing', 'ed', 'ion', 'or', 'ation', 'ive', 'ance', 'al', 'y'] as $suffix) {
                $forms[] = $stem . $suffix;
            }
        }
        // "wary" -> "warier", "warily".
        if (str_ends_with($word, 'y')) {
            $stem = mb_substr($word, 0, -1);
            foreach (['ies', 'ied', 'ier', 'iest', 'ily', 'iness'] as $suffix) {
                $forms[] = $stem . $suffix;
            }
        }
        // "abet" -> "abetted": a short vowel doubles its final consonant.
        if (preg_match('/[aeiou][bdglmnprtvz]$/', $word)) {
            $doubled = $word . mb_substr($word, -1);
            foreach (['ing', 'ed', 'er', 'est', 'y'] as $suffix) {
                $forms[] = $doubled . $suffix;
            }
        }

        $forms = array_values(array_unique($forms));
        usort($forms, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        return $forms;
    }

    // ===== Judging an answer =====

    // Compare on letters alone: case, surrounding punctuation, and stray inner
    // spacing shouldn't decide whether someone knows a word.
    public static function normalizeAnswer(string $answer): string {
        $answer = mb_strtolower(trim($answer));
        $answer = (string)preg_replace('/[^\p{L}\p{N}\s\'-]+/u', ' ', $answer);
        $answer = (string)preg_replace('/\s+/u', ' ', $answer);
        return trim($answer);
    }

    /**
     * What the typed answer was worth, given the word row it was asked about:
     * RESULT_CORRECT, RESULT_CLOSE (one typo), RESULT_SYNONYM (a synonym we
     * list for this word — right idea, wrong word), or RESULT_INCORRECT.
     */
    public static function judgeAnswer(string $typed, array $wordRow, string $mode): string {
        self::assertValidMode($mode);

        $typed = self::normalizeAnswer($typed);
        if ($typed === '') return self::RESULT_INCORRECT;

        $word = (string)($wordRow['word'] ?? '');
        $accepted = [self::normalizeAnswer($word)];
        if ($mode === self::MODE_FILL_BLANK) {
            foreach (self::findWordFormsInSentence((string)($wordRow['sentences'] ?? ''), $word) as $form) {
                $accepted[] = self::normalizeAnswer($form);
            }
        }
        $accepted = array_values(array_filter(array_unique($accepted)));

        if (in_array($typed, $accepted, true)) {
            return self::RESULT_CORRECT;
        }
        foreach ($accepted as $candidate) {
            if (self::isCloseSpelling($typed, $candidate)) {
                return self::RESULT_CLOSE;
            }
        }
        foreach (self::parseSynonymList((string)($wordRow['synonyms'] ?? '')) as $synonym) {
            if (self::normalizeAnswer($synonym) === $typed) {
                return self::RESULT_SYNONYM;
            }
        }
        return self::RESULT_INCORRECT;
    }

    // One typo — a letter added, dropped, mistyped, or two letters swapped — in a
    // word long enough for the slip to be obviously a slip. Short words must be
    // exact, since at three letters a "typo" is usually a different word.
    private static function isCloseSpelling(string $typed, string $target): bool {
        if ($target === '' || mb_strlen($target) < 5) return false;
        // levenshtein() counts bytes; on accented input only exact matches count.
        if (strlen($typed) !== mb_strlen($typed) || strlen($target) !== mb_strlen($target)) return false;
        return levenshtein($typed, $target) <= 1 || self::isAdjacentSwap($typed, $target);
    }

    // "diegn" for "deign": two neighbouring letters typed the wrong way round.
    // Levenshtein scores that as two edits, but it's one slip of the fingers.
    private static function isAdjacentSwap(string $typed, string $target): bool {
        if (strlen($typed) !== strlen($target)) return false;

        $differing = [];
        for ($i = 0, $len = strlen($typed); $i < $len; $i++) {
            if ($typed[$i] !== $target[$i]) {
                $differing[] = $i;
                if (count($differing) > 2) return false;
            }
        }

        return count($differing) === 2
            && $differing[1] === $differing[0] + 1
            && $typed[$differing[0]] === $target[$differing[1]]
            && $typed[$differing[1]] === $target[$differing[0]];
    }

    // "reduce, diminish; lessen" -> ['reduce', 'diminish', 'lessen'].
    private static function parseSynonymList(string $synonyms): array {
        $out = [];
        foreach (preg_split('/[;,\/]/u', $synonyms) ?: [] as $synonym) {
            $synonym = trim($synonym);
            if ($synonym !== '') $out[] = $synonym;
        }
        return $out;
    }

    public static function pointsForResult(string $result): int {
        if ($result === self::RESULT_CORRECT) return self::POINTS_CORRECT;
        if ($result === self::RESULT_CLOSE) return self::POINTS_CLOSE;
        return 0;
    }

    // ===== Recording answers =====

    /**
     * Judge and record one typed answer. Returns everything the quiz page needs
     * to show the outcome — the verdict, the points earned, the word with its
     * definition/sentence/synonyms for reading, the attempt id (so the answer
     * can be claimed as right anyway), and the user's refreshed totals.
     */
    public static function recordAnswer(UserContext $ctx, int $wordId, string $mode, string $typedAnswer): array {
        self::assertLoggedIn($ctx);
        self::assertValidMode($mode);

        $word = WordManagement::findById($wordId);
        if (!$word) {
            throw new InvalidArgumentException('That word is no longer in the list.');
        }

        $typedAnswer = trim($typedAnswer);
        if (mb_strlen($typedAnswer) > 255) {
            $typedAnswer = mb_substr($typedAnswer, 0, 255);
        }

        $result = self::judgeAnswer($typedAnswer, $word, $mode);
        $points = self::pointsForResult($result);

        $st = self::pdo()->prepare(
            'INSERT INTO quiz_attempts (user_id, word_id, quiz_mode, answer_text, result, points_awarded)
             VALUES (?,?,?,?,?,?)'
        );
        $st->execute([$ctx->id, $wordId, $mode, $typedAnswer, $result, $points]);
        $attemptId = (int)self::pdo()->lastInsertId();

        ActivityLog::log($ctx, 'quiz.answered', [
            'word_id' => $wordId,
            'quiz_mode' => $mode,
            'result' => $result,
            'points' => $points,
        ]);

        return [
            'attempt_id' => $attemptId,
            'result' => $result,
            'points' => $points,
            'can_claim_correct' => $points === 0,
            'word' => (string)$word['word'],
            'definition' => (string)$word['definition'],
            'sentences' => (string)($word['sentences'] ?? ''),
            'synonyms' => (string)($word['synonyms'] ?? ''),
            'totals' => self::getQuizStatsForUser($ctx->id),
        ];
    }

    /**
     * "I was right anyway" — the escape hatch for definitions that fit more than
     * one word. Awards partial credit on an attempt that scored nothing, without
     * rewriting what the user actually typed. Idempotent, and refuses attempts
     * belonging to somebody else.
     */
    public static function markAttemptCorrectAnyway(UserContext $ctx, int $attemptId): array {
        self::assertLoggedIn($ctx);

        $st = self::pdo()->prepare('SELECT * FROM quiz_attempts WHERE id = ? AND user_id = ? LIMIT 1');
        $st->execute([$attemptId, $ctx->id]);
        $attempt = $st->fetch();
        if (!$attempt) {
            throw new RuntimeException('That answer could not be found.');
        }

        if (!empty($attempt['was_overridden'])) {
            return ['points' => (int)$attempt['points_awarded'], 'totals' => self::getQuizStatsForUser($ctx->id)];
        }
        if ((int)$attempt['points_awarded'] > 0) {
            throw new RuntimeException('That answer already counted as correct.');
        }

        $st = self::pdo()->prepare('UPDATE quiz_attempts SET was_overridden = 1, points_awarded = ? WHERE id = ?');
        $st->execute([self::POINTS_OVERRIDE, $attemptId]);

        ActivityLog::log($ctx, 'quiz.answer_claimed_correct', [
            'attempt_id' => $attemptId,
            'word_id' => (int)$attempt['word_id'],
        ]);

        return ['points' => self::POINTS_OVERRIDE, 'totals' => self::getQuizStatsForUser($ctx->id)];
    }

    // ===== Stats =====

    /**
     * The quiz half of a user's progress: lifetime points, questions answered,
     * how many landed (spelled right, near enough, or claimed), the resulting
     * accuracy percentage, and today's slice of the same.
     */
    public static function getQuizStatsForUser(int $userId): array {
        $landed = "SUM(result IN ('correct','close') OR was_overridden = 1)";

        $st = self::pdo()->prepare(
            "SELECT COUNT(*) AS answered, COALESCE(SUM(points_awarded), 0) AS points,
                    COALESCE({$landed}, 0) AS correct
             FROM quiz_attempts WHERE user_id = ?"
        );
        $st->execute([$userId]);
        $all = $st->fetch() ?: [];

        $st = self::pdo()->prepare(
            "SELECT COUNT(*) AS answered, COALESCE(SUM(points_awarded), 0) AS points
             FROM quiz_attempts WHERE user_id = ? AND created_at >= CURDATE()"
        );
        $st->execute([$userId]);
        $today = $st->fetch() ?: [];

        $answered = (int)($all['answered'] ?? 0);
        $correct = (int)($all['correct'] ?? 0);

        return [
            'points' => (int)($all['points'] ?? 0),
            'answered' => $answered,
            'correct' => $correct,
            'accuracy' => $answered > 0 ? (int)round(($correct / $answered) * 100) : 0,
            'points_today' => (int)($today['points'] ?? 0),
            'answered_today' => (int)($today['answered'] ?? 0),
        ];
    }
}
