<?php
// Quiz launcher: pick a mode, pick which decks to draw from, start a round.
// Everything here is a read; play.php runs the round itself.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/QuizManagement.php';
require_once __DIR__ . '/../lib/WordManagement.php';
Application::init();
require_login();

$me = current_user();
$stats = QuizManagement::getQuizStatsForUser((int)$me['id']);
$allTags = WordManagement::listAllTags();

$userId = (int)$me['id'];

// How many questions each mode and word pool can produce — overall and per
// deck, so every number on the page can follow the deck, game, and pool the
// user picks without a round trip. Fill in the Blank has a smaller pool,
// since it needs an example sentence using the word.
$readyByMode = [];
$poolCounts = [];
foreach ([QuizManagement::MODE_GUESS_WORD, QuizManagement::MODE_FILL_BLANK] as $mode) {
    foreach ([QuizManagement::SOURCE_ALL, QuizManagement::SOURCE_MISSES_FLAGGED] as $source) {
        $summary = QuizManagement::availableQuestionSummary($userId, $mode, $source);
        $poolCounts[$mode][$source] = [
            'total' => $summary['total'],
            'by_tag' => (object)$summary['by_tag'],   // objects so {} survives json_encode when empty
        ];
    }
    $readyByMode[$mode] = $poolCounts[$mode][QuizManagement::SOURCE_ALL]['total'];
}

// Pre-select whatever the user played last time (play.php links back here with
// its settings) so a second round is one click away.
$selectedMode = (string)($_GET['mode'] ?? QuizManagement::MODE_GUESS_WORD);
if (!QuizManagement::isValidMode($selectedMode)) {
    $selectedMode = QuizManagement::MODE_GUESS_WORD;
}
$selectedSource = QuizManagement::normalizeSource((string)($_GET['source'] ?? QuizManagement::SOURCE_ALL));
if (!QuizManagement::isValidSource($selectedSource)) {
    $selectedSource = QuizManagement::SOURCE_ALL;
}
// The deck picker is a single choice; the first tag carried back from an old
// multi-deck round (or a "change settings" link) pre-selects it.
$selectedTagId = null;
foreach (array_map('intval', (array)($_GET['tags'] ?? [])) as $id) {
    if ($id > 0) { $selectedTagId = $id; break; }
}
$selectedCount = (int)($_GET['count'] ?? 20);

// The number to show beside a game or word pool, honouring the chosen deck.
$countFor = function (string $mode, string $source) use ($poolCounts, $selectedTagId): int {
    $entry = $poolCounts[$mode][$source];
    if ($selectedTagId === null) return $entry['total'];
    return (int)($entry['by_tag']->{$selectedTagId} ?? 0);
};

$sourceCards = [
    QuizManagement::SOURCE_ALL => [
        'name' => 'All words',
        'blurb' => 'Works through the deck, starting with whatever you have practiced least recently.',
    ],
    QuizManagement::SOURCE_MISSES_FLAGGED => [
        'name' => 'Words I missed and flagged words',
        'blurb' => 'Words you have missed — on a flashcard, or in a quiz and not got right since — plus any you flagged.',
    ],
];

$modeCards = [
    QuizManagement::MODE_GUESS_WORD => [
        'emoji' => '&#128173;',
        'blurb' => 'We show you a definition — you type the word it belongs to.',
    ],
    QuizManagement::MODE_FILL_BLANK => [
        'emoji' => '&#9997;&#65039;',
        'blurb' => 'We show you a sentence with the word taken out — you fill in the gap.',
    ],
];

header_html('Quiz');
?>

<div class="quiz-hero">
  <h2 class="quiz-hero-title">Quiz time<span class="quiz-hero-spark">&#10024;</span></h2>
  <p class="quiz-hero-sub">Type the word, earn the points. <?= QuizManagement::POINTS_CORRECT ?> points for a word spelled right,
    <?= QuizManagement::POINTS_CLOSE ?> if a typo sneaks in.</p>
  <?php if ($stats['answered'] > 0): ?>
    <p class="quiz-hero-score"><strong><?= number_format($stats['points']) ?></strong> points so far
      &nbsp;&#183;&nbsp; <?= number_format($stats['answered']) ?> answered &nbsp;&#183;&nbsp; <?= (int)$stats['accuracy'] ?>% right</p>
  <?php endif; ?>
</div>

