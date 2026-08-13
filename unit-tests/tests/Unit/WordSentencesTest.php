<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WordSentencesTest extends TestCase
{
    public function testParsesPlainTextAsOneSentence(): void
    {
        $this->assertSame(['The storm abated.'], WordSentences::parseInput('  The storm abated.  '));
    }

    public function testParsesOneSentencePerLine(): void
    {
        $this->assertSame(
            ['The storm abated.', 'Her anger abated.'],
            WordSentences::parseInput("The storm abated.\r\n\r\n  Her anger abated.  ")
        );
    }

    // The CSV-friendly spelling: a cell can hold neither a newline nor an
    // unquoted comma, so several sentences share one line separated by "|".
    public function testParsesSentencesSeparatedByAPipe(): void
    {
        $this->assertSame(
            ['The storm abated.', 'Her anger abated.'],
            WordSentences::parseInput('The storm abated. | Her anger abated.')
        );
    }

    public function testAPipeIsNotASeparatorInAlreadyStoredValues(): void
    {
        $this->assertSame(['a | b'], WordSentences::fromStorage('a | b'));
    }

    public function testParsesAJsonArray(): void
    {
        $this->assertSame(
            ['The storm abated.', 'Her anger abated.'],
            WordSentences::parseInput('["The storm abated.", "Her anger abated."]')
        );
    }

    public function testParsesNothingFromBlankInput(): void
    {
        $this->assertSame([], WordSentences::parseInput("   \n  "));
        $this->assertSame([], WordSentences::parseInput(null));
        $this->assertSame([], WordSentences::parseInput('[]'));
    }

    // A sentence can legitimately open with a bracket; only real JSON counts.
    public function testTextThatMerelyStartsWithABracketStaysOneSentence(): void
    {
        $this->assertSame(['[sic] the storm abated.'], WordSentences::parseInput('[sic] the storm abated.'));
    }

    public function testEncodesForStorageAsAJsonArray(): void
    {
        $this->assertSame(
            '["The storm abated.","Her anger abated."]',
            WordSentences::encodeForStorage(['The storm abated.', ' Her anger abated. '])
        );
    }

    public function testEncodesNothingAsNull(): void
    {
        $this->assertNull(WordSentences::encodeForStorage([]));
        $this->assertNull(WordSentences::encodeForStorage(['', '   ']));
        $this->assertNull(WordSentences::normalizeInput('   '));
    }

    public function testEncodesUnicodeAndQuotesReadably(): void
    {
        $this->assertSame(
            '["She said “abate” — café."]',
            WordSentences::encodeForStorage(['She said “abate” — café.'])
        );
    }

    public function testReadsBackWhatItStored(): void
    {
        $stored = WordSentences::normalizeInput("The storm abated.\nHer anger abated.");
        $this->assertSame(['The storm abated.', 'Her anger abated.'], WordSentences::fromStorage($stored));
    }

    // Values written before the JSON migration are plain text, one per line.
    public function testReadsLegacyPlainTextValues(): void
    {
        $this->assertSame(['The storm abated.'], WordSentences::fromStorage('The storm abated.'));
        $this->assertSame(
            ['The storm abated.', 'Her anger abated.'],
            WordSentences::fromStorage("The storm abated.\nHer anger abated.")
        );
        $this->assertSame([], WordSentences::fromStorage(null));
    }

    public function testCanonicalizeMakesLegacyAndJsonValuesComparable(): void
    {
        $this->assertSame(
            WordSentences::canonicalize('["The storm abated."]'),
            WordSentences::canonicalize('The storm abated.')
        );
        $this->assertNull(WordSentences::canonicalize('  '));
    }

    public function testAsLinesGivesOneSentencePerLineForEditing(): void
    {
        $this->assertSame(
            "The storm abated.\nHer anger abated.",
            WordSentences::asLines('["The storm abated.", "Her anger abated."]')
        );
        $this->assertSame('', WordSentences::asLines(null));
    }
}
