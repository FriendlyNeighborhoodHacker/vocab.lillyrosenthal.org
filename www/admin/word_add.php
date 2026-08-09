<?php
require_once __DIR__ . '/../partials.php';
Application::init();
require_admin();

$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;

// For repopulating form after errors - get from URL parameters
$form = [];
foreach (['word', 'definition', 'sentences', 'synonyms', 'tags'] as $field) {
    if (isset($_GET[$field])) {
        $form[$field] = $_GET[$field];
    }
}

header_html('Add Word');
?>

<h2>Add Word</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/admin/word_add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">

    <label>Word
      <input type="text" name="word" value="<?=h($form['word'] ?? '')?>" maxlength="100" required>
    </label>
    <label>Definition
      <textarea name="definition" rows="3" required><?=h($form['definition'] ?? '')?></textarea>
    </label>
    <label>Sentences
      <textarea name="sentences" rows="2" placeholder="Example sentence(s) using the word (optional)"><?=h($form['sentences'] ?? '')?></textarea>
    </label>
    <label>Synonyms
      <input type="text" name="synonyms" value="<?=h($form['synonyms'] ?? '')?>" placeholder="e.g. reduce, diminish (optional)">
    </label>
    <label>Tags
      <input type="text" name="tags" value="<?=h($form['tags'] ?? '')?>" placeholder='deck name(s), e.g. "White and Blue; Green" (optional)'>
      <small class="small">Separate multiple decks with ; or , — new tags are created automatically.</small>
    </label>

    <p class="small">The word is added to the end of the deck order.</p>

    <div class="actions">
      <button class="button primary" type="submit">Add Word</button>
      <a class="button" href="/admin/words.php">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
