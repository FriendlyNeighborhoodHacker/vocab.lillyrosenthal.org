<?php
// Evaluates the edit-word form (POST from admin/word_edit.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/WordManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/words.php');
    exit;
}

require_csrf();

$wordId = (int)($_POST['id'] ?? 0);
if ($wordId <= 0 || !WordManagement::findById($wordId)) {
    header('Location: /admin/words.php?err=' . urlencode('Word not found.'));
    exit;
}

$word = trim($_POST['word'] ?? '');
$definition = trim($_POST['definition'] ?? '');
$sentences = trim($_POST['sentences'] ?? '');
$synonyms = trim($_POST['synonyms'] ?? '');
$tags = trim($_POST['tags'] ?? '');
$sortOrder = (int)($_POST['sort_order'] ?? 0);

try {
    $ctx = UserContext::getLoggedInUserContext();
    WordManagement::updateWord($ctx, $wordId, $word, $definition, $sentences, $synonyms, $sortOrder);
    WordManagement::setWordTags($ctx, $wordId, WordManagement::parseTagList($tags));
    header('Location: /admin/word_edit.php?id=' . $wordId . '&msg=' . urlencode('Word updated.'));
    exit;
} catch (Throwable $e) {
    $query = http_build_query([
        'id' => $wordId,
        'err' => $e->getMessage(),
        'word' => $word,
        'definition' => $definition,
        'sentences' => $sentences,
        'synonyms' => $synonyms,
        'tags' => $tags,
        'sort_order' => $sortOrder,
    ]);
    header('Location: /admin/word_edit.php?' . $query);
    exit;
}
