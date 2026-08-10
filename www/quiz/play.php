<?php
// A round of the typing quiz. The round is built server-side and embedded as
// JSON — prompts only, never the answers: each typed answer POSTs to
// answer_eval.php, which judges and records it.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/QuizManagement.php';
require_once __DIR__ . '/../lib/WordManagement.php';
Application::init();
require_login();

$me = current_user();

$mode = (string)($_GET['mode'] ?? QuizManagement::MODE_GUESS_WORD);
if (!QuizManagement::isValidMode($mode)) {
    $mode = QuizManagement::MODE_GUESS_WORD;
}

$source = (string)($_GET['source'] ?? QuizManagement::SOURCE_ALL);
if (!QuizManagement::isValidSource($source)) {
    $source = QuizManagement::SOURCE_ALL;
}

// Only decks that still exist, so a deleted tag can't empty out a round silently.
$selectedTags = [];
foreach (array_map('intval', (array)($_GET['tags'] ?? [])) as $tagId) {
    $tag = $tagId > 0 ? WordManagement::findTagById($tagId) : null;
    if ($tag) {
        $selectedTags[(int)$tag['id']] = $tag['name'];
    }
}
$tagIds = array_keys($selectedTags);

$count = (int)($_GET['count'] ?? 20);
if (!in_array($count, [0, 10, 20, 40], true)) {
    $count = 20;
}

$questions = QuizManagement::buildQuizRound((int)$me['id'], $mode, $tagIds, $source, $count > 0 ? $count : null);

// Carries the round's settings back to the launcher (pre-ticked) and into the
// "play again" links.
$settingsQuery = http_build_query(['mode' => $mode, 'source' => $source, 'tags' => $tagIds, 'count' => $count]);

$deckLabel = $selectedTags ? implode(', ', $selectedTags) : 'All words';
$sourceLabels = [
    QuizManagement::SOURCE_MISSES => 'Words I miss',
    QuizManagement::SOURCE_FLAGGED => 'Flagged words',
];

header_html(QuizManagement::modeLabel($mode));
?>

<div class="quiz-toolbar">
  <div class="quiz-toolbar-titles">
    <h2 class="quiz-title"><?=h(QuizManagement::modeLabel($mode))?></h2>
    <span class="quiz-deck-chip"><?=h($deckLabel)?></span>
    <?php if (isset($sourceLabels[$source])): ?>
      <span class="quiz-deck-chip quiz-source-chip"><?=h($sourceLabels[$source])?></span>
    <?php endif; ?>
  </div>
  <a class="button small" href="/quiz/?<?=h($settingsQuery)?>">Change settings</a>
</div>

<?php if (empty($questions)): ?>
  <?php $inTheseDecks = $selectedTags ? ' in the decks you picked' : ''; ?>
  <div class="card empty-deck">
    <?php if ($source === QuizManagement::SOURCE_MISSES): ?>
      <h2>Nothing missed<?=h($inTheseDecks)?> &#127881;</h2>
      <p>There's nothing you've missed on a flashcard or in a quiz right now. That's the good kind of empty.</p>
    <?php elseif ($source === QuizManagement::SOURCE_FLAGGED): ?>
      <h2>No flagged words<?=h($inTheseDecks)?></h2>
      <p>Tap the flag on any flashcard to collect words here, then come back and quiz on just those.</p>
    <?php elseif ($mode === QuizManagement::MODE_FILL_BLANK): ?>
      <h2>Nothing to ask here yet</h2>
      <p>Fill in the Blank needs words with an example sentence that uses the word,
        and <?= $selectedTags ? 'the decks you picked have none' : 'none of the words have one yet' ?>.</p>
    <?php else: ?>
      <h2>Nothing to ask here yet</h2>
      <p>There are no words<?=h($inTheseDecks)?> yet.</p>
    <?php endif; ?>
    <div class="actions" style="justify-content:center;flex-wrap:wrap;">
      <a class="button primary" href="/quiz/?<?=h($settingsQuery)?>">Change what you're quizzing on</a>
      <?php if ($source !== QuizManagement::SOURCE_ALL): ?>
        <a class="button" href="/quiz/play.php?<?=h(http_build_query(['mode' => $mode, 'tags' => $tagIds, 'count' => $count]))?>">Quiz on all words instead</a>
      <?php endif; ?>
      <a class="button" href="/review/">Back to flashcards</a>
    </div>
  </div>
