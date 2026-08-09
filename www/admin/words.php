<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/WordManagement.php';
Application::init();
require_admin();

$msg = $_GET['msg'] ?? ($_SESSION['import_flash'] ?? null);
$err = $_GET['err'] ?? ($_SESSION['import_flash_error'] ?? null);
unset($_SESSION['import_flash'], $_SESSION['import_flash_error']);

$allTags = WordManagement::listAllTags();
$selectedTag = null;
$requestedTagId = (int)($_GET['tag'] ?? 0);
if ($requestedTagId > 0) {
    $selectedTag = WordManagement::findTagById($requestedTagId);
}

$words = WordManagement::listWordsInGlobalOrder($selectedTag ? (int)$selectedTag['id'] : null);

header_html('Words');
?>

<div class="page-head">
  <h2>Words <span class="count-pill"><?= count($words) ?></span></h2>
  <div class="actions">
    <a class="button" href="/admin/import/upload.php?flow=words">Import CSV</a>
    <a class="button primary" href="/admin/word_add.php">Add Word</a>
  </div>
</div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<?php if (!empty($allTags)): ?>
  <form method="get" class="deck-picker" style="margin:10px 0;">
    <label class="small">Deck
      <select name="tag" onchange="this.form.submit()">
        <option value="">All decks</option>
        <?php foreach ($allTags as $tag): ?>
          <option value="<?= (int)$tag['id'] ?>" <?= $selectedTag && (int)$selectedTag['id'] === (int)$tag['id'] ? 'selected' : '' ?>>
            <?=h($tag['name'])?> (<?= (int)$tag['word_count'] ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
<?php endif; ?>

<?php if (empty($words)): ?>
  <div class="card">
    <p>No words yet! Add words one at a time, or bring in a whole list at once with the CSV import.</p>
    <div class="actions">
      <a class="button primary" href="/admin/import/upload.php?flow=words">Import CSV</a>
      <a class="button" href="/admin/word_add.php">Add a Word</a>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <table class="list">
      <thead>
        <tr>
          <th>#</th>
          <th>Word</th>
          <th>Definition</th>
          <th>Tags</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($words as $word): ?>
          <tr>
            <td class="small"><?= (int)$word['sort_order'] ?></td>
            <td><strong><?= h($word['word']) ?></strong></td>
            <td class="small"><?= h(mb_strlen((string)$word['definition']) > 120 ? mb_substr((string)$word['definition'], 0, 120) . '…' : (string)$word['definition']) ?></td>
            <td class="small">
              <?php foreach (array_filter(array_map('trim', explode(',', (string)($word['tags'] ?? '')))) as $tagName): ?>
                <span class="tag-pill"><?= h($tagName) ?></span>
              <?php endforeach; ?>
            </td>
            <td class="small">
              <a class="button small" href="/admin/word_edit.php?id=<?= (int)$word['id'] ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
