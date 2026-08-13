<?php
// Shared scaffolding for the multistep CSV import flow (per
// docs/php-guidelines.md "Import Flows"): Upload -> Mapping -> Validation ->
// Commit. The generic step pages in this directory are selected by ?flow=:
//   words — create/update flashcard words (WordCsvImport)
// In-progress state (parsed rows, mapping, validation) lives in
// $_SESSION['import_<flow>'].
require_once __DIR__ . '/../../partials.php';
require_once __DIR__ . '/../../lib/CsvImport.php';
require_once __DIR__ . '/../../lib/WordCsvImport.php';

function import_flows(): array {
    return [
        'words' => [
            'title' => 'Import Words',
            'class' => 'WordCsvImport',
            'default_next' => '/admin/words.php',
        ],
    ];
}

/** Resolve ?flow= (or POSTed flow) or die with a 404. */
function import_current_flow(): array {
    $key = (string)($_GET['flow'] ?? $_POST['flow'] ?? '');
    $flows = import_flows();
    if (!isset($flows[$key])) {
        http_response_code(404);
        die('Unknown import flow.');
    }
    $flow = $flows[$key] + ['key' => $key];

    $next = validate_relative_next_path($_GET['next'] ?? $_POST['next'] ?? '');
    $flow['next'] = $next !== '' ? $next : $flow['default_next'];
    return $flow;
}

function import_session_key(array $flow): string {
    return 'import_' . $flow['key'];
}

/** Hidden fields every step's form carries so the flow context survives. */
function import_hidden_fields_html(array $flow): string {
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
         . '<input type="hidden" name="flow" value="' . h($flow['key']) . '">'
         . '<input type="hidden" name="next" value="' . h($flow['next']) . '">';
}

/** ?flow=...&next=... for step links/redirects. */
function import_query_string(array $flow): string {
    $params = ['flow' => $flow['key']];
    if ($flow['next'] !== $flow['default_next']) {
        $params['next'] = $flow['next'];
    }
    return '?' . http_build_query($params);
}

/**
 * Per-flow instructions for the upload step: which columns to include and an
 * example CSV. Column names don't have to match exactly — the mapping step
 * lets the admin fix them — but using these names maps everything automatically.
 */
function import_columns_help_html(array $flow): string {
    $intro = 'One row per word. Rows are matched to existing words (ignoring '
        . 'capitalization), so re-importing a file edits the mapped fields of matched '
        . 'words instead of creating duplicates — columns you leave unmapped are never '
        . 'touched. New words are added to the end of the deck order.';
    $columns = [
        ['word', 'required', 'the vocabulary word'],
        ['definition', 'required*', '*for new words; existing words keep theirs if the column is unmapped'],
        ['sentences', 'optional', 'one example sentence as plain text, or several as a JSON array — ["The storm abated.", "Her anger abated."]. Every sentence shows on the flashcard; blank clears the field'],
        ['synonyms', 'optional', 'e.g. "reduce, diminish"; blank clears the field'],
        ['tags', 'optional', 'deck name(s), separated by ; or , — e.g. "Green" or "White and Blue; Green". New tags are created automatically; blank clears the word\'s tags'],
    ];
    $example = "word,definition,sentences,synonyms,tags\n"
        . "abate,to become less intense or widespread,The storm suddenly abated.,\"subside, diminish\",White and Blue\n"
        . "circumspect,wary and unwilling to take risks,\"[\"\"She was circumspect in her answers.\"\", \"\"Be circumspect online.\"\"]\",\"cautious, wary\",White and Blue\n"
        . "verdant,green with vegetation,The verdant hills rolled on.,\"lush, leafy\",Green";

    $html = '<div class="card"><h3>What to include</h3>';
    $html .= '<p class="small">' . h($intro) . '</p>';
    $html .= '<table class="list"><thead><tr><th>Column</th><th></th><th>Notes</th></tr></thead><tbody>';
    foreach ($columns as [$name, $requirement, $note]) {
        $html .= '<tr><td><code>' . h($name) . '</code></td>'
            . '<td class="small">' . h($requirement) . '</td>'
            . '<td class="small">' . h($note) . '</td></tr>';
    }
    $html .= '</tbody></table>';
    $html .= '<p class="small" style="margin-bottom:4px;">Example:</p>'
        . '<pre class="import-example">' . h($example) . '</pre>'
        . '<p class="small" style="margin-bottom:0;">The first line must be the header row. '
        . 'Different column names are fine — you can fix the mapping in the next step.</p>';
    $html .= '</div>';
    return $html;
}

/** The step indicator bar (1 Upload · 2 Mapping · 3 Validation · 4 Commit). */
function import_steps_html(array $flow, int $current): string {
    $steps = ['Upload', 'Mapping', 'Validation', 'Commit'];
    $html = '<div class="wizard-steps">';
    foreach ($steps as $i => $label) {
        $n = $i + 1;
        $class = 'wizard-step' . ($n === $current ? ' active' : ($n < $current ? ' done' : ''));
        $html .= '<span class="' . $class . '"><span class="wizard-step-num">' . $n . '</span> ' . h($label) . '</span>';
    }
    $html .= '</div>';
    return $html;
}
