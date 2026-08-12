// Quiz launcher: scope every count on the page — each game's "words ready"
// number and the "Which words?" pool counts — to the deck picked up top, and
// don't let anyone start a round that has nothing to ask. POOL_COUNTS is
// embedded by index.php as {mode: {source: {total, by_tag: {tagId: count}}}}.
(function () {
  'use strict';

  if (typeof POOL_COUNTS === 'undefined') return;

  var form = document.querySelector('.quiz-setup');
  if (!form) return;

  var modeInputs = form.querySelectorAll('input[name="mode"]');
  var modeReadies = form.querySelectorAll('[data-mode-ready]');
  var sourcePicks = form.querySelectorAll('.quiz-source-pick');
  var deckSelect = form.querySelector('select[name="tags[]"]');
  var startBtn = form.querySelector('.quiz-start');

  function selectedMode() {
    for (var i = 0; i < modeInputs.length; i++) {
      if (modeInputs[i].checked) return modeInputs[i].value;
    }
    return null;
  }

  function countFor(mode, source) {
    var entry = (POOL_COUNTS[mode] || {})[source];
    if (!entry) return 0;
    var tagId = deckSelect ? deckSelect.value : '';
    if (!tagId) return entry.total;
    return entry.by_tag[tagId] || 0;
  }

  function refreshCounts() {
    modeReadies.forEach(function (el) {
      el.textContent = countFor(el.dataset.modeReady, 'all') + ' words ready';
    });

    sourcePicks.forEach(function (pick) {
      var count = countFor(selectedMode(), pick.dataset.source);

      pick.querySelector('.quiz-source-count').textContent = count;
      // An empty pool stays visible and explains itself rather than vanishing.
      pick.classList.toggle('empty', count === 0);
      pick.querySelector('input').disabled = count === 0;
    });

    // If the pool that was selected just emptied out, fall back to all words.
    var checked = form.querySelector('input[name="source"]:checked');
    if (!checked || checked.disabled) {
      var fallback = form.querySelector('input[name="source"]:not(:disabled)');
      if (fallback) fallback.checked = true;
    }

    var anything = form.querySelector('input[name="source"]:checked');
    startBtn.classList.toggle('disabled', !anything);
  }

  modeInputs.forEach(function (input) {
    input.addEventListener('change', refreshCounts);
  });
  if (deckSelect) deckSelect.addEventListener('change', refreshCounts);

  refreshCounts();
})();
