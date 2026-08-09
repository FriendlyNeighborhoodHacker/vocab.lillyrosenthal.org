<?php
// AJAX: save the per-user flag toggle on a word. Returns {ok, is_flagged}.
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
    $flagged = !empty($_POST['flagged']);

    $isFlagged = FlashcardProgress::setWordFlag(UserContext::getLoggedInUserContext(), $wordId, $flagged);
    echo json_encode(['ok' => true, 'is_flagged' => $isFlagged]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
