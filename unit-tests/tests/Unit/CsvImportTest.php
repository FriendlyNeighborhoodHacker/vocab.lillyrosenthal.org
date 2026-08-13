<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CsvImportTest extends TestCase
{
    public function testParseCommaCsv(): void
    {
        $parsed = CsvImport::parseCsv("word,definition\nabate,\"to lessen, reduce\"\ncandor,honesty");

        $this->assertSame(['word', 'definition'], $parsed['headers']);
        $this->assertSame([
            ['abate', 'to lessen, reduce'],
            ['candor', 'honesty'],
        ], $parsed['rows']);
    }

    public function testParseTabCsvAndPadsShortRows(): void
    {
        $parsed = CsvImport::parseCsv("word\tdefinition\nabate\tto lessen\ncandor", "\t");

        $this->assertSame([
            ['abate', 'to lessen'],
            ['candor', ''],
        ], $parsed['rows']);
    }

    // A short row is ordinary CSV; a row with extra values means a delimiter
    // inside a cell split it, which shifts every later column.
    public function testParseFlagsRowsWithMoreValuesThanColumns(): void
    {
        $parsed = CsvImport::parseCsv(
            "word,definition,tags\n"
            . "abate,to lessen,Green\n"
            . "candor,honesty, and openness,Green\n"
            . "dearth,a lack\n"
        );

        // Row index 1 really had 4 values; row 2 is merely short.
        $this->assertSame([1 => 4], $parsed['overlong']);
        // The overflow is still dropped, which is exactly why it is flagged.
        $this->assertSame(['candor', 'honesty', 'and openness'], $parsed['rows'][1]);
    }

    public function testParseFlagsNothingWhenEveryRowLinesUp(): void
    {
        $parsed = CsvImport::parseCsv("word,definition\nabate,\"to lessen, reduce\"\ncandor,honesty");
        $this->assertSame([], $parsed['overlong']);
    }

    public function testParseSkipsBlankLinesAndNormalizesNewlines(): void
    {
        $parsed = CsvImport::parseCsv("word,definition\r\n\r\nabate,to lessen\r\n");
        $this->assertCount(1, $parsed['rows']);
    }

    public function testParseRejectsEmptyText(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CsvImport::parseCsv('   ');
    }

    public function testSuggestColumnMappingMatchesByNormalizedName(): void
    {
        $mapping = CsvImport::suggestColumnMapping(
            ['Word', 'DEFINITION', 'Notes'],
            WordCsvImport::targetFields()
        );
        $this->assertSame(['word', 'definition', ''], $mapping);
    }

    public function testSuggestColumnMappingUsesEachFieldOnce(): void
    {
        $mapping = CsvImport::suggestColumnMapping(
            ['word', 'word', 'definition'],
            WordCsvImport::targetFields()
        );
        $this->assertSame(['word', '', 'definition'], $mapping);
    }

    public function testApplyMappingDropsIgnoredColumns(): void
    {
        $rows = [['abate', 'ignored', 'to lessen']];
        $mapped = CsvImport::applyMapping($rows, ['word', '', 'definition']);
        $this->assertSame([['word' => 'abate', 'definition' => 'to lessen']], $mapped);
    }
}
