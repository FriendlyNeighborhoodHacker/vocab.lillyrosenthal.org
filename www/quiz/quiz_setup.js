// Quiz launcher: keep the "Which words?" counts pointing at the game currently
// selected, and don't let anyone start a round that has nothing to ask.
// READY_BY_SOURCE is embedded by index.php as {mode: {source: count}}.
(function () {
  'use strict';

  if (typeof READY_BY_SOURCE === 'undefined') return;

  var form = document.querySelector('.quiz-setup');
  if (!form) return;

  var modeInputs = form.querySelectorAll('input[name="mode"]');
  var sourcePicks = form.querySelectorAll('.quiz-source-pick');
  var startBtn = form.querySelector('.quiz-start');

  function selectedMode() {
    for (var i = 0; i < modeInputs.length; i++) {
      if (modeInputs[i].checked) return modeInputs[i].value;
    }
    return null;
  }

  function refreshCounts() {
    var counts = READY_BY_SOURCE[selectedMode()] || {};

    sourcePicks.forEach(function (pick) {
      var count = counts[pick.dataset.source];
      if (count === undefined) return;

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

  refreshCounts();
})();
