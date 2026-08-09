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
            ['word' => 'brusque', 'definition' => ''],              // error: new word needs a definition
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

    public function testValidateReportsWhichFieldsChange(): void
    {
        WordManagement::addWord($this->adminCtx, 'abate', 'to lessen', 'Old sentence.', 'subside');

        $validated = WordCsvImport::validateRows([
            ['word' => 'abate', 'definition' => 'to lessen', 'sentences' => 'The storm abated.', 'synonyms' => 'diminish'],
            ['word' => 'abate', 'definition' => 'to lessen', 'sentences' => 'Old sentence.', 'synonyms' => 'subside'],
        ]);

        $this->assertSame('Update sentences, synonyms', $validated[0]['changes']);
        // (Row 2 is an in-file duplicate in this synthetic case, so check via a fresh call.)
        $noChange = WordCsvImport::validateRows([
            ['word' => 'abate', 'definition' => 'to lessen', 'sentences' => 'Old sentence.', 'synonyms' => 'subside'],
        ]);
        $this->assertSame('No changes', $noChange[0]['changes']);
        $this->assertSame('valid', $noChange[0]['status']);
    }

    public function testValidateRejectsBlankDefinitionForExistingWord(): void
    {
        WordManagement::addWord($this->adminCtx, 'abate', 'to lessen');

        $validated = WordCsvImport::validateRows([
            ['word' => 'abate', 'definition' => '', 'synonyms' => 'subside'],
        ]);
        $this->assertSame('error', $validated[0]['status']);
        $this->assertStringContainsString('Definition cannot be blank', $validated[0]['messages'][0]);
    }

    public function testValidateAllowsUnmappedDefinitionForExistingWord(): void
    {
        WordManagement::addWord($this->adminCtx, 'abate', 'to lessen');

        // Only word + synonyms mapped: existing word keeps its definition.
        $validated = WordCsvImport::validateRows([
            ['word' => 'abate', 'synonyms' => 'subside'],
        ]);
        $this->assertSame('valid', $validated[0]['status']);
        $this->assertSame('Update synonyms', $validated[0]['changes']);
    }

    public function testCommitCreatesUpdatesAndSkips(): void
    {
        WordManagement::addWord($this->adminCtx, 'abate', 'old definition');

        $validated = WordCsvImport::validateRows([
            ['word' => 'abate', 'definition' => 'to lessen', 'sentences' => 'The storm abated.', 'synonyms' => 'subside'],
            ['word' => 'candor', 'definition' => 'honesty', 'sentences' => 'She spoke with candor.', 'synonyms' => 'frankness'],
            ['word' => '', 'definition' => 'broken row'],
        ]);
        $summary = WordCsvImport::commit($this->adminCtx, $validated);

        $this->assertSame(['created' => 1, 'updated' => 1, 'unchanged' => 0, 'skipped' => 1], $summary);

        $abate = WordManagement::findByWordText('abate');
        $this->assertSame('to lessen', $abate['definition']);
        $this->assertSame('The storm abated.', $abate['sentences']);
        $this->assertSame('subside', $abate['synonyms']);

        $candor = WordManagement::findByWordText('candor');
        $this->assertSame('honesty', $candor['definition']);
        $this->assertSame('She spoke with candor.', $candor['sentences']);
        $this->assertSame('frankness', $candor['synonyms']);
        $this->assertSame(2, (int)$candor['sort_order']); // appended after abate
        $this->assertSame(2, WordManagement::countWords());
    }

    public function testCommitUpdatesOnlyMappedFields(): void
    {
        WordManagement::addWord($this->adminCtx, 'abate', 'to lessen', 'Old sentence.', 'subside');

        // Only word + synonyms mapped: definition and sentences must survive.
        $validated = WordCsvImport::validateRows([
            ['word' => 'abate', 'synonyms' => 'diminish, wane'],
        ]);
        $summary = WordCsvImport::commit($this->adminCtx, $validated);

        $this->assertSame(1, $summary['updated']);
        $abate = WordManagement::findByWordText('abate');
        $this->assertSame('to lessen', $abate['definition']);
        $this->assertSame('Old sentence.', $abate['sentences']);
        $this->assertSame('diminish, wane', $abate['synonyms']);
    }

    public function testCommitClearsOptionalFieldWhenMappedBlank(): void
    {
        WordManagement::addWord($this->adminCtx, 'abate', 'to lessen', 'Old sentence.', 'subside');

        $validated = WordCsvImport::validateRows([
            ['word' => 'abate', 'sentences' => '', 'synonyms' => 'subside'],
        ]);
        WordCsvImport::commit($this->adminCtx, $validated);

        $abate = WordManagement::findByWordText('abate');
        $this->assertNull($abate['sentences']);
        $this->assertSame('subside', $abate['synonyms']);
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
            ['word' => 'abate', 'definition' => 'to lessen', 'sentences' => 'The storm abated.', 'synonyms' => 'subside'],
            ['word' => 'candor', 'definition' => 'honesty', 'sentences' => '', 'synonyms' => ''],
        ];
        WordCsvImport::commit($this->adminCtx, WordCsvImport::validateRows($rows));
        $summary = WordCsvImport::commit($this->adminCtx, WordCsvImport::validateRows($rows));

        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 2, 'skipped' => 0], $summary);
        $this->assertSame(2, WordManagement::countWords());
    }

    // --- Tags (decks) ---

    public function testCommitCreatesWordsWithTags(): void
    {
        $validated = WordCsvImport::validateRows([
            ['word' => 'abate', 'definition' => 'to lessen', 'tags' => 'White and Blue'],
            ['word' => 'verdant', 'definition' => 'green with vegetation', 'tags' => 'Green; White and Blue'],
        ]);
        WordCsvImport::commit($this->adminCtx, $validated);

        $abate = WordManagement::findByWordText('abate');
        $verdant = WordManagement::findByWordText('verdant');
        $this->assertSame(['White and Blue'], WordManagement::tagNamesForWord((int)$abate['id']));
        $this->assertSame(['Green', 'White and Blue'], WordManagement::tagNamesForWord((int)$verdant['id']));
        $this->assertCount(2, WordManagement::listAllTags()); // tags auto-created, shared
    }

    public function testImportUpdatesTagsOnExistingWords(): void
    {
        $id = WordManagement::addWord($this->adminCtx, 'abate', 'to lessen');

        // Word + tags only: definition untouched, tags applied
        $validated = WordCsvImport::validateRows([
            ['word' => 'abate', 'tags' => 'Green'],
        ]);
        $this->assertSame('Update tags', $validated[0]['changes']);
        $summary = WordCsvImport::commit($this->adminCtx, $validated);

        $this->assertSame(1, $summary['updated']);
        $this->assertSame(['Green'], WordManagement::tagNamesForWord($id));
        $this->assertSame('to lessen', WordManagement::findById($id)['definition']);
    }

    public function testTagsUnchangedWhenSameSetInAnyOrderOrCase(): void
    {
        $id = WordManagement::addWord($this->adminCtx, 'abate', 'to lessen');
        WordManagement::setWordTags($this->adminCtx, $id, ['White and Blue', 'Green']);

        $validated = WordCsvImport::validateRows([
            ['word' => 'abate', 'tags' => 'green, WHITE AND BLUE'],
        ]);
        $this->assertSame('No changes', $validated[0]['changes']);

        $summary = WordCsvImport::commit($this->adminCtx, $validated);
        $this->assertSame(1, $summary['unchanged']);
    }

    public function testBlankMappedTagsClearsTags(): void
    {
        $id = WordManagement::addWord($this->adminCtx, 'abate', 'to lessen');
        WordManagement::setWordTags($this->adminCtx, $id, ['Green']);

        $validated = WordCsvImport::validateRows([
            ['word' => 'abate', 'tags' => ''],
        ]);
        $this->assertSame('Update tags', $validated[0]['changes']);
        WordCsvImport::commit($this->adminCtx, $validated);

        $this->assertSame([], WordManagement::tagNamesForWord($id));
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
