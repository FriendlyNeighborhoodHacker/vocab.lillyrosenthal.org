<?php
// AJAX: record a Got it / Need More Review click. Returns {ok, score} so the
// client can repaint the score chip.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/FlashcardProgress.php';
require_once __DIR__ . '/../lib/WordManagement.php';
Application::init();
require_login();

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}
require_csrf();

try {
    $wordId = (int)($_POST['word_id'] ?? 0);
    $mark = (string)($_POST['mark'] ?? '');
    $position = isset($_POST['position']) ? (int)$_POST['position'] : null;

    // Which deck the position belongs to: null = the full deck.
    $tagId = (int)($_POST['tag'] ?? 0);
    $tagId = ($tagId > 0 && WordManagement::findTagById($tagId)) ? $tagId : null;

    $score = FlashcardProgress::markWord(UserContext::getLoggedInUserContext(), $wordId, $mark, $position, $tagId);
    echo json_encode(['ok' => true, 'score' => $score]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
