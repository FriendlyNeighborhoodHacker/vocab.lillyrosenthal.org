<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/WordManagement.php';

// The words CSV import flow (columns: word, definition, sentences, synonyms),
// used by the admin/import/ wizard. Rows are matched to existing words
// case-insensitively: a match updates the mapped fields, no match creates a
// new word appended to the end of the global order. Columns left unmapped are
// never touched on existing words, so a file with only word + synonyms can
// fill in synonyms without disturbing definitions.
class WordCsvImport {
    // Fields (besides the word itself) that the import can create or edit.
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
        ];
    }

    // '' and NULL both mean "empty" when comparing against stored values.
    private static function normalized(?string $value): string {
        return trim((string)$value);
    }

    // The provided fields of a row that differ from the stored word.
    private static function changedFieldsForExistingWord(array $row, array $existing): array {
        $changed = [];
        foreach (self::EDITABLE_FIELDS as $field) {
            if (!array_key_exists($field, $row)) continue; // column not mapped
            if (self::normalized($row[$field]) !== self::normalized($existing[$field] ?? null)) {
                $changed[] = $field;
            }
        }
        return $changed;
    }

    /**
     * Validate mapped rows. Returns one entry per row:
     * ['row' => 1-based line, 'data' => assoc of the mapped fields,
     *  'status' => 'valid'|'error', 'messages' => string[], 'changes' => string]
     */
    public static function validateRows(array $mappedRows, array $context = []): array {
        $validated = [];
        $seenWords = []; // lowercased word => first row number

        foreach ($mappedRows as $i => $row) {
            $rowNumber = $i + 1;
            $word = self::normalized($row['word'] ?? '');
            $messages = [];
            $changes = '';

            $data = ['word' => $word];
            foreach (self::EDITABLE_FIELDS as $field) {
                if (array_key_exists($field, $row)) {
                    $data[$field] = self::normalized($row[$field]);
                }
            }

            if ($word === '') {
                $messages[] = 'Word is required.';
            } elseif (mb_strlen($word) > 100) {
                $messages[] = 'Word must be 100 characters or fewer.';
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
                        $value = self::normalized($data[$field]);
                        $set[] = "$field = ?";
                        // Blank sentences/synonyms clear the field (definition
                        // is validated non-blank above).
                        $params[] = $value === '' ? null : $value;
                    }
                    $params[] = (int)$existing['id'];
                    $st = $pdo->prepare('UPDATE words SET ' . implode(', ', $set) . ' WHERE id = ?');
                    $st->execute($params);
                    $updated++;
                } else {
                    $sentences = self::normalized($data['sentences'] ?? '');
                    $synonyms = self::normalized($data['synonyms'] ?? '');
                    $st = $pdo->prepare(
                        'INSERT INTO words (word, definition, sentences, synonyms, sort_order, created_by_user_id) VALUES (?,?,?,?,?,?)'
                    );
                    $st->execute([
                        $word,
                        self::normalized($data['definition']),
                        $sentences === '' ? null : $sentences,
                        $synonyms === '' ? null : $synonyms,
                        $nextSortOrder,
                        $ctx->id,
                    ]);
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
