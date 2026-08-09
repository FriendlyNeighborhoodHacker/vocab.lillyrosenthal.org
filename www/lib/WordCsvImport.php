<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/WordManagement.php';

// The words CSV import flow (columns: word, definition), used by the
// admin/import/ wizard. Rows are matched to existing words case-insensitively:
// a match updates the definition, no match creates a new word appended to the
// end of the global order.
class WordCsvImport {
    private static function pdo(): PDO {
        return pdo();
    }

    /** [field => label] for the mapping step. */
    public static function targetFields(): array {
        return [
            'word' => 'Word',
            'definition' => 'Definition',
        ];
    }

    /**
     * Validate mapped rows. Returns one entry per row:
     * ['row' => 1-based line, 'data' => assoc, 'status' => 'valid'|'error',
     *  'messages' => string[], 'changes' => string]
     */
    public static function validateRows(array $mappedRows, array $context = []): array {
        $validated = [];
        $seenWords = []; // lowercased word => first row number

        foreach ($mappedRows as $i => $row) {
            $rowNumber = $i + 1;
            $word = trim((string)($row['word'] ?? ''));
            $definition = trim((string)($row['definition'] ?? ''));
            $messages = [];
            $changes = '';

            if ($word === '') {
                $messages[] = 'Word is required.';
            } elseif (mb_strlen($word) > 100) {
                $messages[] = 'Word must be 100 characters or fewer.';
            }
            if ($definition === '') {
                $messages[] = 'Definition is required.';
            }

            $key = mb_strtolower($word);
            if ($word !== '' && isset($seenWords[$key])) {
                $messages[] = 'Duplicate of row ' . $seenWords[$key] . ' in this file.';
            } elseif ($word !== '') {
                $seenWords[$key] = $rowNumber;
            }

            if (!$messages) {
                $existing = WordManagement::findByWordText($word);
                $changes = $existing ? 'Update definition' : 'Create new word';
            }

            $validated[] = [
                'row' => $rowNumber,
                'data' => ['word' => $word, 'definition' => $definition],
                'status' => $messages ? 'error' : 'valid',
                'messages' => $messages,
                'changes' => $changes,
            ];
        }

        return $validated;
    }

    /**
     * Commit the validated rows in one transaction. Error rows are skipped.
     * Returns ['created' => n, 'updated' => n, 'skipped' => n].
     */
    public static function commit(?UserContext $ctx, array $validatedRows, array $context = []): array {
        if (!$ctx || !$ctx->admin) {
            throw new RuntimeException('Admins only');
        }

        $pdo = self::pdo();
        $created = 0;
        $updated = 0;
        $skipped = 0;

        $pdo->beginTransaction();
        try {
            $nextSortOrder = WordManagement::nextSortOrder();

            foreach ($validatedRows as $entry) {
                if (($entry['status'] ?? '') !== 'valid') {
                    $skipped++;
                    continue;
                }
                $word = (string)$entry['data']['word'];
                $definition = (string)$entry['data']['definition'];

                $existing = WordManagement::findByWordText($word);
                if ($existing) {
                    $st = $pdo->prepare('UPDATE words SET definition = ? WHERE id = ?');
                    $st->execute([$definition, (int)$existing['id']]);
                    $updated++;
                } else {
                    $st = $pdo->prepare(
                        'INSERT INTO words (word, definition, sort_order, created_by_user_id) VALUES (?,?,?,?)'
                    );
                    $st->execute([$word, $definition, $nextSortOrder, $ctx->id]);
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
            'skipped' => $skipped,
        ]);

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }
}
