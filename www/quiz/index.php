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

// Some words can't be asked in every mode — Fill in the Blank needs an example
// sentence that actually uses the word — so show what each mode has ready.
$readyByMode = [
    QuizManagement::MODE_GUESS_WORD => QuizManagement::countAvailableQuestions(QuizManagement::MODE_GUESS_WORD),
    QuizManagement::MODE_FILL_BLANK => QuizManagement::countAvailableQuestions(QuizManagement::MODE_FILL_BLANK),
];

// Pre-select whatever the user played last time (play.php links back here with
// its settings) so a second round is one click away.
$selectedMode = (string)($_GET['mode'] ?? QuizManagement::MODE_GUESS_WORD);
if (!QuizManagement::isValidMode($selectedMode)) {
    $selectedMode = QuizManagement::MODE_GUESS_WORD;
}
$selectedTagIds = array_map('intval', (array)($_GET['tags'] ?? []));
$selectedCount = (int)($_GET['count'] ?? 20);

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
  <fieldset class="quiz-fieldset">
    <legend>Pick your game</legend>
    <div class="quiz-mode-cards">
      <?php foreach ($modeCards as $mode => $card): ?>
        <label class="quiz-mode-card<?= $selectedMode === $mode ? ' selected' : '' ?>">
          <input type="radio" name="mode" value="<?=h($mode)?>" <?= $selectedMode === $mode ? 'checked' : '' ?>>
          <span class="quiz-mode-emoji" aria-hidden="true"><?= $card['emoji'] ?></span>
          <span class="quiz-mode-name"><?=h(QuizManagement::modeLabel($mode))?></span>
          <span class="quiz-mode-blurb small"><?=h($card['blurb'])?></span>
          <span class="quiz-mode-ready small"><?= number_format($readyByMode[$mode]) ?> words ready</span>
        </label>
      <?php endforeach; ?>
    </div>
    <?php if ($readyByMode[QuizManagement::MODE_FILL_BLANK] < $readyByMode[QuizManagement::MODE_GUESS_WORD]): ?>
      <p class="small">Fill in the Blank can only use words that have an example sentence, so it has a smaller pool.</p>
    <?php endif; ?>
  </fieldset>

  <?php if (!empty($allTags)): ?>
    <fieldset class="quiz-fieldset">
      <legend>Which decks?</legend>
      <p class="small">Tick as many as you like — leave them all unticked to quiz on every word.</p>
      <div class="quiz-deck-picks">
        <?php foreach ($allTags as $tag): ?>
          <label class="quiz-deck-pick">
            <input type="checkbox" name="tags[]" value="<?= (int)$tag['id'] ?>"
                   <?= in_array((int)$tag['id'], $selectedTagIds, true) ? 'checked' : '' ?>>
            <span><?=h($tag['name'])?> <span class="small">(<?= (int)$tag['word_count'] ?>)</span></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>
  <?php endif; ?>

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

<?php endif; ?>

<?php footer_html(); ?>
