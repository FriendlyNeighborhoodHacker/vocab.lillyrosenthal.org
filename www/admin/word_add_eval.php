<?php
// Evaluates the add-word form (POST from admin/word_add.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/WordManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/words.php');
    exit;
}

require_csrf();

$word = trim($_POST['word'] ?? '');
$definition = trim($_POST['definition'] ?? '');
$sentences = trim($_POST['sentences'] ?? '');
$synonyms = trim($_POST['synonyms'] ?? '');

try {
    $ctx = UserContext::getLoggedInUserContext();
    WordManagement::addWord($ctx, $word, $definition, $sentences, $synonyms);
    header('Location: /admin/word_add.php?msg=' . urlencode('Added "' . $word . '". Add another?'));
    exit;
} catch (Throwable $e) {
    $query = http_build_query([
        'err' => $e->getMessage(),
        'word' => $word,
        'definition' => $definition,
        'sentences' => $sentences,
        'synonyms' => $synonyms,
    ]);
    header('Location: /admin/word_add.php?' . $query);
    exit;
}
