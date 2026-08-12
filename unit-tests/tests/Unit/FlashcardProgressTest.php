<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FlashcardProgressTest extends TestCase
{
    private UserContext $adminCtx;
    private UserContext $userCtx;
    private array $wordIds = [];

    protected function setUp(): void
    {
        test_reset_all();
        $this->adminCtx = test_seed_admin();
        $this->userCtx = test_seed_user();

        $this->wordIds = [];
        foreach ([['abate', 'to lessen'], ['brusque', 'abrupt in manner'], ['candor', 'honesty']] as $pair) {
            $this->wordIds[] = WordManagement::addWord($this->adminCtx, $pair[0], $pair[1]);
        }
    }

    // --- markWord ---

    public function testMarkWordCreatesStateAndEvent(): void
    {
        $score = FlashcardProgress::markWord($this->userCtx, $this->wordIds[0], FlashcardProgress::MARK_GOT_IT);

        $st = pdo()->prepare('SELECT * FROM user_word_state WHERE user_id = ? AND word_id = ?');
        $st->execute([$this->userCtx->id, $this->wordIds[0]]);
        $state = $st->fetch();
        $this->assertSame('got_it', $state['last_mark']);
        $this->assertSame(1, (int)$state['got_it_count']);
        $this->assertSame(0, (int)$state['needs_review_count']);
        $this->assertNotNull($state['last_reviewed_at']);

        $this->assertSame(1, (int)pdo()->query('SELECT COUNT(*) FROM word_review_events')->fetchColumn());

        $this->assertSame(1, $score['mastered']);
        $this->assertSame(3, $score['total_words']);
        $this->assertSame(1, $score['reviewed_today']);
    }

    public function testMarkWordIncrementsCountersAndFlipsLastMark(): void
    {
        FlashcardProgress::markWord($this->userCtx, $this->wordIds[0], FlashcardProgress::MARK_GOT_IT);
        FlashcardProgress::markWord($this->userCtx, $this->wordIds[0], FlashcardProgress::MARK_NEEDS_REVIEW);
        $score = FlashcardProgress::markWord($this->userCtx, $this->wordIds[0], FlashcardProgress::MARK_NEEDS_REVIEW);

        $st = pdo()->prepare('SELECT * FROM user_word_state WHERE user_id = ? AND word_id = ?');
        $st->execute([$this->userCtx->id, $this->wordIds[0]]);
        $state = $st->fetch();
        $this->assertSame('needs_review', $state['last_mark']);
        $this->assertSame(1, (int)$state['got_it_count']);
        $this->assertSame(2, (int)$state['needs_review_count']);

        // One state row, three events; no longer mastered.
        $this->assertSame(1, (int)pdo()->query('SELECT COUNT(*) FROM user_word_state')->fetchColumn());
        $this->assertSame(3, (int)pdo()->query('SELECT COUNT(*) FROM word_review_events')->fetchColumn());
        $this->assertSame(0, $score['mastered']);
        $this->assertSame(3, $score['reviewed_today']);
    }

    public function testMarkWordPersistsDeckPosition(): void
    {
        FlashcardProgress::markWord($this->userCtx, $this->wordIds[0], FlashcardProgress::MARK_GOT_IT, 7);
        $this->assertSame(7, FlashcardProgress::deckPositionForUser($this->userCtx->id));
    }

    public function testMarkWordPersistsPositionPerTagDeck(): void
    {
        WordManagement::setWordTags($this->adminCtx, $this->wordIds[0], ['Green']);
        $tags = array_column(WordManagement::listAllTags(), null, 'name');
        $greenId = (int)$tags['Green']['id'];

        FlashcardProgress::markWord($this->userCtx, $this->wordIds[0], FlashcardProgress::MARK_GOT_IT, 5, $greenId);

        // The Green deck remembers its own place; the full deck is untouched.
        $this->assertSame(5, FlashcardProgress::deckPositionForUser($this->userCtx->id, $greenId));
        $this->assertSame(0, FlashcardProgress::deckPositionForUser($this->userCtx->id));

        // And the mark itself landed regardless of which deck it came from.
        $this->assertSame(1, FlashcardProgress::getScoreSummary($this->userCtx->id)['mastered']);
    }

    public function testSaveDeckPositionUpsertsPerTag(): void
    {
        WordManagement::setWordTags($this->adminCtx, $this->wordIds[0], ['Green']);
        $tags = array_column(WordManagement::listAllTags(), null, 'name');
        $greenId = (int)$tags['Green']['id'];

        FlashcardProgress::saveDeckPosition($this->userCtx, 3, $greenId);
        FlashcardProgress::saveDeckPosition($this->userCtx, 9, $greenId);
        FlashcardProgress::saveDeckPosition($this->userCtx, 4);

        $this->assertSame(9, FlashcardProgress::deckPositionForUser($this->userCtx->id, $greenId));
        $this->assertSame(4, FlashcardProgress::deckPositionForUser($this->userCtx->id));
    }

    public function testShuffleResetsTagDeckPositionsToo(): void
    {
        WordManagement::setWordTags($this->adminCtx, $this->wordIds[0], ['Green']);
        $tags = array_column(WordManagement::listAllTags(), null, 'name');
        $greenId = (int)$tags['Green']['id'];

        FlashcardProgress::saveDeckPosition($this->userCtx, 5, $greenId);
        FlashcardProgress::saveDeckPosition($this->userCtx, 2);

        FlashcardProgress::shuffleDeck($this->userCtx);
        $this->assertSame(0, FlashcardProgress::deckPositionForUser($this->userCtx->id, $greenId));
        $this->assertSame(0, FlashcardProgress::deckPositionForUser($this->userCtx->id));

        FlashcardProgress::saveDeckPosition($this->userCtx, 5, $greenId);
        FlashcardProgress::restoreOriginalOrder($this->userCtx);
        $this->assertSame(0, FlashcardProgress::deckPositionForUser($this->userCtx->id, $greenId));
    }

    public function testMarkWordRejectsUnknownMark(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FlashcardProgress::markWord($this->userCtx, $this->wordIds[0], 'maybe');
    }

    public function testMarksAreScopedPerUser(): void
    {
        FlashcardProgress::markWord($this->userCtx, $this->wordIds[0], FlashcardProgress::MARK_GOT_IT);

        $otherScore = FlashcardProgress::getScoreSummary($this->adminCtx->id);
        $this->assertSame(0, $otherScore['mastered']);
        $this->assertSame(0, $otherScore['reviewed_today']);
    }

    // --- flags ---

    public function testSetWordFlagUpsertsAndToggles(): void
    {
        FlashcardProgress::setWordFlag($this->userCtx, $this->wordIds[1], true);

        $deck = FlashcardProgress::getDeckForUser($this->userCtx->id, FlashcardProgress::DECK_FLAGGED);
        $this->assertCount(1, $deck);
        $this->assertSame('brusque', $deck[0]['word']);

        FlashcardProgress::setWordFlag($this->userCtx, $this->wordIds[1], false);
        $this->assertCount(0, FlashcardProgress::getDeckForUser($this->userCtx->id, FlashcardProgress::DECK_FLAGGED));
    }

    public function testFlagsAreScopedPerUser(): void
    {
        FlashcardProgress::setWordFlag($this->userCtx, $this->wordIds[1], true);
        $this->assertCount(0, FlashcardProgress::getDeckForUser($this->adminCtx->id, FlashcardProgress::DECK_FLAGGED));
    }

    // --- deck ordering ---

    public function testDeckFollowsSortOrderWithoutShuffle(): void
    {
        $deck = FlashcardProgress::getDeckForUser($this->userCtx->id);
        $this->assertSame(['abate', 'brusque', 'candor'], array_column($deck, 'word'));
    }

    public function testShuffleIsDeterministicPerSeed(): void
    {
        FlashcardProgress::shuffleDeck($this->userCtx);
        $this->assertTrue(FlashcardProgress::isDeckShuffledForUser($this->userCtx->id));

        $first = array_column(FlashcardProgress::getDeckForUser($this->userCtx->id), 'id');
        $second = array_column(FlashcardProgress::getDeckForUser($this->userCtx->id), 'id');
        $this->assertSame($first, $second);
        $this->assertEqualsCanonicalizing($this->wordIds, array_map('intval', $first));
    }

    public function testShuffleResetsDeckPosition(): void
    {
        FlashcardProgress::saveDeckPosition($this->userCtx, 2);
        FlashcardProgress::shuffleDeck($this->userCtx);
        $this->assertSame(0, FlashcardProgress::deckPositionForUser($this->userCtx->id));
    }

    public function testRestoreOriginalOrder(): void
    {
        FlashcardProgress::shuffleDeck($this->userCtx);
        FlashcardProgress::restoreOriginalOrder($this->userCtx);

        $this->assertFalse(FlashcardProgress::isDeckShuffledForUser($this->userCtx->id));
        $deck = FlashcardProgress::getDeckForUser($this->userCtx->id);
        $this->assertSame(['abate', 'brusque', 'candor'], array_column($deck, 'word'));
    }

    public function testDifferentSeedsGiveDifferentOrders(): void
    {
        // With only 3 words two seeds can collide; use enough words to make a
        // collision across every attempt effectively impossible.
        for ($i = 0; $i < 12; $i++) {
            WordManagement::addWord($this->adminCtx, 'filler' . $i, 'definition ' . $i);
        }

        FlashcardProgress::shuffleDeck($this->userCtx);
        $first = array_column(FlashcardProgress::getDeckForUser($this->userCtx->id), 'id');

        $differs = false;
        for ($attempt = 0; $attempt < 5 && !$differs; $attempt++) {
            FlashcardProgress::shuffleDeck($this->userCtx);
            $differs = array_column(FlashcardProgress::getDeckForUser($this->userCtx->id), 'id') !== $first;
        }
        $this->assertTrue($differs, 'Reshuffling never changed the order');
    }

    public function testDeckFilteredByTag(): void
    {
        WordManagement::setWordTags($this->adminCtx, $this->wordIds[0], ['Green']);
        WordManagement::setWordTags($this->adminCtx, $this->wordIds[2], ['Green', 'White and Blue']);
        $tags = array_column(WordManagement::listAllTags(), null, 'name');

        $green = FlashcardProgress::getDeckForUser($this->userCtx->id, FlashcardProgress::DECK_ALL, (int)$tags['Green']['id']);
        $this->assertSame(['abate', 'candor'], array_column($green, 'word'));

        $whiteBlue = FlashcardProgress::getDeckForUser($this->userCtx->id, FlashcardProgress::DECK_ALL, (int)$tags['White and Blue']['id']);
        $this->assertSame(['candor'], array_column($whiteBlue, 'word'));

        // No tag filter = the whole deck
        $this->assertCount(3, FlashcardProgress::getDeckForUser($this->userCtx->id));
    }

    public function testTagFilterCombinesWithStatusFilters(): void
    {
        WordManagement::setWordTags($this->adminCtx, $this->wordIds[0], ['Green']);
        WordManagement::setWordTags($this->adminCtx, $this->wordIds[1], ['Green']);
        $tags = array_column(WordManagement::listAllTags(), null, 'name');
        $greenId = (int)$tags['Green']['id'];

        FlashcardProgress::markWord($this->userCtx, $this->wordIds[0], FlashcardProgress::MARK_NEEDS_REVIEW);
        FlashcardProgress::markWord($this->userCtx, $this->wordIds[2], FlashcardProgress::MARK_NEEDS_REVIEW); // untagged
        FlashcardProgress::setWordFlag($this->userCtx, $this->wordIds[1], true);

        $greenMisses = FlashcardProgress::getDeckForUser($this->userCtx->id, FlashcardProgress::DECK_NEEDS_REVIEW, $greenId);
        $this->assertSame(['abate'], array_column($greenMisses, 'word'));

        $greenFlagged = FlashcardProgress::getDeckForUser($this->userCtx->id, FlashcardProgress::DECK_FLAGGED, $greenId);
        $this->assertSame(['brusque'], array_column($greenFlagged, 'word'));
    }

    public function testNeedsReviewDeckFilter(): void
    {
        FlashcardProgress::markWord($this->userCtx, $this->wordIds[0], FlashcardProgress::MARK_NEEDS_REVIEW);
        FlashcardProgress::markWord($this->userCtx, $this->wordIds[2], FlashcardProgress::MARK_GOT_IT);

        $deck = FlashcardProgress::getDeckForUser($this->userCtx->id, FlashcardProgress::DECK_NEEDS_REVIEW);
        $this->assertSame(['abate'], array_column($deck, 'word'));
    }

    // --- stats ---

    public function testGetStatsForUser(): void
    {
        FlashcardProgress::markWord($this->userCtx, $this->wordIds[0], FlashcardProgress::MARK_GOT_IT);
        FlashcardProgress::markWord($this->userCtx, $this->wordIds[1], FlashcardProgress::MARK_NEEDS_REVIEW);
        FlashcardProgress::setWordFlag($this->userCtx, $this->wordIds[2], true);

        $stats = FlashcardProgress::getStatsForUser($this->userCtx->id, null, 14);
        $this->assertSame(1, $stats['mastered']);
        $this->assertSame(1, $stats['needs_review']);
        $this->assertSame(1, $stats['flagged']);
        $this->assertSame(2, $stats['total_reviews']);
        $this->assertSame(2, $stats['reviewed_today']);
        $this->assertCount(14, $stats['daily_reviews']);
        $this->assertSame(2, end($stats['daily_reviews'])['count']); // today is the last bucket
    }

    public function testLearnedWordCounts(): void
    {
        WordManagement::setWordTags($this->adminCtx, $this->wordIds[0], ['Green']);
        WordManagement::setWordTags($this->adminCtx, $this->wordIds[1], ['Green']);
        $tags = array_column(WordManagement::listAllTags(), null, 'name');
        $greenId = (int)$tags['Green']['id'];

        // abate: got it then missed — still learned. brusque: only missed,
        // never learned. candor: learned but outside the Green deck.
        FlashcardProgress::markWord($this->userCtx, $this->wordIds[0], FlashcardProgress::MARK_GOT_IT);
        FlashcardProgress::markWord($this->userCtx, $this->wordIds[0], FlashcardProgress::MARK_NEEDS_REVIEW);
        FlashcardProgress::markWord($this->userCtx, $this->wordIds[1], FlashcardProgress::MARK_NEEDS_REVIEW);
        FlashcardProgress::markWord($this->userCtx, $this->wordIds[2], FlashcardProgress::MARK_GOT_IT);

        $counts = FlashcardProgress::learnedWordCounts($this->userCtx->id);
        $this->assertSame(2, $counts['total']);
        $this->assertSame([$greenId => 1], $counts['by_tag']);

        // Other users only see their own progress.
        $this->assertSame(['total' => 0, 'by_tag' => []], FlashcardProgress::learnedWordCounts($this->adminCtx->id));
    }

    public function testStatsScopedToOneDeck(): void
    {
        // Green holds abate + brusque; candor stays outside the deck.
        WordManagement::setWordTags($this->adminCtx, $this->wordIds[0], ['Green']);
        WordManagement::setWordTags($this->adminCtx, $this->wordIds[1], ['Green']);
        $tags = array_column(WordManagement::listAllTags(), null, 'name');
        $greenId = (int)$tags['Green']['id'];

        FlashcardProgress::markWord($this->userCtx, $this->wordIds[0], FlashcardProgress::MARK_GOT_IT);
        FlashcardProgress::markWord($this->userCtx, $this->wordIds[1], FlashcardProgress::MARK_NEEDS_REVIEW);
        FlashcardProgress::markWord($this->userCtx, $this->wordIds[2], FlashcardProgress::MARK_NEEDS_REVIEW);
        FlashcardProgress::setWordFlag($this->userCtx, $this->wordIds[2], true);

        $stats = FlashcardProgress::getStatsForUser($this->userCtx->id, $greenId);
        $this->assertSame(1, $stats['mastered']);
        $this->assertSame(2, $stats['total_words']);
        $this->assertSame(1, $stats['needs_review']);   // candor's miss is outside the deck
        $this->assertSame(0, $stats['flagged']);        // so is its flag
        $this->assertSame(2, $stats['total_reviews']);
        $this->assertSame(2, $stats['reviewed_today']);
        $this->assertSame(2, end($stats['daily_reviews'])['count']);

        // The unscoped numbers still see everything.
        $all = FlashcardProgress::getStatsForUser($this->userCtx->id);
        $this->assertSame(3, $all['total_words']);
        $this->assertSame(2, $all['needs_review']);
        $this->assertSame(1, $all['flagged']);
        $this->assertSame(3, $all['total_reviews']);
    }
}
