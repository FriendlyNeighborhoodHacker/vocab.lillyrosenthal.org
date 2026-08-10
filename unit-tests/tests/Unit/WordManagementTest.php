<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WordManagementTest extends TestCase
{
    private UserContext $adminCtx;

    protected function setUp(): void
    {
        test_reset_all();
        $this->adminCtx = test_seed_admin();
    }

    public function testAddWordAppendsToOrder(): void
    {
        $id1 = WordManagement::addWord($this->adminCtx, 'abate', 'to lessen');
        $id2 = WordManagement::addWord($this->adminCtx, 'ephemeral', 'short-lived');

        $w1 = WordManagement::findById($id1);
        $w2 = WordManagement::findById($id2);
        $this->assertSame(1, (int)$w1['sort_order']);
        $this->assertSame(2, (int)$w2['sort_order']);
        $this->assertSame('abate', $w1['word']);
        $this->assertSame('to lessen', $w1['definition']);
        $this->assertNull($w1['sentences']);
        $this->assertNull($w1['synonyms']);
    }

    public function testAddWordStoresSentencesAndSynonyms(): void
    {
        $id = WordManagement::addWord($this->adminCtx, 'abate', 'to lessen', 'The storm abated.', 'subside, diminish');

        $word = WordManagement::findById($id);
        $this->assertSame('The storm abated.', $word['sentences']);
        $this->assertSame('subside, diminish', $word['synonyms']);
    }

    public function testAddWordStoresBlankOptionalFieldsAsNull(): void
    {
        $id = WordManagement::addWord($this->adminCtx, 'abate', 'to lessen', '   ', '');

        $word = WordManagement::findById($id);
        $this->assertNull($word['sentences']);
        $this->assertNull($word['synonyms']);
    }

    public function testAddWordRejectsDuplicateCaseInsensitively(): void
    {
        WordManagement::addWord($this->adminCtx, 'Abate', 'to lessen');
        $this->expectException(InvalidArgumentException::class);
        WordManagement::addWord($this->adminCtx, 'abate', 'again');
    }

    public function testAddWordRequiresDefinition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WordManagement::addWord($this->adminCtx, 'abate', '   ');
    }

    public function testAddWordRequiresAdmin(): void
    {
        $nonAdmin = test_seed_user();
        $this->expectException(RuntimeException::class);
        WordManagement::addWord($nonAdmin, 'abate', 'to lessen');
    }

    public function testAddWordWritesActivityLog(): void
    {
        $id = WordManagement::addWord($this->adminCtx, 'abate', 'to lessen');

        $st = pdo()->prepare("SELECT * FROM activity_log WHERE action_type = 'word.create'");
        $st->execute();
        $rows = $st->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame($this->adminCtx->id, (int)$rows[0]['user_id']);
        $meta = json_decode((string)$rows[0]['json_metadata'], true);
        $this->assertSame($id, $meta['word_id']);
    }

    public function testUpdateWord(): void
    {
        $id = WordManagement::addWord($this->adminCtx, 'abate', 'to lessen');

        $ok = WordManagement::updateWord($this->adminCtx, $id, 'abate', 'to become less intense', 'The storm abated.', 'subside', 5);
        $this->assertTrue($ok);

        $word = WordManagement::findById($id);
        $this->assertSame('to become less intense', $word['definition']);
        $this->assertSame('The storm abated.', $word['sentences']);
        $this->assertSame('subside', $word['synonyms']);
        $this->assertSame(5, (int)$word['sort_order']);
    }

    public function testUpdateWordClearsOptionalFieldsWhenBlank(): void
    {
        $id = WordManagement::addWord($this->adminCtx, 'abate', 'to lessen', 'The storm abated.', 'subside');

        WordManagement::updateWord($this->adminCtx, $id, 'abate', 'to lessen', '', '', 1);

        $word = WordManagement::findById($id);
        $this->assertNull($word['sentences']);
        $this->assertNull($word['synonyms']);
    }

    public function testUpdateWordRejectsCollidingRename(): void
    {
        WordManagement::addWord($this->adminCtx, 'abate', 'to lessen');
        $id2 = WordManagement::addWord($this->adminCtx, 'ephemeral', 'short-lived');

        $this->expectException(InvalidArgumentException::class);
        WordManagement::updateWord($this->adminCtx, $id2, 'ABATE', 'colliding', null, null, 2);
    }

    public function testDeleteWordCascadesUserState(): void
    {
        $id = WordManagement::addWord($this->adminCtx, 'abate', 'to lessen');
        FlashcardProgress::markWord($this->adminCtx, $id, FlashcardProgress::MARK_GOT_IT);

        $this->assertTrue(WordManagement::deleteWord($this->adminCtx, $id));
        $this->assertNull(WordManagement::findById($id));

        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM user_word_state')->fetchColumn());
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM word_review_events')->fetchColumn());
    }

    public function testFindByWordTextIsCaseInsensitive(): void
    {
        $id = WordManagement::addWord($this->adminCtx, 'Ephemeral', 'short-lived');
        $found = WordManagement::findByWordText('ePHEMERAL');
        $this->assertNotNull($found);
        $this->assertSame($id, (int)$found['id']);
    }

    // --- Tags (decks) ---

    public function testParseTagList(): void
    {
        $this->assertSame(['White and Blue', 'Green'], WordManagement::parseTagList('White and Blue; Green'));
        $this->assertSame(['Green', 'Red'], WordManagement::parseTagList(' Green , Red '));
        $this->assertSame(['Green'], WordManagement::parseTagList('Green, GREEN')); // case-insensitive dedupe
        $this->assertSame([], WordManagement::parseTagList('  ; , '));
    }

    public function testSetWordTagsCreatesAndReplaces(): void
    {
        $id = WordManagement::addWord($this->adminCtx, 'abate', 'to lessen');

        WordManagement::setWordTags($this->adminCtx, $id, ['White and Blue', 'Green']);
        $this->assertSame(['Green', 'White and Blue'], WordManagement::tagNamesForWord($id));

        // Replacing keeps the shared tag row and drops the removed link
        WordManagement::setWordTags($this->adminCtx, $id, ['Green']);
        $this->assertSame(['Green'], WordManagement::tagNamesForWord($id));

        // Clearing removes the word from every deck
        WordManagement::setWordTags($this->adminCtx, $id, []);
        $this->assertSame([], WordManagement::tagNamesForWord($id));
    }

    public function testTagNamesMatchCaseInsensitively(): void
    {
        $id1 = WordManagement::addWord($this->adminCtx, 'abate', 'to lessen');
        $id2 = WordManagement::addWord($this->adminCtx, 'candor', 'honesty');

        WordManagement::setWordTags($this->adminCtx, $id1, ['Green']);
        WordManagement::setWordTags($this->adminCtx, $id2, ['green']);

        // One shared tag row, both words attached
        $tags = WordManagement::listAllTags();
        $this->assertCount(1, $tags);
        $this->assertSame(2, (int)$tags[0]['word_count']);
    }

    public function testListWordsFilteredByTag(): void
    {
        $id1 = WordManagement::addWord($this->adminCtx, 'abate', 'to lessen');
        $id2 = WordManagement::addWord($this->adminCtx, 'verdant', 'green with vegetation');
        WordManagement::setWordTags($this->adminCtx, $id1, ['White and Blue']);
        WordManagement::setWordTags($this->adminCtx, $id2, ['Green']);

        $tags = WordManagement::listAllTags();
        $byName = array_column($tags, null, 'name');

        $greenWords = WordManagement::listWordsInGlobalOrder((int)$byName['Green']['id']);
        $this->assertSame(['verdant'], array_column($greenWords, 'word'));

        $all = WordManagement::listWordsInGlobalOrder();
        $this->assertCount(2, $all);
        $this->assertSame('White and Blue', $all[0]['tags']);
    }

    public function testListAndCount(): void
    {
        WordManagement::addWord($this->adminCtx, 'beta', 'second', null, null, 2);
        WordManagement::addWord($this->adminCtx, 'alpha', 'first', null, null, 1);

        $words = WordManagement::listWordsInGlobalOrder();
        $this->assertSame(['alpha', 'beta'], array_column($words, 'word'));
        $this->assertSame(2, WordManagement::countWords());
        $this->assertSame(3, WordManagement::nextSortOrder());
    }

    public function testListWordTextsIsAlphabeticalAndFollowsDecks(): void
    {
        // Deck order differs from alphabetical order on purpose.
        $verdant = WordManagement::addWord($this->adminCtx, 'verdant', 'green with vegetation');
        $abate = WordManagement::addWord($this->adminCtx, 'abate', 'to lessen');
        $brusque = WordManagement::addWord($this->adminCtx, 'brusque', 'abrupt in manner');
        WordManagement::setWordTags($this->adminCtx, $verdant, ['Green']);
        WordManagement::setWordTags($this->adminCtx, $abate, ['Green', 'White and Blue']);
        WordManagement::setWordTags($this->adminCtx, $brusque, ['White and Blue']);

        $this->assertSame(['abate', 'brusque', 'verdant'], WordManagement::listWordTexts());

        $byName = array_column(WordManagement::listAllTags(), null, 'name');
        $greenId = (int)$byName['Green']['id'];
        $whiteBlueId = (int)$byName['White and Blue']['id'];

        $this->assertSame(['abate', 'verdant'], WordManagement::listWordTexts([$greenId]));
        // The union of two decks, with the word tagged in both appearing once.
        $this->assertSame(['abate', 'brusque', 'verdant'], WordManagement::listWordTexts([$greenId, $whiteBlueId]));
    }
}
