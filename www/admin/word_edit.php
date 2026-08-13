<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/WordManagement.php';
require_once __DIR__ . '/../lib/WordSentences.php';
Application::init();
require_admin();

$wordId = (int)($_GET['id'] ?? 0);
if ($wordId <= 0) {
    header('Location: /admin/words.php');
    exit;
}

$word = WordManagement::findById($wordId);
if (!$word) {
    header('Location: /admin/words.php?err=' . urlencode('Word not found.'));
    exit;
}

$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;

// For repopulating form after errors - get from URL parameters or the word row
$form = [];
foreach (['word', 'definition', 'sentences', 'synonyms', 'sort_order'] as $field) {
    $form[$field] = $_GET[$field] ?? ($word[$field] ?? '');
}
$form['tags'] = $_GET['tags'] ?? implode(', ', WordManagement::tagNamesForWord($wordId));
// Sentences are stored as a JSON array; the textarea edits them one per line.
$form['sentences'] = $_GET['sentences'] ?? WordSentences::asLines($word['sentences'] ?? null);

header_html('Edit ' . $word['word']);
?>

<div class="page-head">
  <h2>Edit "<?= h($word['word']) ?>"</h2>
  <a class="button" href="/admin/words.php">Back to Words</a>
</div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/admin/word_edit_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?= (int)$wordId ?>">

    <label>Word
      <input type="text" name="word" value="<?=h($form['word'])?>" maxlength="100" required>
    </label>
    <label>Definition
      <textarea name="definition" rows="3" required><?=h($form['definition'])?></textarea>
    </label>
    <label>Sentences
      <textarea name="sentences" rows="3" placeholder="Example sentence(s) using the word (optional)"><?=h($form['sentences'])?></textarea>
      <small class="small">One sentence per line (or separated by |) — every one of them shows on the flashcard.</small>
    </label>
    <label>Synonyms
      <input type="text" name="synonyms" value="<?=h($form['synonyms'])?>" placeholder="e.g. reduce, diminish (optional)">
    </label>
    <label>Tags
      <input type="text" name="tags" value="<?=h($form['tags'])?>" placeholder='deck name(s), e.g. "White and Blue; Green" (optional)'>
      <small class="small">Separate multiple decks with ; or , — new tags are created automatically. Clearing this removes the word from every deck.</small>
    </label>
    <label>Order
      <input type="number" name="sort_order" value="<?=h((string)$form['sort_order'])?>" required>
      <small class="small">The word's position in the deck's original order.</small>
    </label>

    <div class="actions">
      <button class="button primary" type="submit">Update Word</button>
    </div>
  </form>
</div>

<div class="card">
  <h3>Danger Zone</h3>
  <form method="post" action="/admin/word_delete_eval.php">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?= (int)$wordId ?>">
    <button class="button danger" type="submit" data-confirm="Delete this word? Everyone's marks and flags for it will be removed too.">Delete Word</button>
  </form>
</div>

<?php footer_html(); ?>
