# Lilly's Vocab — SAT flashcard site (vocab.lillyrosenthal.org)

A PHP/MySQL flashcard web app for reviewing SAT vocabulary. Users sign in and
flip through a global deck of word cards, marking each "Got it" or "Need More
Review"; admins manage the word list, mainly via CSV import. Playful visual
style (cream / violet / mint / coral), mobile-friendly, no framework, no build
step. Follows the conventions in docs/php-guidelines.md throughout (PDO only,
SQL only inside lib/*Management classes, every write takes a UserContext and
is activity-logged, `page.php` + `page_eval.php` pairing, CSRF on all POSTs,
dedicated single-purpose AJAX endpoint files).

This file describes what the app actually does today. The original one-page
request it grew from is preserved at the bottom.

## What a signed-in user can do

**Review flashcards** (`/review/`, the home page after login):
- One large card at a time. Click / tap / space flips it with a quick 160ms
  cross-fade: front = the word, back = definition, plus an example sentence
  (italic) and synonyms ("Similar: …" pill) when the word has them.
- Under the card: mint **Got it!** and coral **Need More Review** buttons.
  Marks are per-user, saved by AJAX with optimistic UI (the deck advances
  immediately; failures surface in a toast — errors are never swallowed).
- `<` / `>` buttons flanking the card (and ←/→ keys) browse backward and
  forward without marking. Keyboard: space=flip, 1=got it, 2=needs review,
  f=flag, ←/→=navigate.
- Flag icon top-right of the card (visible on both faces): per-user toggle,
  AJAX-saved.
- **Deck tabs**: All words / Flagged / Misses (words whose latest mark is
  "needs review").
- **Deck dropdown** (appears once tags exist): filters review to one tag,
  e.g. "White and Blue", "Green", "Claude Bonus Words 1". Words are global;
  tags group them into decks. The chosen deck persists across tabs, shuffle,
  and the deck-complete screen.
- **Order / shuffle**: words have a global sort_order; a Shuffle button
  re-deals the user's personal order (deterministic per-user seed —
  `SHA2(seed:word_id)` — so newly imported words slot in automatically), and
  "Original order" restores the global sequence.
- **Resume**: every deck — the full list and each tag deck independently —
  remembers where the user left off ("Card 137 of 250") and resumes there.
  The flagged/misses passes intentionally restart at the top, since they
  shrink as words are cleared. Shuffling or restoring order resets all resume
  points (the card order changed).
- Finishing a deck shows a celebration card with the session tally and
  buttons to go again, shuffle, or review misses/flagged.

**Score chip** (top-right of the header on every page, repainted live after
each mark): ⭐ mastered / total words — "mastered" means the word's *latest*
mark is Got it — with a "N today" subline. Links to the stats page.

**Stats** (`/progress/` — note: NOT `/stats/`, which shared hosts shadow with
their web-statistics alias): big-number tiles (Got it, Need more review,
Flagged, Reviewed today, all-time total) and a pure-CSS bar chart of the last
14 days of review activity. Every Got it / Need More Review click is recorded
in an append-only events table, so per-word counters and daily history both
survive re-marking.

**Account**: change password (`/profile/change_password.php`), logout.
Remember-me is a stateless HMAC cookie invalidated by password changes;
a "public computer" checkbox on login skips it.

## What an admin can do (Admin dropdown in the header)

- **Words**: list (with tag pills and a per-deck filter), add, edit
  (word / definition / sentences / synonyms / tags / sort order), delete
  (cascades everyone's marks and flags for that word).
- **Import Words** — the main way content enters the app. A 4-step CSV wizard
  (Upload → Mapping → Validation → Commit) accepting a file or pasted text,
  comma or tab delimited. Columns: `word, definition, sentences, synonyms,
  tags`; header names are auto-mapped and fixable by hand.
  - Rows match existing words case-insensitively: matches get their **mapped**
    fields updated; unmapped columns are never touched (so a `word,tags` file
    can tag the whole list without disturbing definitions). No match = new
    word appended to the end of the global order.
  - `tags` cells hold deck names separated by `,` or `;`; unknown tags are
    auto-created; a blank mapped cell clears (tags/sentences/synonyms);
    a blank mapped definition on an existing word is an error.
  - Validation shows a per-row Status and a Changes column ("Create new
    word" / "Update sentences, tags" / "No changes"); commit reports
    created / updated / unchanged / skipped. Re-importing the same file is
    idempotent.
- **Users**: admin-created accounts only (no self-registration). Creating a
  user sends an activation email; the user verifies and sets their own
  password. Admins can re-send activation/verification, send password resets,
  grant/revoke admin (not on themselves), and delete users.
- **Settings** (site title, timezone, site URL), **Activity Log** (every write
  and login, filterable), **Email Log** (every send attempt with errors).

## Data model (www/schema.sql is the complete, standalone truth)

- `users` — auth (email + password_hash, verify/reset tokens, is_admin), plus
  `shuffle_seed` (NULL = original order) and `deck_position` (resume point in
  the full deck).
- `words` — the global list: word (unique), definition, sentences, synonyms,
  sort_order.
- `tags` + `word_tags` — global many-to-many "decks" on words.
- `user_word_state` — one row per user×word touched: is_flagged, last_mark
  (got_it / needs_review), per-mark counters, last_reviewed_at.
- `word_review_events` — append-only log of every mark, for "reviewed today"
  and the daily chart.
- `user_deck_positions` — per-user resume point per tag deck.
- `settings`, `activity_log`, `emails_sent` — infrastructure per guidelines.
- Seeded admin for fresh installs: email `lilly`, password `lilly` (change it).

Migrations live in `www/db_migrations/` (currently `2026-08-09_initial_schema`,
`01_add_sentences_and_synonyms`, `02_add_tags`, `03_user_deck_positions`, all
idempotent). schema.sql must always be updated alongside any migration.

## Code layout (web root = www/)

- `config.php` — session, `pdo()` (+ `set_pdo_for_testing()` seam), CSRF,
  remember-me, `current_user()`, `require_login()` / `require_admin()`.
  Secrets in git-ignored `config.local.php` (see `config.local.php.example`;
  includes optional SUPER_PASSWORD test backdoor and SMTP settings).
- `lib/` — `WordManagement` (words + tags), `FlashcardProgress` (decks, marks,
  flags, positions, score/stats), `WordCsvImport` (validate/commit),
  `CsvImport` (pure parsing/mapping), `UserManagement`, `UserContext`,
  `ActivityLog`, `EmailLog`, `Application`, `ApplicationUI` (page shell, nav,
  score chip, filemtime cache-busted assets).
- `review/` — `index.php` (embeds the whole deck as JSON; reads are
  server-rendered, only writes are AJAX), `review.js` (deck engine),
  `mark_word_eval.php`, `toggle_flag_eval.php`, `save_position_eval.php`
  (JSON), `shuffle_eval.php`, `order_eval.php` (PRG).
- `progress/index.php` — stats page.
- `admin/` — words CRUD, `import/` wizard, users, settings, logs.
- Auth pages at root: `login`, `forgot/reset/set_password`, `verify_email`,
  `logout` (+ `profile/change_password`).
- `styles.css` (design tokens in `:root`), `main.js` (menus, auto-submit,
  confirms), `mailer.php` (raw SMTP + EmailLog).

## Development & deployment

- Local: create DB `vocab_lillyrosenthal`, load `www/schema.sql`, copy
  `config.local.php.example` → `config.local.php`, `php -S localhost:8080 -t www`.
- Tests: `php unit-tests/tools/phpunit.phar -c unit-tests/phpunit.xml` —
  73 unit tests over the lib classes (DI via `set_pdo_for_testing`; the
  bootstrap drops/recreates `vocab_lillyrosenthal_test` from schema.sql).
  No endpoint or UI tests, per guidelines.
- Production: Apache-style shared host, docroot at `www/`; `.htaccess` denies
  logs/, db_migrations/, config.local.php, schema.sql. Deploy = upload changed
  files + run any new `db_migrations/*.sql`. Known host quirk: `/stats` is
  shadowed by the host's web-statistics alias, which is why the stats page
  lives at `/progress/`.
- Word source files live in `words/` at the repo root (plain .txt lists, plus
  `bonus_words_to_learn.csv` — 250 import-ready words with definitions, funny
  example sentences, synonyms, tagged "Claude Bonus Words 1").

---

## Original request (historical)

Please generate a flashcard program to help people review vocabulary for the
SAT. The site will be vocab.lillyrosenthal.org.

Users should be able to login and see flashcards of words. The list of words
should be global.

The core function of the site is after someone logs in, they should go through
flashcards.

A user should be able to click on a word and see the definition. (maybe fade
in and out quickly to turn the card, but any effect has to be quick)

For each flashcard / word:
- "Got it" or "Need More Review" (maybe buttons underneath the word). This is
  marked for the user only.
- Flag icon top right. Toggle state (should show when looking at card). Ajax
  save on click. Flagged words are flagged for the user only.
- Stats kept based on each "Got it" or "Need more review."

CSV Upload for words with columns
- word
- definition

There should be an "order" to the words so that a user can know how many
they've gone through, and the user should be able to "shuffle" the words.

The colors should be playful - I want it to be fun to use!

It should keep stats on how many words user go through so that they can feel
progress. Maybe a "score" in the top right after login.

I want this to be a PHP / MYSQL web site. Please follow the guidelines and
create a login infrastructure. There is a similar app (in that it is php and
in the style I want it) in ../portal.bronxconservatory.org

The website should be in the "www" directory.

I want it to work on mobile (like the bronx conservatory website)
