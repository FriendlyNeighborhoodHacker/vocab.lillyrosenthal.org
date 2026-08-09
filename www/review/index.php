<?php
// The flashcard review page — the heart of the app. The user's whole deck is
// embedded as JSON (reads are server-rendered; only marks/flags POST via the
// dedicated AJAX endpoints in this directory).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/FlashcardProgress.php';
Application::init();
require_login();

$me = current_user();
$userId = (int)$me['id'];

$deckFilter = $_GET['deck'] ?? FlashcardProgress::DECK_ALL;
if (!in_array($deckFilter, [FlashcardProgress::DECK_ALL, FlashcardProgress::DECK_FLAGGED, FlashcardProgress::DECK_NEEDS_REVIEW], true)) {
    $deckFilter = FlashcardProgress::DECK_ALL;
}

$deck = FlashcardProgress::getDeckForUser($userId, $deckFilter);
$isShuffled = FlashcardProgress::isDeckShuffledForUser($userId);

// Only the full deck keeps a persistent resume point; the short filtered
// passes always start at the top.
$persistPosition = ($deckFilter === FlashcardProgress::DECK_ALL);
$startAt = $persistPosition ? min(FlashcardProgress::deckPositionForUser($userId), count($deck)) : 0;

$deckJson = array_map(fn($row) => [
    'id' => (int)$row['id'],
    'word' => (string)$row['word'],
    'definition' => (string)$row['definition'],
    'sentences' => (string)($row['sentences'] ?? ''),
    'synonyms' => (string)($row['synonyms'] ?? ''),
    'flagged' => !empty($row['is_flagged']),
], $deck);

$deckLabels = [
    FlashcardProgress::DECK_ALL => 'All words',
    FlashcardProgress::DECK_FLAGGED => 'Flagged',
    FlashcardProgress::DECK_NEEDS_REVIEW => 'Misses',
];

header_html('Flashcards');
?>

<div class="review-toolbar">
  <nav class="deck-tabs" aria-label="Deck">
    <?php foreach ($deckLabels as $key => $label): ?>
      <a href="/review/?deck=<?=h($key)?>" class="deck-tab<?= $deckFilter === $key ? ' active' : '' ?>"><?=h($label)?></a>
    <?php endforeach; ?>
  </nav>
  <?php if ($deckFilter === FlashcardProgress::DECK_ALL): ?>
    <div class="deck-order">
      <span class="small"><?= $isShuffled ? 'Shuffled' : 'In order' ?></span>
      <form method="post" action="/review/shuffle_eval.php">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <button type="submit" class="button small">&#128256; Shuffle</button>
      </form>
      <?php if ($isShuffled): ?>
        <form method="post" action="/review/order_eval.php">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <button type="submit" class="button small">Original order</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if (empty($deck)): ?>
  <div class="card empty-deck">
    <?php if ($deckFilter === FlashcardProgress::DECK_FLAGGED): ?>
      <h2>No flagged words</h2>
      <p>Tap the flag on any card to collect words here for a focused pass.</p>
      <a class="button primary" href="/review/">Back to all words</a>
    <?php elseif ($deckFilter === FlashcardProgress::DECK_NEEDS_REVIEW): ?>
      <h2>No misses &#127881;</h2>
      <p>Nothing is marked "Need More Review" right now. Keep it up!</p>
      <a class="button primary" href="/review/">Back to all words</a>
    <?php else: ?>
      <h2>The deck is empty</h2>
      <?php if (!empty($me['is_admin'])): ?>
        <p>Add words one at a time or import a CSV to get started.</p>
        <div class="actions" style="justify-content:center;">
          <a class="button primary" href="/admin/import/upload.php?flow=words">Import Words</a>
          <a class="button" href="/admin/word_add.php">Add a Word</a>
        </div>
      <?php else: ?>
        <p>No words have been added yet — check back soon!</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php else: ?>

  <div class="progress-wrap">
    <div class="progress-track"><div class="progress-fill" id="progress-fill"></div></div>
    <div class="progress-text small" id="progress-text"></div>
  </div>

  <div class="flashcard-stage" id="flashcard-stage">
    <div class="flashcard-row">
      <button type="button" class="nav-btn" id="btn-prev" aria-label="Previous card" title="Previous card (&#8592;)">&lt;</button>
      <div class="flashcard" id="flashcard" tabindex="0" role="button" aria-label="Flip card">
        <button type="button" class="flag-btn" id="flag-btn" aria-pressed="false" title="Flag this word (f)">
          <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
            <path class="flag-outline" d="M5 3v18M5 4h11l-2.5 4L16 12H5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            <path class="flag-fill" d="M5 4h11l-2.5 4L16 12H5z" fill="currentColor"/>
          </svg>
        </button>
        <div class="flashcard-face flashcard-front">
          <div class="flashcard-word" id="card-word"></div>
          <div class="flashcard-hint small">tap to see the definition</div>
        </div>
        <div class="flashcard-face flashcard-back">
          <div class="flashcard-definition" id="card-definition"></div>
          <div class="flashcard-sentences hidden" id="card-sentences"></div>
          <div class="flashcard-synonyms hidden" id="card-synonyms"></div>
          <div class="flashcard-hint small">tap to see the word</div>
        </div>
      </div>
      <button type="button" class="nav-btn" id="btn-next" aria-label="Next card" title="Next card (&#8594;)">&gt;</button>
    </div>

    <div class="mark-buttons">
      <button type="button" class="button mark-btn mark-miss" id="btn-miss" title="Press 2">Need More Review</button>
      <button type="button" class="button mark-btn mark-got" id="btn-got" title="Press 1">Got it! &#10024;</button>
    </div>
    <p class="keyboard-hint small">space = flip &nbsp;·&nbsp; &#8592; &#8594; = back / forward &nbsp;·&nbsp; 1 = got it &nbsp;·&nbsp; 2 = need more review &nbsp;·&nbsp; f = flag</p>
  </div>

  <div class="card deck-done hidden" id="deck-done">
    <div class="deck-done-burst" aria-hidden="true">&#127881;</div>
    <h2>Deck complete!</h2>
    <p id="deck-done-tally" class="deck-done-tally"></p>
    <p><button type="button" class="button small" id="btn-done-back">&lt; Back to the last card</button></p>
    <div class="actions" style="justify-content:center;flex-wrap:wrap;">
      <?php if ($deckFilter === FlashcardProgress::DECK_ALL): ?>
        <form method="post" action="/review/shuffle_eval.php">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <button type="submit" class="button primary">&#128256; Shuffle &amp; go again</button>
        </form>
        <form method="post" action="/review/order_eval.php">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <button type="submit" class="button">Start over in order</button>
        </form>
        <a class="button" href="/review/?deck=needs_review">Review misses</a>
        <a class="button" href="/review/?deck=flagged">Review flagged</a>
      <?php else: ?>
        <a class="button primary" href="/review/?deck=<?=h($deckFilter)?>">Go again</a>
        <a class="button" href="/review/">Back to all words</a>
      <?php endif; ?>
    </div>
  </div>

  <div id="toast" class="toast hidden" role="alert"></div>

  <script>
    const DECK = <?= json_encode($deckJson, JSON_UNESCAPED_UNICODE) ?>;
    const START_AT = <?= (int)$startAt ?>;
    const PERSIST_POSITION = <?= $persistPosition ? 'true' : 'false' ?>;
    const CSRF = <?= json_encode(csrf_token()) ?>;
  </script>
  <?= ApplicationUI::jsScript('/review/review.js') ?>
<?php endif; ?>

<?php footer_html(); ?>
