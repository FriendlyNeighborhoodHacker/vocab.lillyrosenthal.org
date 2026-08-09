<?php
// Deletes a word (POST from admin/word_edit.php's danger zone).
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
$word = $wordId > 0 ? WordManagement::findById($wordId) : null;
if (!$word) {
    header('Location: /admin/words.php?err=' . urlencode('Word not found.'));
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    WordManagement::deleteWord($ctx, $wordId);
    header('Location: /admin/words.php?msg=' . urlencode('Deleted "' . $word['word'] . '".'));
    exit;
} catch (Throwable $e) {
    header('Location: /admin/word_edit.php?id=' . $wordId . '&err=' . urlencode('Failed to delete: ' . $e->getMessage()));
    exit;
}
