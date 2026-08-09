// Flashcard deck engine. The page embeds DECK (the user's full ordered deck),
// START_AT (resume index), PERSIST_POSITION, and CSRF. Reads never hit the
// server; marks and flag toggles POST to the dedicated eval endpoints and the
// UI advances optimistically.
(function () {
  'use strict';

  if (typeof DECK === 'undefined' || !DECK.length) return;

  var idx = Math.min(START_AT, DECK.length);
  var sessionGot = 0;
  var sessionMiss = 0;

  var stage = document.getElementById('flashcard-stage');
  var card = document.getElementById('flashcard');
  var wordEl = document.getElementById('card-word');
  var definitionEl = document.getElementById('card-definition');
  var flagBtn = document.getElementById('flag-btn');
  var btnGot = document.getElementById('btn-got');
  var btnMiss = document.getElementById('btn-miss');
  var progressFill = document.getElementById('progress-fill');
  var progressText = document.getElementById('progress-text');
  var donePanel = document.getElementById('deck-done');
  var doneTally = document.getElementById('deck-done-tally');
  var toast = document.getElementById('toast');
  var toastTimer;

  function showToast(message) {
    toast.textContent = message;
    toast.classList.remove('hidden');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toast.classList.add('hidden'); }, 4000);
  }

  function postForm(url, fields) {
    var body = new FormData();
    body.append('csrf', CSRF);
    Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  function updateScoreChip(score) {
    if (!score) return;
    var mastered = document.getElementById('score-mastered');
    var total = document.getElementById('score-total');
    var today = document.getElementById('score-today');
    if (mastered) mastered.textContent = score.mastered.toLocaleString();
    if (total) total.textContent = score.total_words.toLocaleString();
    if (today) today.textContent = score.reviewed_today.toLocaleString();
  }

  function renderProgress() {
    var done = Math.min(idx, DECK.length);
    progressFill.style.width = (DECK.length ? (done / DECK.length) * 100 : 0) + '%';
    progressText.textContent = done < DECK.length
      ? 'Card ' + (done + 1) + ' of ' + DECK.length
      : DECK.length + ' of ' + DECK.length;
  }

  function renderCard() {
    renderProgress();

    if (idx >= DECK.length) {
      stage.classList.add('hidden');
      donePanel.classList.remove('hidden');
      doneTally.textContent = sessionGot + sessionMiss > 0
        ? 'This round: ' + sessionGot + ' got it, ' + sessionMiss + ' to review again.'
        : 'You’re all the way through!';
      return;
    }

    var entry = DECK[idx];
    card.classList.remove('flipped');
    wordEl.textContent = entry.word;
    definitionEl.textContent = entry.definition;
    flagBtn.classList.toggle('flagged', entry.flagged);
    flagBtn.setAttribute('aria-pressed', entry.flagged ? 'true' : 'false');
  }

  function flipCard() {
    if (idx >= DECK.length) return;
    card.classList.toggle('flipped');
  }

  function markCurrent(mark) {
    if (idx >= DECK.length) return;
    var entry = DECK[idx];
    if (mark === 'got_it') { sessionGot++; } else { sessionMiss++; }

    // Optimistic UI: advance immediately, report errors via toast.
    idx++;
    var fields = { word_id: entry.id, mark: mark };
    if (PERSIST_POSITION) fields.position = idx;

    card.classList.add('leaving');
    setTimeout(function () {
      card.classList.remove('leaving');
      renderCard();
    }, 140);

    postForm('/review/mark_word_eval.php', fields)
      .then(function (res) {
        if (!res.ok) { showToast('Could not save: ' + (res.error || 'unknown error')); return; }
        updateScoreChip(res.score);
      })
      .catch(function () { showToast('Could not save — check your connection.'); });
  }

  function toggleFlag() {
    if (idx >= DECK.length) return;
    var entry = DECK[idx];
    entry.flagged = !entry.flagged;
    flagBtn.classList.toggle('flagged', entry.flagged);
    flagBtn.setAttribute('aria-pressed', entry.flagged ? 'true' : 'false');

    postForm('/review/toggle_flag_eval.php', { word_id: entry.id, flagged: entry.flagged ? 1 : 0 })
      .then(function (res) {
        if (!res.ok) {
          entry.flagged = !entry.flagged;
          flagBtn.classList.toggle('flagged', entry.flagged);
          showToast('Could not save flag: ' + (res.error || 'unknown error'));
        }
      })
      .catch(function () {
        entry.flagged = !entry.flagged;
        flagBtn.classList.toggle('flagged', entry.flagged);
        showToast('Could not save flag — check your connection.');
      });
  }

  card.addEventListener('click', function (e) {
    if (flagBtn.contains(e.target)) return;
    flipCard();
  });
  card.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); flipCard(); }
  });
  flagBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    toggleFlag();
  });
  btnGot.addEventListener('click', function () { markCurrent('got_it'); });
  btnMiss.addEventListener('click', function () { markCurrent('needs_review'); });

  document.addEventListener('keydown', function (e) {
    if (e.target.matches('input, textarea, select')) return;
    if (e.key === ' ') { e.preventDefault(); flipCard(); }
    else if (e.key === '1') { markCurrent('got_it'); }
    else if (e.key === '2') { markCurrent('needs_review'); }
    else if (e.key === 'f' || e.key === 'F') { toggleFlag(); }
  });

  renderCard();
})();
