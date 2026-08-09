<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WordCsvImportTest extends TestCase
{
    private UserContext $adminCtx;

    protected function setUp(): void
    {
        test_reset_all();
        $this->adminCtx = test_seed_admin();
    }

    public function testValidateRowsStatuses(): void
    {
        WordManagement::addWord($this->adminCtx, 'abate', 'old definition');

        $validated = WordCsvImport::validateRows([
            ['word' => 'Abate', 'definition' => 'to lessen'],       // matches existing (case-insensitive)
            ['word' => 'candor', 'definition' => 'honesty'],        // new
            ['word' => '', 'definition' => 'no word'],              // error
            ['word' => 'brusque', 'definition' => ''],              // error
            ['word' => 'CANDOR', 'definition' => 'duplicate'],      // in-file duplicate
        ]);

        $this->assertSame('valid', $validated[0]['status']);
        $this->assertSame('Update definition', $validated[0]['changes']);

        $this->assertSame('valid', $validated[1]['status']);
        $this->assertSame('Create new word', $validated[1]['changes']);

        $this->assertSame('error', $validated[2]['status']);
        $this->assertSame('error', $validated[3]['status']);

        $this->assertSame('error', $validated[4]['status']);
        $this->assertStringContainsString('Duplicate of row 2', $validated[4]['messages'][0]);
    }

    public function testCommitCreatesUpdatesAndSkips(): void
    {
        WordManagement::addWord($this->adminCtx, 'abate', 'old definition');

        $validated = WordCsvImport::validateRows([
            ['word' => 'abate', 'definition' => 'to lessen'],
            ['word' => 'candor', 'definition' => 'honesty'],
            ['word' => '', 'definition' => 'broken row'],
        ]);
        $summary = WordCsvImport::commit($this->adminCtx, $validated);

        $this->assertSame(['created' => 1, 'updated' => 1, 'skipped' => 1], $summary);

        $this->assertSame('to lessen', WordManagement::findByWordText('abate')['definition']);
        $candor = WordManagement::findByWordText('candor');
        $this->assertSame('honesty', $candor['definition']);
        $this->assertSame(2, (int)$candor['sort_order']); // appended after abate
        $this->assertSame(2, WordManagement::countWords());
    }

    public function testCommitAppendsMultipleNewWordsInOrder(): void
    {
        WordManagement::addWord($this->adminCtx, 'abate', 'to lessen');

        $validated = WordCsvImport::validateRows([
            ['word' => 'brusque', 'definition' => 'abrupt'],
            ['word' => 'candor', 'definition' => 'honesty'],
        ]);
        WordCsvImport::commit($this->adminCtx, $validated);

        $this->assertSame(2, (int)WordManagement::findByWordText('brusque')['sort_order']);
        $this->assertSame(3, (int)WordManagement::findByWordText('candor')['sort_order']);
    }

    public function testReimportIsIdempotent(): void
    {
        $rows = [
            ['word' => 'abate', 'definition' => 'to lessen'],
            ['word' => 'candor', 'definition' => 'honesty'],
        ];
        WordCsvImport::commit($this->adminCtx, WordCsvImport::validateRows($rows));
        $summary = WordCsvImport::commit($this->adminCtx, WordCsvImport::validateRows($rows));

        $this->assertSame(['created' => 0, 'updated' => 2, 'skipped' => 0], $summary);
        $this->assertSame(2, WordManagement::countWords());
    }

    public function testCommitRequiresAdmin(): void
    {
        $nonAdmin = test_seed_user();
        $validated = WordCsvImport::validateRows([['word' => 'abate', 'definition' => 'to lessen']]);
        $this->expectException(RuntimeException::class);
        WordCsvImport::commit($nonAdmin, $validated);
    }

    public function testCommitWritesActivityLog(): void
    {
        WordCsvImport::commit($this->adminCtx, WordCsvImport::validateRows([
            ['word' => 'abate', 'definition' => 'to lessen'],
        ]));

        $st = pdo()->prepare("SELECT * FROM activity_log WHERE action_type = 'words.imported'");
        $st->execute();
        $rows = $st->fetchAll();
        $this->assertCount(1, $rows);
        $meta = json_decode((string)$rows[0]['json_metadata'], true);
        $this->assertSame(1, $meta['created']);
    }
}
