<?php
// AJAX: judge and record one typed quiz answer. The answer never lives in the
// page, so this is where correctness is decided. Returns the verdict, the word
// with its definition/sentence/synonyms to read, and the refreshed totals.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/QuizManagement.php';
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
    $mode = (string)($_POST['mode'] ?? '');
    $answer = (string)($_POST['answer'] ?? '');

    $outcome = QuizManagement::recordAnswer(UserContext::getLoggedInUserContext(), $wordId, $mode, $answer);
    echo json_encode(['ok' => true] + $outcome);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
