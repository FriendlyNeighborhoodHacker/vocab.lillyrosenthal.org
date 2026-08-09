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

        $ok = WordManagement::updateWord($this->adminCtx, $id, 'abate', 'to become less intense', 5);
        $this->assertTrue($ok);

        $word = WordManagement::findById($id);
        $this->assertSame('to become less intense', $word['definition']);
        $this->assertSame(5, (int)$word['sort_order']);
    }

    public function testUpdateWordRejectsCollidingRename(): void
    {
        WordManagement::addWord($this->adminCtx, 'abate', 'to lessen');
        $id2 = WordManagement::addWord($this->adminCtx, 'ephemeral', 'short-lived');

        $this->expectException(InvalidArgumentException::class);
        WordManagement::updateWord($this->adminCtx, $id2, 'ABATE', 'colliding', 2);
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

    public function testListAndCount(): void
    {
        WordManagement::addWord($this->adminCtx, 'beta', 'second', 2);
        WordManagement::addWord($this->adminCtx, 'alpha', 'first', 1);

        $words = WordManagement::listWordsInGlobalOrder();
        $this->assertSame(['alpha', 'beta'], array_column($words, 'word'));
        $this->assertSame(2, WordManagement::countWords());
        $this->assertSame(3, WordManagement::nextSortOrder());
    }
}
