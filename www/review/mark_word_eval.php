<?php
// AJAX: record a Got it / Need More Review click. Returns {ok, score} so the
// client can repaint the score chip.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/FlashcardProgress.php';
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

    $score = FlashcardProgress::markWord(UserContext::getLoggedInUserContext(), $wordId, $mark, $position);
    echo json_encode(['ok' => true, 'score' => $score]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
