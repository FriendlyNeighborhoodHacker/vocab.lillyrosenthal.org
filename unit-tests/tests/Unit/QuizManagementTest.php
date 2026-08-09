<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class QuizManagementTest extends TestCase
{
    private UserContext $adminCtx;
    private UserContext $userCtx;
    private array $wordIds = [];

    protected function setUp(): void
    {
        test_reset_all();
        $this->adminCtx = test_seed_admin();
        $this->userCtx = test_seed_user();

        $this->wordIds = [];
        $this->wordIds['abate'] = WordManagement::addWord(
            $this->adminCtx,
            'abate',
            'to lessen in intensity',
            'The storm finally abated after three long days.',
            'subside, diminish, lessen'
        );
        $this->wordIds['brusque'] = WordManagement::addWord(
            $this->adminCtx,
            'brusque',
            'abrupt in manner',
            'Her brusque reply ended the conversation.',
            'curt, blunt'
        );
        // No example sentence: askable in Guess the Word, not in Fill in the Blank.
        $this->wordIds['candor'] = WordManagement::addWord($this->adminCtx, 'candor', 'openness and honesty');
    }

    private function wordRow(string $word): array
    {
        $row = WordManagement::findByWordText($word);
        $this->assertNotNull($row);
        return $row;
    }

    // --- blanking out the answer ---

    public function testFillBlankPromptRemovesTheWord(): void
    {
        $prompt = QuizManagement::buildFillBlankPrompt('Her brusque reply ended the conversation.', 'brusque');

        $this->assertNotNull($prompt);
        $this->assertStringNotContainsStringIgnoringCase('brusque', $prompt);
        $this->assertStringContainsString(QuizManagement::BLANK, $prompt);
        $this->assertStringContainsString('reply ended the conversation', $prompt);
    }

    public function testFillBlankPromptRemovesInflectedForms(): void
    {
        $cases = [
            ['The storm finally abated after three days.', 'abate'],
            ['The rain was abating by noon.', 'abate'],
            ['We watched the abatement of the storm.', 'abate'],
            ['She spoke with unusual candor.', 'candor'],
            ['He abetted the scheme from the start.', 'abet'],
            ['The wariest travelers packed early.', 'wary'],
            ['They eyed the offer warily.', 'wary'],
        ];

        foreach ($cases as [$sentence, $word]) {
            $prompt = QuizManagement::buildFillBlankPrompt($sentence, $word);
            $this->assertNotNull($prompt, "No prompt built for '$word'");
            $this->assertStringContainsString(QuizManagement::BLANK, $prompt, "Nothing blanked for '$word'");
            $this->assertStringNotContainsStringIgnoringCase(
                $word,
                $prompt,
                "'$word' survived in: $prompt"
            );
        }
    }

    public function testFillBlankPromptIsNullWhenTheSentenceNeverUsesTheWord(): void
    {
        $this->assertNull(QuizManagement::buildFillBlankPrompt('A sentence about something else.', 'abate'));
        $this->assertNull(QuizManagement::buildFillBlankPrompt('', 'abate'));
    }

    public function testFillBlankPromptPicksTheLineThatUsesTheWord(): void
    {
        $sentences = "A first line with nothing useful.\nThe storm abated overnight.";
        $prompt = QuizManagement::buildFillBlankPrompt($sentences, 'abate');

        $this->assertSame('The storm ' . QuizManagement::BLANK . ' overnight.', $prompt);
    }

    public function testDefinitionThatContainsTheWordIsMaskedToo(): void
    {
        $masked = QuizManagement::maskWordInText('to abate is to lessen', 'abate');
        $this->assertSame('to ' . QuizManagement::BLANK . ' is to lessen', $masked);
    }

    public function testFindWordFormsInSentence(): void
    {
        $this->assertSame(['abated'], QuizManagement::findWordFormsInSentence('The storm abated.', 'abate'));
        $this->assertSame([], QuizManagement::findWordFormsInSentence('Nothing here.', 'abate'));
    }

    // --- judging answers ---

    public function testExactAnswerIsCorrectRegardlessOfCaseAndPunctuation(): void
    {
        foreach (['abate', 'ABATE', '  Abate ', 'abate.'] as $typed) {
            $this->assertSame(
                QuizManagement::RESULT_CORRECT,
                QuizManagement::judgeAnswer($typed, $this->wordRow('abate'), QuizManagement::MODE_GUESS_WORD),
                "Rejected '$typed'"
            );
        }
    }

    public function testSingleTypoInALongWordCountsAsClose(): void
    {
        $this->assertSame(
            QuizManagement::RESULT_CLOSE,
            QuizManagement::judgeAnswer('brusqe', $this->wordRow('brusque'), QuizManagement::MODE_GUESS_WORD)
        );
    }

    public function testShortWordsMustBeSpelledExactly(): void
    {
        $shortId = WordManagement::addWord($this->adminCtx, 'wan', 'pale and weak');
        $row = WordManagement::findById($shortId);

        $this->assertSame(
            QuizManagement::RESULT_INCORRECT,
            QuizManagement::judgeAnswer('wax', $row, QuizManagement::MODE_GUESS_WORD)
        );
    }

    public function testAListedSynonymIsCalledOutAsASynonym(): void
    {
        $this->assertSame(
            QuizManagement::RESULT_SYNONYM,
            QuizManagement::judgeAnswer('subside', $this->wordRow('abate'), QuizManagement::MODE_GUESS_WORD)
        );
    }

    public function testUnrelatedAnswerIsIncorrect(): void
    {
        $this->assertSame(
            QuizManagement::RESULT_INCORRECT,
            QuizManagement::judgeAnswer('elephant', $this->wordRow('abate'), QuizManagement::MODE_GUESS_WORD)
        );
        $this->assertSame(
            QuizManagement::RESULT_INCORRECT,
            QuizManagement::judgeAnswer('   ', $this->wordRow('abate'), QuizManagement::MODE_GUESS_WORD)
        );
    }

    public function testFillBlankAcceptsTheInflectedFormTheBlankReplaced(): void
    {
        // The sentence read "abated", so both that and the base word are right.
        $this->assertSame(
            QuizManagement::RESULT_CORRECT,
            QuizManagement::judgeAnswer('abated', $this->wordRow('abate'), QuizManagement::MODE_FILL_BLANK)
        );
        $this->assertSame(
            QuizManagement::RESULT_CORRECT,
            QuizManagement::judgeAnswer('abate', $this->wordRow('abate'), QuizManagement::MODE_FILL_BLANK)
        );

        // Guess the Word asks for the word itself, so the inflection is only close.
        $this->assertSame(
            QuizManagement::RESULT_CLOSE,
            QuizManagement::judgeAnswer('abated', $this->wordRow('abate'), QuizManagement::MODE_GUESS_WORD)
        );
    }

    // --- building rounds ---

    public function testGuessWordRoundUsesEveryWord(): void
    {
        $round = QuizManagement::buildQuizRound(QuizManagement::MODE_GUESS_WORD);
        $this->assertCount(3, $round);
        $this->assertSame(3, QuizManagement::countAvailableQuestions(QuizManagement::MODE_GUESS_WORD));
    }

    public function testFillBlankRoundSkipsWordsWithoutAUsableSentence(): void
    {
        $round = QuizManagement::buildQuizRound(QuizManagement::MODE_FILL_BLANK);

        $this->assertCount(2, $round);
        $this->assertSame(2, QuizManagement::countAvailableQuestions(QuizManagement::MODE_FILL_BLANK));
        $this->assertNotContains($this->wordIds['candor'], array_column($round, 'word_id'));
    }

    public function testQuestionsNeverLeakTheAnswer(): void
    {
        foreach ([QuizManagement::MODE_GUESS_WORD, QuizManagement::MODE_FILL_BLANK] as $mode) {
            foreach (QuizManagement::buildQuizRound($mode) as $question) {
                $word = (string)WordManagement::findById($question['word_id'])['word'];
                $this->assertArrayNotHasKey('word', $question);
                $this->assertStringNotContainsStringIgnoringCase($word, $question['prompt']);
                $this->assertStringNotContainsStringIgnoringCase($word, $question['hint']);
            }
        }
    }

    public function testQuestionsCarryLetterHints(): void
    {
        $round = QuizManagement::buildQuizRound(QuizManagement::MODE_GUESS_WORD);
        $byId = array_column($round, null, 'word_id');

        $this->assertSame(5, $byId[$this->wordIds['abate']]['letters']);
        $this->assertSame('A', $byId[$this->wordIds['abate']]['first_letter']);
    }

    public function testRoundIsLimitedToTheRequestedCount(): void
    {
        $this->assertCount(2, QuizManagement::buildQuizRound(QuizManagement::MODE_GUESS_WORD, [], 2));
        // A limit larger than the pool just returns the pool.
        $this->assertCount(3, QuizManagement::buildQuizRound(QuizManagement::MODE_GUESS_WORD, [], 50));
    }

    public function testRoundCanBeDrawnFromSeveralDecksAtOnce(): void
    {
        WordManagement::setWordTags($this->adminCtx, $this->wordIds['abate'], ['Green']);
        WordManagement::setWordTags($this->adminCtx, $this->wordIds['brusque'], ['White and Blue']);
        $tags = array_column(WordManagement::listAllTags(), null, 'name');
        $green = (int)$tags['Green']['id'];
        $whiteBlue = (int)$tags['White and Blue']['id'];

        $greenOnly = QuizManagement::buildQuizRound(QuizManagement::MODE_GUESS_WORD, [$green]);
        $this->assertSame([$this->wordIds['abate']], array_column($greenOnly, 'word_id'));

        $both = QuizManagement::buildQuizRound(QuizManagement::MODE_GUESS_WORD, [$green, $whiteBlue]);
        $this->assertEqualsCanonicalizing(
            [$this->wordIds['abate'], $this->wordIds['brusque']],
            array_column($both, 'word_id')
        );

        // No decks named = every word.
        $this->assertCount(3, QuizManagement::buildQuizRound(QuizManagement::MODE_GUESS_WORD, []));
    }

    public function testWordTaggedTwiceIsOnlyAskedOnce(): void
    {
        WordManagement::setWordTags($this->adminCtx, $this->wordIds['abate'], ['Green', 'White and Blue']);
        $tags = array_column(WordManagement::listAllTags(), null, 'name');

        $round = QuizManagement::buildQuizRound(
            QuizManagement::MODE_GUESS_WORD,
            [(int)$tags['Green']['id'], (int)$tags['White and Blue']['id']]
        );
        $this->assertSame([$this->wordIds['abate']], array_column($round, 'word_id'));
    }

    public function testBuildRoundRejectsUnknownMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        QuizManagement::buildQuizRound('spelling_bee');
    }

    // --- recording answers ---

    public function testRecordingACorrectAnswerScoresAndStoresIt(): void
    {
        $outcome = QuizManagement::recordAnswer(
            $this->userCtx,
            $this->wordIds['abate'],
            QuizManagement::MODE_GUESS_WORD,
            'Abate'
        );

        $this->assertSame(QuizManagement::RESULT_CORRECT, $outcome['result']);
        $this->assertSame(QuizManagement::POINTS_CORRECT, $outcome['points']);
        $this->assertFalse($outcome['can_claim_correct']);
        $this->assertSame('abate', $outcome['word']);
        $this->assertSame('to lessen in intensity', $outcome['definition']);
        $this->assertSame(QuizManagement::POINTS_CORRECT, $outcome['totals']['points']);

        $st = pdo()->prepare('SELECT * FROM quiz_attempts WHERE id = ?');
        $st->execute([$outcome['attempt_id']]);
        $row = $st->fetch();
        $this->assertSame('Abate', $row['answer_text']);      // stored as typed
        $this->assertSame('guess_word', $row['quiz_mode']);
        $this->assertSame(0, (int)$row['was_overridden']);
    }

    public function testATypoStillScores(): void
    {
        $outcome = QuizManagement::recordAnswer(
            $this->userCtx,
            $this->wordIds['brusque'],
            QuizManagement::MODE_GUESS_WORD,
            'brusqe'
        );

        $this->assertSame(QuizManagement::RESULT_CLOSE, $outcome['result']);
        $this->assertSame(QuizManagement::POINTS_CLOSE, $outcome['points']);
        $this->assertFalse($outcome['can_claim_correct']);
    }

    public function testAWrongAnswerScoresNothingButCanBeClaimed(): void
    {
        $outcome = QuizManagement::recordAnswer(
            $this->userCtx,
            $this->wordIds['abate'],
            QuizManagement::MODE_GUESS_WORD,
            'subside'
        );

        $this->assertSame(QuizManagement::RESULT_SYNONYM, $outcome['result']);
        $this->assertSame(0, $outcome['points']);
        $this->assertTrue($outcome['can_claim_correct']);
    }

    public function testRecordAnswerRejectsAMissingWord(): void
    {
        $this->expectException(InvalidArgumentException::class);
        QuizManagement::recordAnswer($this->userCtx, 999999, QuizManagement::MODE_GUESS_WORD, 'abate');
    }

    // --- "I was right anyway" ---

    public function testClaimingAnAnswerAwardsPartialCredit(): void
    {
        $outcome = QuizManagement::recordAnswer(
            $this->userCtx,
            $this->wordIds['abate'],
            QuizManagement::MODE_GUESS_WORD,
            'subside'
        );
        $claim = QuizManagement::markAttemptCorrectAnyway($this->userCtx, $outcome['attempt_id']);

        $this->assertSame(QuizManagement::POINTS_OVERRIDE, $claim['points']);
        $this->assertSame(QuizManagement::POINTS_OVERRIDE, $claim['totals']['points']);
        $this->assertSame(1, $claim['totals']['correct']);

        $st = pdo()->prepare('SELECT * FROM quiz_attempts WHERE id = ?');
        $st->execute([$outcome['attempt_id']]);
        $row = $st->fetch();
        $this->assertSame(1, (int)$row['was_overridden']);
        $this->assertSame('subside', $row['answer_text']);   // what was typed is preserved
        $this->assertSame('synonym', $row['result']);        // and so is the honest verdict
    }

    public function testClaimingTwiceDoesNotDoubleTheCredit(): void
    {
        $outcome = QuizManagement::recordAnswer(
            $this->userCtx,
            $this->wordIds['abate'],
            QuizManagement::MODE_GUESS_WORD,
            'wrongword'
        );
        QuizManagement::markAttemptCorrectAnyway($this->userCtx, $outcome['attempt_id']);
        $second = QuizManagement::markAttemptCorrectAnyway($this->userCtx, $outcome['attempt_id']);

        $this->assertSame(QuizManagement::POINTS_OVERRIDE, $second['totals']['points']);
    }

    public function testAnAlreadyCorrectAnswerCannotBeClaimed(): void
    {
        $outcome = QuizManagement::recordAnswer(
            $this->userCtx,
            $this->wordIds['abate'],
            QuizManagement::MODE_GUESS_WORD,
            'abate'
        );

        $this->expectException(RuntimeException::class);
        QuizManagement::markAttemptCorrectAnyway($this->userCtx, $outcome['attempt_id']);
    }

    public function testAnotherUsersAnswerCannotBeClaimed(): void
    {
        $outcome = QuizManagement::recordAnswer(
            $this->userCtx,
            $this->wordIds['abate'],
            QuizManagement::MODE_GUESS_WORD,
            'nope'
        );

        $this->expectException(RuntimeException::class);
        QuizManagement::markAttemptCorrectAnyway($this->adminCtx, $outcome['attempt_id']);
    }

    // --- stats ---

    public function testQuizStatsSummarizeThePlayerOnly(): void
    {
        QuizManagement::recordAnswer($this->userCtx, $this->wordIds['abate'], QuizManagement::MODE_GUESS_WORD, 'abate');
        QuizManagement::recordAnswer($this->userCtx, $this->wordIds['brusque'], QuizManagement::MODE_GUESS_WORD, 'brusqe');
        QuizManagement::recordAnswer($this->userCtx, $this->wordIds['candor'], QuizManagement::MODE_GUESS_WORD, 'nope');
        QuizManagement::recordAnswer($this->adminCtx, $this->wordIds['abate'], QuizManagement::MODE_GUESS_WORD, 'abate');

        $stats = QuizManagement::getQuizStatsForUser($this->userCtx->id);
        $this->assertSame(3, $stats['answered']);
        $this->assertSame(2, $stats['correct']);
        $this->assertSame(67, $stats['accuracy']);
        $this->assertSame(QuizManagement::POINTS_CORRECT + QuizManagement::POINTS_CLOSE, $stats['points']);
        $this->assertSame(3, $stats['answered_today']);
        $this->assertSame($stats['points'], $stats['points_today']);

        $empty = QuizManagement::getQuizStatsForUser(999999);
        $this->assertSame(0, $empty['answered']);
        $this->assertSame(0, $empty['accuracy']);
    }
}
