<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/WordManagement.php';
require_once __DIR__ . '/WordSentences.php';

// The words CSV import flow (columns: word, definition, sentences, synonyms,
// tags), used by the admin/import/ wizard. Rows are matched to existing words
// case-insensitively: a match updates the mapped fields, no match creates a
// new word appended to the end of the global order. Columns left unmapped are
// never touched on existing words, so a file with only word + synonyms can
// fill in synonyms without disturbing definitions. The tags column carries
// deck names separated by , or ; (e.g. "Green" or "White and Blue; Green");
// unknown tags are created automatically. The sentences column carries one
// sentence as plain text, or several as a JSON array
// (["The storm abated.", "Her anger abated."]).
class WordCsvImport {
    // words-table columns (besides the word itself) that the import can create
    // or edit. Tags are handled separately (they live in word_tags).
    private const EDITABLE_FIELDS = ['definition', 'sentences', 'synonyms'];

    private static function pdo(): PDO {
        return pdo();
    }

    /** [field => label] for the mapping step. */
    public static function targetFields(): array {
        return [
            'word' => 'Word',
            'definition' => 'Definition',
            'sentences' => 'Sentences',
            'synonyms' => 'Synonyms',
            'tags' => 'Tags',
        ];
    }

    // '' and NULL both mean "empty" when comparing against stored values.
    private static function normalized(?string $value): string {
        return trim((string)$value);
    }

    /**
     * What a mapped cell would put in its words column, or NULL to clear it.
     * Sentences are stored as a JSON array, so the cell — plain text or a JSON
     * array — is re-encoded here rather than stored as typed.
     */
    private static function storageValue(string $field, ?string $cell): ?string {
        if ($field === 'sentences') {
            return WordSentences::normalizeInput($cell);
        }
        $value = self::normalized($cell);
        return $value === '' ? null : $value;
    }

    // The same column's stored value in that canonical form, so a row only
    // counts as changed when the sentences themselves differ — not when a file
    // spells the same sentence out as plain text instead of a JSON array.
    private static function storedValue(string $field, array $existing): ?string {
        if ($field === 'sentences') {
            return WordSentences::canonicalize($existing['sentences'] ?? null);
        }
        $value = self::normalized($existing[$field] ?? null);
        return $value === '' ? null : $value;
    }

    // The provided fields of a row that differ from the stored word. "tags"
    // compares as a set (order- and case-insensitive).
    private static function changedFieldsForExistingWord(array $row, array $existing): array {
        $changed = [];
        foreach (self::EDITABLE_FIELDS as $field) {
            if (!array_key_exists($field, $row)) continue; // column not mapped
            if (self::storageValue($field, $row[$field]) !== self::storedValue($field, $existing)) {
                $changed[] = $field;
            }
        }
        if (array_key_exists('tags', $row)) {
            $newTags = array_map('mb_strtolower', WordManagement::parseTagList((string)$row['tags']));
            $currentTags = array_map('mb_strtolower', WordManagement::tagNamesForWord((int)$existing['id']));
            sort($newTags);
            sort($currentTags);
            if ($newTags !== $currentTags) {
                $changed[] = 'tags';
            }
        }
        return $changed;
    }

