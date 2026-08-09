<?php
// AJAX: persist the user's resume point in the full deck when they browse
// with the back/forward buttons (marks save it themselves). Returns {ok}.
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
    $position = (int)($_POST['position'] ?? 0);

    // Which deck the position belongs to: null = the full deck.
    $tagId = (int)($_POST['tag'] ?? 0);
    $tagId = ($tagId > 0 && WordManagement::findTagById($tagId)) ? $tagId : null;

    FlashcardProgress::saveDeckPosition(UserContext::getLoggedInUserContext(), $position, $tagId);
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
