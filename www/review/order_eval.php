<?php
// Restore the deck's original order (back to the first card).
// POST from the review toolbar / deck-complete panel; PRG back to the deck.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/FlashcardProgress.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /review/');
    exit;
}
require_csrf();

FlashcardProgress::restoreOriginalOrder(UserContext::getLoggedInUserContext());

header('Location: /review/');
exit;
