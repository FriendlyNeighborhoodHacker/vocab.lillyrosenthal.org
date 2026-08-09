// Typing-quiz engine. The page embeds QUESTIONS (prompts only — the answers
// stay on the server), QUIZ_MODE, and CSRF. Every answer POSTs to
// answer_eval.php and waits for the verdict; nothing is judged in the browser.
(function () {
  'use strict';

  if (typeof QUESTIONS === 'undefined' || !QUESTIONS.length) return;

  // Cheers are picked at random so the same word twice doesn't feel canned.
  var CHEERS = [
    { burst: '🎉', title: 'Nailed it!' },
    { burst: '⭐', title: 'Word wizard!' },
    { burst: '🚀', title: 'Yes! Exactly right.' },
    { burst: '🌟', title: 'Brilliant.' },
    { burst: '💡', title: 'That is the one!' },
    { burst: '🎯', title: 'Bullseye.' },
    { burst: '🤩', title: 'Look at you go!' },
    { burst: '🌈', title: 'Beautiful work.' },
    { burst: '🔥', title: 'Too easy for you.' },
    { burst: '🍓', title: 'Sweet — spot on.' }
  ];

  var STREAK_CHEERS = {
    3: { burst: '🔥', title: 'Three in a row!' },
    5: { burst: '⚡', title: 'Five straight — unstoppable!' },
    8: { burst: '🏆', title: 'Eight in a row. Showing off now.' },
    12: { burst: '👑', title: 'Twelve straight. Vocabulary royalty.' }
  };

  var CLOSE_CHEERS = [
    { burst: '✍️', title: 'So close — just the spelling!' },
    { burst: '🧠', title: 'You knew it! One letter off.' },
    { burst: '👌', title: 'Right word, sneaky typo.' }
  ];

  var KIND_MISSES = [
    { burst: '🌱', title: 'Not this time — now you know it.' },
    { burst: '📚', title: 'Tricky one. Into the memory bank it goes.' },
    { burst: '👀', title: 'Have a good look at this one.' },
    { burst: '💪', title: 'Missed it — you will get it next round.' }
  ];

  var idx = 0;
  var phase = 'asking';           // 'asking' while typing, 'feedback' after a verdict
  var sessionPoints = 0;
  var sessionRight = 0;
  var streak = 0;
  var bestStreak = 0;
  var currentAttemptId = null;
  var busy = false;

  var stage = document.getElementById('quiz-stage');
  var promptEl = document.getElementById('quiz-prompt');
  var hintBtn = document.getElementById('quiz-hint-btn');
  var hintEl = document.getElementById('quiz-hint');
  var form = document.getElementById('quiz-form');
  var input = document.getElementById('quiz-input');
  var checkBtn = document.getElementById('quiz-check');
  var pointsEl = document.getElementById('quiz-points');
  var streakEl = document.getElementById('quiz-streak');
  var progressFill = document.getElementById('quiz-progress-fill');
  var progressText = document.getElementById('quiz-progress-text');
  var feedback = document.getElementById('quiz-feedback');
  var fbBurst = document.getElementById('quiz-feedback-burst');
  var fbTitle = document.getElementById('quiz-feedback-title');
  var fbWord = document.getElementById('quiz-feedback-word');
  var fbYours = document.getElementById('quiz-feedback-yours');
  var fbDefinition = document.getElementById('quiz-feedback-definition');
  var fbSentence = document.getElementById('quiz-feedback-sentence');
  var fbSynonyms = document.getElementById('quiz-feedback-synonyms');
  var fbPoints = document.getElementById('quiz-feedback-points');
  var claimBtn = document.getElementById('quiz-claim');
  var nextBtn = document.getElementById('quiz-next');
  var donePanel = document.getElementById('quiz-done');
  var doneTitle = document.getElementById('quiz-done-title');
  var doneBurst = document.getElementById('quiz-done-burst');
  var doneScore = document.getElementById('quiz-done-score');
  var doneTally = document.getElementById('quiz-done-tally');
  var toast = document.getElementById('toast');
  var toastTimer;

  function showToast(message) {
    toast.textContent = message;
    toast.classList.remove('hidden');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toast.classList.add('hidden'); }, 4000);
  }

  function pick(list) {
    return list[Math.floor(Math.random() * list.length)];
  }

  function postForm(url, fields) {
    var body = new FormData();
    body.append('csrf', CSRF);
    Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  // Prompts arrive with the word replaced by a run of underscores; draw that
  // run as a proper blank rather than printing the underscores.
  function renderPrompt(el, text) {
    el.textContent = '';
    String(text || '').split(/_{3,}/).forEach(function (part, i) {
      if (i > 0) {
        var blank = document.createElement('span');
        blank.className = 'quiz-blank';
        blank.textContent = ' ';
        el.appendChild(blank);
      }
      if (part) el.appendChild(document.createTextNode(part));
    });
  }

  function renderProgress() {
    var done = Math.min(idx, QUESTIONS.length);
    progressFill.style.width = (done / QUESTIONS.length) * 100 + '%';
    progressText.textContent = done < QUESTIONS.length
      ? 'Question ' + (done + 1) + ' of ' + QUESTIONS.length
      : QUESTIONS.length + ' of ' + QUESTIONS.length;
  }

  function renderStreak() {
    if (streak >= 2) {
      streakEl.textContent = '🔥 ' + streak + ' in a row';
      streakEl.classList.remove('hidden');
    } else {
      streakEl.classList.add('hidden');
    }
  }

  function renderQuestion() {
    renderProgress();

    if (idx >= QUESTIONS.length) {
      finishRound();
      return;
    }

    var q = QUESTIONS[idx];
    phase = 'asking';
    currentAttemptId = null;
    feedback.classList.add('hidden');
    form.classList.remove('hidden');
    renderPrompt(promptEl, q.prompt);
    promptEl.classList.remove('pop');
    void promptEl.offsetWidth;          // restart the entrance animation
    promptEl.classList.add('pop');

    hintEl.classList.add('hidden');
    hintEl.textContent = '';
    hintBtn.classList.remove('hidden');

    input.value = '';
    input.disabled = false;
    checkBtn.disabled = false;
    renderStreak();
    input.focus();
  }

  function showHint() {
    var q = QUESTIONS[idx];
    hintEl.textContent = '';

    var letters = document.createElement('div');
    letters.className = 'quiz-hint-letters';
    letters.textContent = q.letters + ' letters, starts with "' + q.first_letter + '"';
    hintEl.appendChild(letters);

    if (q.hint) {
      var extra = document.createElement('div');
      extra.className = 'quiz-hint-extra';
      renderPrompt(extra, q.hint);
      hintEl.appendChild(extra);
    }

    hintEl.classList.remove('hidden');
    hintBtn.classList.add('hidden');
    input.focus();
  }

  function submitAnswer() {
    if (phase !== 'asking' || busy) return;
    var answer = input.value.trim();
    if (!answer) { input.focus(); return; }

    busy = true;
    input.disabled = true;
    checkBtn.disabled = true;

    postForm('/quiz/answer_eval.php', {
      word_id: QUESTIONS[idx].word_id,
      mode: QUIZ_MODE,
      answer: answer
    })
      .then(function (res) {
        busy = false;
        if (!res.ok) {
          input.disabled = false;
          checkBtn.disabled = false;
          input.focus();
          showToast('Could not check that: ' + (res.error || 'unknown error'));
          return;
        }
        showFeedback(res, answer);
      })
      .catch(function () {
        busy = false;
        input.disabled = false;
        checkBtn.disabled = false;
        input.focus();
        showToast('Could not check that — check your connection.');
      });
  }

  function showFeedback(res, typed) {
    phase = 'feedback';
    currentAttemptId = res.attempt_id;

    var landed = res.result === 'correct' || res.result === 'close';
    if (landed) {
      sessionRight++;
      streak++;
      bestStreak = Math.max(bestStreak, streak);
    } else {
      streak = 0;
    }
    sessionPoints += res.points;
    pointsEl.textContent = sessionPoints;

    var cheer;
    if (res.result === 'correct') {
      cheer = STREAK_CHEERS[streak] || pick(CHEERS);
    } else if (res.result === 'close') {
      cheer = pick(CLOSE_CHEERS);
    } else if (res.result === 'synonym') {
      cheer = { burst: '🤔', title: 'That is a synonym — close thinking!' };
    } else {
      cheer = pick(KIND_MISSES);
    }

    feedback.className = 'quiz-feedback hidden result-' + res.result;
    fbBurst.textContent = cheer.burst;
    fbTitle.textContent = cheer.title;
    fbWord.textContent = res.word;

    if (res.result === 'correct') {
      fbYours.classList.add('hidden');
    } else {
      fbYours.textContent = 'You typed "' + typed + '"';
      fbYours.classList.remove('hidden');
    }

    // In Guess the Word the definition is the prompt sitting right above, so
    // repeating it here would just pad the panel.
    fbDefinition.textContent = res.definition;
    fbDefinition.classList.toggle('hidden', QUIZ_MODE === 'guess_word');
    fbSentence.textContent = res.sentences || '';
    fbSentence.classList.toggle('hidden', !res.sentences);
    fbSynonyms.textContent = res.synonyms ? 'Similar: ' + res.synonyms : '';
    fbSynonyms.classList.toggle('hidden', !res.synonyms);
    fbPoints.textContent = res.points > 0 ? '+' + res.points + ' points' : 'No points this time';

    // The escape hatch: a definition can fit more than one word, so anything
    // that scored nothing can be claimed as right anyway.
    claimBtn.classList.toggle('hidden', !res.can_claim_correct);
    claimBtn.disabled = false;

    form.classList.add('hidden');
    hintBtn.classList.add('hidden');
    feedback.classList.remove('hidden');
    renderStreak();

    // Show the verdict from the top — focusing the button alone would scroll a
    // phone straight past the word we just revealed.
    feedback.scrollIntoView({ block: 'nearest' });
    nextBtn.focus({ preventScroll: true });
  }

  function claimCorrect() {
    if (!currentAttemptId || claimBtn.disabled) return;
    claimBtn.disabled = true;

    postForm('/quiz/claim_correct_eval.php', { attempt_id: currentAttemptId })
      .then(function (res) {
        if (!res.ok) {
          claimBtn.disabled = false;
          showToast('Could not count that: ' + (res.error || 'unknown error'));
          return;
        }
        sessionPoints += res.points;
        sessionRight++;
        pointsEl.textContent = sessionPoints;
        claimBtn.classList.add('hidden');
        fbPoints.textContent = '+' + res.points + ' points — counted!';
        feedback.classList.add('claimed');
        nextBtn.focus();
      })
      .catch(function () {
        claimBtn.disabled = false;
        showToast('Could not count that — check your connection.');
      });
  }

  function nextQuestion() {
    if (phase !== 'feedback') return;
    idx++;
    feedback.classList.remove('claimed');
    renderQuestion();
  }

  function finishRound() {
    stage.classList.add('hidden');
    donePanel.classList.remove('hidden');

    var pct = Math.round((sessionRight / QUESTIONS.length) * 100);
    if (pct === 100) {
      doneBurst.textContent = '🏆';
      doneTitle.textContent = 'A perfect round!';
    } else if (pct >= 80) {
      doneBurst.textContent = '🎉';
      doneTitle.textContent = 'Great round!';
    } else if (pct >= 50) {
      doneBurst.textContent = '💪';
      doneTitle.textContent = 'Solid work.';
    } else {
      doneBurst.textContent = '🌱';
      doneTitle.textContent = 'Every round makes the next one easier.';
    }

    doneScore.textContent = sessionPoints + ' points';
    doneTally.textContent = sessionRight + ' of ' + QUESTIONS.length + ' right (' + pct + '%)'
      + (bestStreak >= 3 ? ' · best streak: ' + bestStreak : '');
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    submitAnswer();
  });
  hintBtn.addEventListener('click', showHint);
  claimBtn.addEventListener('click', claimCorrect);
  nextBtn.addEventListener('click', nextQuestion);

  // Enter moves on from the verdict even when focus has wandered off the button.
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || phase !== 'feedback') return;
    if (e.target === nextBtn || e.target === claimBtn) return;
    e.preventDefault();
    nextQuestion();
  });

  renderQuestion();
})();