<?php else: ?>

  <div class="progress-wrap">
    <div class="progress-track"><div class="progress-fill" id="quiz-progress-fill"></div></div>
    <div class="progress-text small" id="quiz-progress-text"></div>
  </div>

  <div class="quiz-stage" id="quiz-stage">
    <div class="quiz-nav">
      <button type="button" class="quiz-nav-btn hidden" id="quiz-prev-btn" aria-label="Back to the previous question">&#8592; Look back</button>
      <button type="button" class="quiz-nav-btn hidden" id="quiz-fwd-btn" aria-label="Forward to the next question">Forward &#8594;</button>
    </div>
    <div class="quiz-card">
      <div class="quiz-points-chip" id="quiz-points-chip"><span id="quiz-points">0</span> pts</div>
      <div class="quiz-streak hidden" id="quiz-streak"></div>

      <p class="quiz-ask small"><?= $mode === QuizManagement::MODE_FILL_BLANK
        ? 'Which word fills the blank?'
        : 'Which word means&hellip;' ?></p>
      <div class="quiz-prompt" id="quiz-prompt"></div>

      <button type="button" class="button small quiz-hint-btn" id="quiz-hint-btn">Need a hint?</button>
      <div class="quiz-hint hidden" id="quiz-hint"></div>
      <button type="button" class="button small quiz-choices-btn hidden" id="quiz-choices-btn"></button>
      <div class="quiz-choices hidden" id="quiz-choices"></div>

      <form class="quiz-answer-form" id="quiz-form" autocomplete="off">
        <input type="text" id="quiz-input" class="quiz-input" placeholder="type the word&hellip;"
               autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
               aria-label="Your answer">
        <button type="submit" class="button primary quiz-check" id="quiz-check">Check</button>
      </form>
    </div>

    <div class="quiz-feedback hidden" id="quiz-feedback">
      <div class="quiz-feedback-burst" id="quiz-feedback-burst" aria-hidden="true"></div>
      <div class="quiz-feedback-title" id="quiz-feedback-title"></div>
      <div class="quiz-feedback-word" id="quiz-feedback-word"></div>
      <div class="quiz-feedback-yours small hidden" id="quiz-feedback-yours"></div>
      <div class="quiz-feedback-definition" id="quiz-feedback-definition"></div>
      <div class="quiz-feedback-sentence hidden" id="quiz-feedback-sentence"></div>
      <div class="quiz-feedback-synonyms hidden" id="quiz-feedback-synonyms"></div>
      <div class="quiz-feedback-points" id="quiz-feedback-points"></div>
      <div class="actions quiz-feedback-actions">
        <button type="button" class="button quiz-claim hidden" id="quiz-claim">&#10003; I was right anyway</button>
        <button type="button" class="button primary" id="quiz-next">Next &#8594;</button>
      </div>
    </div>

    <p class="keyboard-hint small">enter = check your answer, then enter again for the next word</p>
  </div>

  <div class="card quiz-done hidden" id="quiz-done">
    <div class="quiz-done-burst" id="quiz-done-burst" aria-hidden="true">&#127881;</div>
    <h2 id="quiz-done-title">Round complete!</h2>
    <p class="quiz-done-score" id="quiz-done-score"></p>
    <p class="quiz-done-tally" id="quiz-done-tally"></p>
    <div class="actions" style="justify-content:center;flex-wrap:wrap;">
      <a class="button primary" href="/quiz/play.php?<?=h($settingsQuery)?>">Play again</a>
      <a class="button" href="/quiz/?<?=h($settingsQuery)?>">Change settings</a>
      <a class="button" href="/review/">Back to flashcards</a>
    </div>
  </div>

  <div id="toast" class="toast hidden" role="alert"></div>

  <script>
    const QUESTIONS = <?= json_encode($questions, JSON_UNESCAPED_UNICODE) ?>;
    // Every word in the chosen decks (texts only, nothing marking the answer),
    // for the "show words starting with…" second hint.
    const WORD_LIST = <?= json_encode(WordManagement::listWordTexts($tagIds), JSON_UNESCAPED_UNICODE) ?>;
    const QUIZ_MODE = <?= json_encode($mode) ?>;
    // Identifies this round's settings, so a saved in-progress round is only
    // resumed into the round it belongs to.
    const ROUND_SETTINGS = <?= json_encode($settingsQuery) ?>;
    const CSRF = <?= json_encode(csrf_token()) ?>;
  </script>
  <?= ApplicationUI::jsScript('/quiz/quiz.js') ?>
<?php endif; ?>

<?php footer_html(); ?>
