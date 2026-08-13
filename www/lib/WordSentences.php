<?php
declare(strict_types=1);

// A word can carry several example sentences, so words.sentences holds a JSON
// array of strings — ["The storm abated.", "Her anger abated."] — with NULL
// meaning none provided.
//
// Everything written to that column goes through parseInput() and everything
// read out of it goes through fromStorage(). fromStorage() still understands
// the plain-text values written before the JSON migration, so a database that
// has not run 05_sentences_as_json_array.sql yet keeps reading correctly.
//
// Pure string handling, no SQL — the unit tests exercise it directly.
class WordSentences {
    // How several sentences are separated in one line of typed input. A CSV
    // cell can hold neither a newline nor an unquoted comma, so a hand-written
    // file needs a separator that survives both and never shows up in prose.
    public const SEPARATOR = '|';

    /**
     * Text typed by an admin, or read from a CSV cell, as a list of sentences.
     * Three spellings, all equivalent: a JSON array, one sentence per line, or
     * one line with the sentences separated by "|". Blank entries are dropped.
     */
    public static function parseInput(?string $input): array {
        return self::interpret($input, true);
    }

    /** How a list of sentences is stored: a JSON array, or NULL for none. */
    public static function encodeForStorage(array $sentences): ?string {
        $sentences = self::cleanList($sentences);
        if (!$sentences) return null;
        return json_encode($sentences, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** parseInput() then encodeForStorage(): input text -> the value to store. */
    public static function normalizeInput(?string $input): ?string {
        return self::encodeForStorage(self::parseInput($input));
    }

    /**
     * The sentences held in a words.sentences value. Values written before the
     * JSON migration (plain text, one sentence per line) still read correctly.
     */
    public static function fromStorage(?string $stored): array {
        // No "|" splitting here: a stored value is either the JSON array this
        // class wrote or a legacy plain-text one, and neither used it.
        return self::interpret($stored, false);
    }

    /**
     * A stored value re-encoded in the canonical JSON form. Lets the CSV import
     * compare a cell against the database without a difference in formatting
     * looking like a change.
     */
    public static function canonicalize(?string $stored): ?string {
        return self::encodeForStorage(self::fromStorage($stored));
    }

    /** The stored sentences as editable text, one per line (admin textareas). */
    public static function asLines(?string $stored): string {
        return implode("\n", self::fromStorage($stored));
    }

    // Both directions read JSON first and fall back to plain text; only typed
    // input also treats "|" as a separator.
    private static function interpret(?string $text, bool $splitOnSeparator): array {
        $text = trim((string)$text);
        if ($text === '') return [];
        return self::decodeJsonArray($text) ?? self::splitPlainText($text, $splitOnSeparator);
    }

    // A JSON array -> its sentences; null when the text is not a JSON array at
    // all, which is the ordinary case for a sentence somebody typed.
    private static function decodeJsonArray(string $text): ?array {
        if ($text[0] !== '[') return null;
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) return null;
        return self::cleanList($decoded);
    }

    private static function splitPlainText(string $text, bool $splitOnSeparator): array {
        $pattern = $splitOnSeparator
            ? '/\r\n|\r|\n|' . preg_quote(self::SEPARATOR, '/') . '/'
            : '/\r\n|\r|\n/';
        return self::cleanList(preg_split($pattern, $text) ?: []);
    }

    // Trim, drop blanks, drop anything that isn't a plain string/number.
    private static function cleanList(array $items): array {
        $sentences = [];
        foreach ($items as $item) {
            if (!is_scalar($item)) continue;
            $item = trim((string)$item);
            if ($item !== '') $sentences[] = $item;
        }
        return $sentences;
    }
}
