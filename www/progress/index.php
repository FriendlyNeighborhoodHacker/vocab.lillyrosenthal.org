<?php
// Personal progress page: big-number tiles plus a 14-day activity chart,
// optionally scoped to one deck (tag).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/FlashcardProgress.php';
require_once __DIR__ . '/../lib/QuizManagement.php';
require_once __DIR__ . '/../lib/WordManagement.php';
Application::init();
require_login();

$me = current_user();

// Optional deck (tag) filter — every number on the page follows it.
$allTags = WordManagement::listAllTags();
$requestedTagId = (int)($_GET['tag'] ?? 0);
$selectedTag = $requestedTagId > 0 ? WordManagement::findTagById($requestedTagId) : null;
$tagId = $selectedTag ? (int)$selectedTag['id'] : null;

// Appended to links so the chosen deck survives navigation. The quiz launcher
// takes decks as a tags[] array; review takes a single tag.
$tagQuery = $tagId !== null ? '&tag=' . $tagId : '';
$quizTagQuery = $tagId !== null ? '&' . http_build_query(['tags' => [$tagId]]) : '';
$quizHref = '/quiz/' . ($quizTagQuery !== '' ? '?' . ltrim($quizTagQuery, '&') : '');

$stats = FlashcardProgress::getStatsForUser((int)$me['id'], $tagId);
$quiz = QuizManagement::getQuizStatsForUser((int)$me['id'], $tagId);

// How many most-missed words to show: top 20 (default), top 40, or everything.
$missedChoices = ['20' => 'Top 20', '40' => 'Top 40', 'all' => 'All time'];
$missedShown = (string)($_GET['missed'] ?? '20');
if (!isset($missedChoices[$missedShown])) {
    $missedShown = '20';
}
$mostMissed = QuizManagement::getMostMissedWordsForUser(
    (int)$me['id'],
    $missedShown === 'all' ? null : (int)$missedShown,
    $tagId
);

$maxDaily = 0;
foreach ($stats['daily_reviews'] as $day) {
    $maxDaily = max($maxDaily, $day['count']);
}

header_html('My Stats');
?>