<?php if ($readyByMode[QuizManagement::MODE_GUESS_WORD] === 0): ?>
  <div class="card empty-deck">
    <h2>No words to quiz on yet</h2>
    <?php if (!empty($me['is_admin'])): ?>
      <p>Add words or import a CSV and the quizzes fill themselves in.</p>
      <div class="actions" style="justify-content:center;">
        <a class="button primary" href="/admin/import/upload.php?flow=words">Import Words</a>
        <a class="button" href="/admin/word_add.php">Add a Word</a>
      </div>
    <?php else: ?>
      <p>No words have been added yet — check back soon!</p>
    <?php endif; ?>
  </div>
<?php else: ?>

<form method="get" action="/quiz/play.php" class="quiz-setup card">
  <?php if (!empty($allTags)): ?>
    <fieldset class="quiz-fieldset">
      <legend>Which deck?</legend>
      <label class="quiz-count">
        <select name="tags[]">
          <option value="">All decks</option>
          <?php foreach ($allTags as $tag): ?>
            <option value="<?= (int)$tag['id'] ?>" <?= $selectedTagId === (int)$tag['id'] ? 'selected' : '' ?>>
              <?=h($tag['name'])?> (<?= (int)$tag['word_count'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </fieldset>
  <?php endif; ?>

  <fieldset class="quiz-fieldset">
    <legend>Pick your game</legend>
    <div class="quiz-mode-cards">
      <?php foreach ($modeCards as $mode => $card): ?>
        <label class="quiz-mode-card<?= $selectedMode === $mode ? ' selected' : '' ?>">
          <input type="radio" name="mode" value="<?=h($mode)?>" <?= $selectedMode === $mode ? 'checked' : '' ?>>
          <span class="quiz-mode-emoji" aria-hidden="true"><?= $card['emoji'] ?></span>
          <span class="quiz-mode-name"><?=h(QuizManagement::modeLabel($mode))?></span>
          <span class="quiz-mode-blurb small"><?=h($card['blurb'])?></span>
          <span class="quiz-mode-ready small" data-mode-ready="<?=h($mode)?>"><?= number_format($countFor($mode, QuizManagement::SOURCE_ALL)) ?> words ready</span>
        </label>
      <?php endforeach; ?>
    </div>
    <?php if ($readyByMode[QuizManagement::MODE_FILL_BLANK] < $readyByMode[QuizManagement::MODE_GUESS_WORD]): ?>
      <p class="small">Fill in the Blank can only use words that have an example sentence, so it has a smaller pool.</p>
    <?php endif; ?>
  </fieldset>

  <fieldset class="quiz-fieldset">
    <legend>Which words?</legend>
    <div class="quiz-source-picks">
      <?php foreach ($sourceCards as $source => $card): ?>
        <label class="quiz-source-pick" data-source="<?=h($source)?>">
          <input type="radio" name="source" value="<?=h($source)?>" <?= $selectedSource === $source ? 'checked' : '' ?>>
          <span class="quiz-source-body">
            <span class="quiz-source-name"><?=h($card['name'])?>
              <span class="quiz-source-count"><?= number_format($countFor($selectedMode, $source)) ?></span>
            </span>
            <span class="quiz-source-blurb small"><?=h($card['blurb'])?></span>
          </span>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <fieldset class="quiz-fieldset">
    <legend>How many questions?</legend>
    <label class="quiz-count">
      <select name="count">
        <?php foreach ([10 => '10 questions', 20 => '20 questions', 40 => '40 questions', 0 => 'Everything in the deck'] as $value => $label): ?>
          <option value="<?= (int)$value ?>" <?= $selectedCount === (int)$value ? 'selected' : '' ?>><?=h($label)?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </fieldset>

  <div class="actions">
    <button type="submit" class="button primary quiz-start">Start the quiz &#8594;</button>
    <a class="button" href="/review/">Back to flashcards</a>
  </div>
</form>

<script>
  // How many questions each mode/pool pair has, overall and per deck
  // ({mode: {source: {total, by_tag: {tagId: count}}}}), so every count on
  // the page follows the deck and game the user picks.
  const POOL_COUNTS = <?= json_encode($poolCounts) ?>;
</script>
<?= ApplicationUI::jsScript('/quiz/quiz_setup.js') ?>

<?php endif; ?>

<?php footer_html(); ?>
