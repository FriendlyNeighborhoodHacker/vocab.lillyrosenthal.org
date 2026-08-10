<?php
// Personal progress page: big-number tiles plus a 14-day activity chart.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/FlashcardProgress.php';
require_once __DIR__ . '/../lib/QuizManagement.php';
Application::init();
require_login();

$me = current_user();
$stats = FlashcardProgress::getStatsForUser((int)$me['id']);
$quiz = QuizManagement::getQuizStatsForUser((int)$me['id']);
$mostMissed = QuizManagement::getMostMissedWordsForUser((int)$me['id']);

$maxDaily = 0;
foreach ($stats['daily_reviews'] as $day) {
    $maxDaily = max($maxDaily, $day['count']);
}

header_html('My Stats');
?>

<h2>Hi <?=h($me['first_name'])?>! Here's your progress &#127775;</h2>

<div class="stat-tiles">
  <div class="stat-tile stat-mastered">
    <div class="stat-number"><?= number_format($stats['mastered']) ?></div>
    <div class="stat-label">Got it</div>
    <div class="stat-sub small">of <?= number_format($stats['total_words']) ?> words</div>
  </div>
  <div class="stat-tile stat-misses">
    <div class="stat-number"><?= number_format($stats['needs_review']) ?></div>
    <div class="stat-label">Need more review</div>
    <div class="stat-sub small"><a href="/review/?deck=needs_review">review them now</a></div>
  </div>
  <div class="stat-tile stat-flagged">
    <div class="stat-number"><?= number_format($stats['flagged']) ?></div>
    <div class="stat-label">Flagged</div>
    <div class="stat-sub small"><a href="/review/?deck=flagged">review them now</a></div>
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
    <p class="small">No reviews yet — <a href="/review/">flip your first card</a> and the chart fills in!</p>
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
    <p class="small">No quiz answers yet — <a href="/quiz/">type your first word</a> and your points start stacking up.</p>
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
      <div class="stat-sub small"><a href="/quiz/">play a round</a></div>
    </div>
  </div>
<?php endif; ?>

<h3 class="progress-section-head">Words you miss the most &#128269;</h3>
<div class="card">
  <?php if (!$mostMissed): ?>
    <p class="small">Nothing missed yet — every flashcard you mark Need More Review and every quiz answer that doesn't land shows up here.</p>
  <?php else: ?>
    <p class="small">Counting every Need More Review on a flashcard and every quiz answer that didn't earn points.</p>
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
    <p class="small"><a href="/quiz/?source=misses">Quiz me on my misses</a> &middot; <a href="/review/?deck=needs_review">flip through them</a></p>
  <?php endif; ?>
</div>

<div class="actions">
  <a class="button primary" href="/review/">Keep reviewing &#8594;</a>
  <a class="button" href="/quiz/">Take a quiz</a>
</div>

<?php footer_html(); ?>