    /**
     * Validate mapped rows. Returns one entry per row:
     * ['row' => 1-based line, 'data' => assoc of the mapped fields,
     *  'status' => 'valid'|'error', 'messages' => string[], 'changes' => string]
     *
     * $context carries what only the parse step knows: 'overlong' (row index =>
     * how many values that row really had, from CsvImport::parseCsv) and
     * 'column_count' (how many headers there were).
     */
    public static function validateRows(array $mappedRows, array $context = []): array {
        $validated = [];
        $seenWords = []; // lowercased word => first row number
        $overlong = $context['overlong'] ?? [];
        $columnCount = (int)($context['column_count'] ?? 0);

        foreach ($mappedRows as $i => $row) {
            $rowNumber = $i + 1;
            $word = self::normalized($row['word'] ?? '');
            $messages = [];
            $changes = '';

            $data = ['word' => $word];
            foreach (array_merge(self::EDITABLE_FIELDS, ['tags']) as $field) {
                if (array_key_exists($field, $row)) {
                    $data[$field] = self::normalized($row[$field]);
                }
            }

            if ($word === '') {
                $messages[] = 'Word is required.';
            } elseif (mb_strlen($word) > 100) {
                $messages[] = 'Word must be 100 characters or fewer.';
            }

            // More values than columns: a delimiter inside a cell split the row,
            // so everything after it is in the wrong column and the overflow was
            // dropped. Worth stopping on — the row would import as nonsense.
            if (isset($overlong[$i])) {
                $messages[] = 'This row has ' . (int)$overlong[$i] . ' values but the file has '
                    . $columnCount . ' columns, so its columns do not line up. A comma inside a '
                    . 'cell splits the row unless the cell is wrapped in quotes.';
            }

            // Caught here rather than at commit: an over-long tag is almost
            // always a row whose columns have shifted, and finding that out
            // row by row beats one exception rolling the whole import back.
            foreach (WordManagement::parseTagList((string)($data['tags'] ?? '')) as $tagName) {
                if (mb_strlen($tagName) > 100) {
                    $messages[] = 'Tag name is too long (100 characters max): "'
                        . mb_substr($tagName, 0, 40) . '…". Check that this row\'s columns line up.';
                    break;
                }
            }

            $key = mb_strtolower($word);
            if ($word !== '' && isset($seenWords[$key])) {
                $messages[] = 'Duplicate of row ' . $seenWords[$key] . ' in this file.';
            } elseif ($word !== '') {
                $seenWords[$key] = $rowNumber;
            }

            if (!$messages) {
                $existing = WordManagement::findByWordText($word);
                if ($existing) {
                    // A mapped-but-blank definition would clear a required
                    // field; leave the column unmapped to keep current values.
                    if (array_key_exists('definition', $data) && $data['definition'] === '') {
                        $messages[] = 'Definition cannot be blank for an existing word.';
                    } else {
                        $changed = self::changedFieldsForExistingWord($data, $existing);
                        $changes = $changed ? 'Update ' . implode(', ', $changed) : 'No changes';
                    }
                } else {
                    if (self::normalized($data['definition'] ?? '') === '') {
                        $messages[] = 'Definition is required for new words.';
                    } else {
                        $changes = 'Create new word';
                    }
                }
            }

            $validated[] = [
                'row' => $rowNumber,
                'data' => $data,
                'status' => $messages ? 'error' : 'valid',
                'messages' => $messages,
                'changes' => $changes,
            ];
        }

        return $validated;
    }

    /**
     * Commit the validated rows in one transaction. Error rows are skipped;
     * matched rows whose mapped fields already match the database count as
     * unchanged. Returns ['created' => n, 'updated' => n, 'unchanged' => n, 'skipped' => n].
     */
    public static function commit(?UserContext $ctx, array $validatedRows, array $context = []): array {
        if (!$ctx || !$ctx->admin) {
            throw new RuntimeException('Admins only');
        }

        $pdo = self::pdo();
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;

        $pdo->beginTransaction();
        try {
            $nextSortOrder = WordManagement::nextSortOrder();

            foreach ($validatedRows as $entry) {
                if (($entry['status'] ?? '') !== 'valid') {
                    $skipped++;
                    continue;
                }
                $data = $entry['data'];
                $word = (string)$data['word'];

                $existing = WordManagement::findByWordText($word);
                if ($existing) {
                    $changed = self::changedFieldsForExistingWord($data, $existing);
                    if (!$changed) {
                        $unchanged++;
                        continue;
                    }
                    $set = [];
                    $params = [];
                    foreach ($changed as $field) {
                        if ($field === 'tags') continue; // synced below, not a words column
                        $set[] = "$field = ?";
                        // Blank sentences/synonyms clear the field (definition
                        // is validated non-blank above).
                        $params[] = self::storageValue($field, $data[$field]);
                    }
                    if ($set) {
                        $params[] = (int)$existing['id'];
                        $st = $pdo->prepare('UPDATE words SET ' . implode(', ', $set) . ' WHERE id = ?');
                        $st->execute($params);
                    }
                    if (in_array('tags', $changed, true)) {
                        WordManagement::syncWordTagLinks((int)$existing['id'], WordManagement::parseTagList((string)$data['tags']));
                    }
                    $updated++;
                } else {
                    $st = $pdo->prepare(
                        'INSERT INTO words (word, definition, sentences, synonyms, sort_order, created_by_user_id) VALUES (?,?,?,?,?,?)'
                    );
                    $st->execute([
                        $word,
                        self::normalized($data['definition']),
                        self::storageValue('sentences', $data['sentences'] ?? null),
                        self::storageValue('synonyms', $data['synonyms'] ?? null),
                        $nextSortOrder,
                        $ctx->id,
                    ]);
                    $newWordId = (int)$pdo->lastInsertId();
                    $tagNames = WordManagement::parseTagList((string)($data['tags'] ?? ''));
                    if ($tagNames) {
                        WordManagement::syncWordTagLinks($newWordId, $tagNames);
                    }
                    $nextSortOrder++;
                    $created++;
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        ActivityLog::log($ctx, 'words.imported', [
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
        ]);

        return ['created' => $created, 'updated' => $updated, 'unchanged' => $unchanged, 'skipped' => $skipped];
    }
}