<div class="progress-toolbar">
  <h2>Hi <?=h($me['first_name'])?>! Here's your progress &#127775;</h2>
  <?php if (!empty($allTags)): ?>
    <form method="get" action="/progress/" class="deck-picker">
      <input type="hidden" name="missed" value="<?=h($missedShown)?>">
      <label class="small">Deck
        <select name="tag" onchange="this.form.submit()">
          <option value="">All decks</option>
          <?php foreach ($allTags as $tag): ?>
            <option value="<?= (int)$tag['id'] ?>" <?= $tagId === (int)$tag['id'] ? 'selected' : '' ?>>
              <?=h($tag['name'])?> (<?= (int)$tag['word_count'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </form>
  <?php endif; ?>
</div>
<?php if ($selectedTag): ?>
  <p class="small progress-deck-note">Showing just the <strong><?=h($selectedTag['name'])?></strong> deck.
    <a href="/progress/?missed=<?=h($missedShown)?>">See all decks</a></p>
<?php endif; ?>

<div class="stat-tiles">
  <div class="stat-tile stat-mastered">
    <div class="stat-number"><?= number_format($stats['mastered']) ?></div>
    <div class="stat-label">Got it</div>
    <div class="stat-sub small">of <?= number_format($stats['total_words']) ?> words</div>
  </div>
  <div class="stat-tile stat-misses">
    <div class="stat-number"><?= number_format($stats['needs_review']) ?></div>
    <div class="stat-label">Need more review</div>
    <div class="stat-sub small"><a href="/review/?deck=needs_review<?=h($tagQuery)?>">review them now</a></div>
  </div>
  <div class="stat-tile stat-flagged">
    <div class="stat-number"><?= number_format($stats['flagged']) ?></div>
    <div class="stat-label">Flagged</div>
    <div class="stat-sub small"><a href="/review/?deck=flagged<?=h($tagQuery)?>">review them now</a></div>
  </div>
  <div class="stat-tile stat-today">
    <div class="stat-number"><?= number_format($stats['reviewed_today']) ?></div>
    <div class="stat-label">Reviewed today</div>
    <div class="stat-sub small"><?= number_format($stats['total_reviews']) ?> all-time</div>
  </div>
</div>

<div class="card">
  <h3>Last 14 days</h3>
  <?php if ($maxDaily === 0): ?>
    <p class="small">No reviews yet<?= $selectedTag ? ' in this deck' : '' ?> — <a href="/review/?deck=all<?=h($tagQuery)?>">flip your first card</a> and the chart fills in!</p>
  <?php else: ?>
    <div class="daily-chart">
      <?php foreach ($stats['daily_reviews'] as $day): ?>
        <?php $pct = (int)round(($day['count'] / $maxDaily) * 100); ?>
        <div class="daily-col" title="<?= h(date('M j', strtotime($day['date']))) ?>: <?= (int)$day['count'] ?> review<?= $day['count'] === 1 ? '' : 's' ?>">
          <div class="daily-count small"><?= $day['count'] > 0 ? (int)$day['count'] : '' ?></div>
          <div class="daily-bar-track"><div class="daily-bar" style="height:<?= max($pct, $day['count'] > 0 ? 6 : 0) ?>%;"></div></div>
          <div class="daily-label small"><?= h(date('D', strtotime($day['date']))[0]) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<h3 class="progress-section-head">Quiz &#9997;&#65039;</h3>
<?php if ($quiz['answered'] === 0): ?>
  <div class="card">
    <p class="small">No quiz answers yet<?= $selectedTag ? ' on this deck' : '' ?> — <a href="<?=h($quizHref)?>">type your first word</a> and your points start stacking up.</p>
  </div>
<?php else: ?>
  <div class="stat-tiles">
    <div class="stat-tile stat-points">
      <div class="stat-number"><?= number_format($quiz['points']) ?></div>
      <div class="stat-label">Quiz points</div>
      <div class="stat-sub small"><?= number_format($quiz['points_today']) ?> today</div>
    </div>
    <div class="stat-tile stat-accuracy">
      <div class="stat-number"><?= (int)$quiz['accuracy'] ?>%</div>
      <div class="stat-label">Answered right</div>
      <div class="stat-sub small"><?= number_format($quiz['correct']) ?> of <?= number_format($quiz['answered']) ?></div>
    </div>
    <div class="stat-tile stat-today">
      <div class="stat-number"><?= number_format($quiz['answered_today']) ?></div>
      <div class="stat-label">Quizzed today</div>
      <div class="stat-sub small"><a href="<?=h($quizHref)?>">play a round</a></div>
    </div>
  </div>
<?php endif; ?>

<h3 class="progress-section-head">Words you miss the most &#128269;</h3>
<div class="card">
  <?php if (!$mostMissed): ?>
    <p class="small">Nothing missed yet — every flashcard you mark Need More Review and every quiz answer that doesn't land shows up here.</p>
  <?php else: ?>
    <div class="missed-toolbar">
      <p class="small">Counting every Need More Review on a flashcard and every quiz answer that didn't earn points.</p>
      <div class="missed-limit-picks">
        <?php foreach ($missedChoices as $value => $label): ?>
          <?php if ($value === $missedShown): ?>
            <span class="missed-limit-pick active"><?= h($label) ?></span>
          <?php else: ?>
            <a class="missed-limit-pick" href="/progress/?missed=<?= h($value) ?><?=h($tagQuery)?>"><?= h($label) ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="table-scroll">
      <table class="list">
        <thead>
          <tr>
            <th>Word</th>
            <th>Definition</th>
            <th>Misses</th>
            <th>Flashcards</th>
            <th>Quizzes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($mostMissed as $row): ?>
            <tr>
              <td><strong><?= h($row['word']) ?></strong></td>
              <td><?= h($row['definition']) ?></td>
              <td><strong><?= number_format($row['total_misses']) ?></strong></td>
              <td><?= number_format($row['flashcard_misses']) ?></td>
              <td><?= number_format($row['quiz_misses']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="small"><a href="/quiz/?source=misses<?=h($quizTagQuery)?>">Quiz me on my misses</a> &middot; <a href="/review/?deck=needs_review<?=h($tagQuery)?>">flip through them</a></p>
  <?php endif; ?>
</div>

<div class="actions">
  <a class="button primary" href="/review/?deck=all<?=h($tagQuery)?>">Keep reviewing &#8594;</a>
  <a class="button" href="<?=h($quizHref)?>">Take a quiz</a>
</div>

<?php footer_html(); ?>
