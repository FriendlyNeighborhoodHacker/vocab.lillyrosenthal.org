<?php
// AJAX: "I was right anyway" — award partial credit on an answer that scored
// nothing, for the cases where a definition honestly fits more than one word.
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
    $attemptId = (int)($_POST['attempt_id'] ?? 0);

    $outcome = QuizManagement::markAttemptCorrectAnyway(UserContext::getLoggedInUserContext(), $attemptId);
    echo json_encode(['ok' => true] + $outcome);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
