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
