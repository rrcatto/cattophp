<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Setup;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use CattoPHP\Activity\MemoryActivityStore;
use CattoPHP\Setup\MemorySetupMarkStore;
use CattoPHP\Setup\SetupChecklist;
use CattoPHP\Setup\SetupMarks;
use CattoPHP\Setup\SetupRefused;
use CattoPHP\Setup\SetupStep;
use CattoPHP\Setup\SetupStepProvider;
use CattoPHP\Tests\Support\ExampleSetupSteps;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SetupChecklistTest extends TestCase
{
    private ExampleSetupSteps $project;
    private MemorySetupMarkStore $marks;
    private MemoryActivityStore $activity;
    private MockClock $clock;
    private SetupChecklist $checklist;

    protected function setUp(): void
    {
        $this->project = new ExampleSetupSteps();
        $this->marks = new MemorySetupMarkStore();
        $this->activity = new MemoryActivityStore();
        $this->clock = new MockClock('2026-11-12 14:00:00+02:00');
        $this->checklist = new SetupChecklist([$this->project], $this->marks, $this->clock, new ActivityLog($this->activity, $this->clock));
    }

    public function testTheStepsComeInTheProjectsOrderWithNothingDoneAtFirst(): void
    {
        $steps = $this->checklist->steps();
        self::assertSame(['details', 'mail', 'verification', 'theme'], array_column($steps, 'key'));
        self::assertSame([false, false, false, false], array_column($steps, 'done'));
        self::assertSame([false, true, true, true], array_column($steps, 'needs_you'));
        self::assertSame([false, true, true, true], array_column($steps, 'markable'), 'Only a step that needs you is marked.');
        self::assertSame(['', '', '', 'pick-theme'], array_column($steps, 'action'));
        self::assertSame(['done' => 0, 'total' => 4, 'next' => 'details'], ['done' => $this->checklist->progress()['done'], 'total' => $this->checklist->progress()['total'], 'next' => $this->checklist->progress()['next']['key'] ?? null]);
    }

    public function testAStepTheSiteChecksIsDoneWhileTheSiteFindsItSo(): void
    {
        $this->project->detailsFilled = true;
        self::assertTrue($this->step('details')['done']);
        self::assertSame('Set: Example Learning.', $this->step('details')['state']);
        self::assertSame('mail', $this->checklist->progress()['next']['key'] ?? null);
        try {
            $this->checklist->markDone('details', new Actor('7', 'Ada'));
            self::fail('A step the site decides is not marked by hand.');
        } catch (SetupRefused $refused) {
            self::assertSame('That step is done by what the site holds, not by marking it.', $refused->getMessage());
        }
        $this->expectException(SetupRefused::class);
        $this->expectExceptionMessage('There is no setup step of that name.');
        $this->checklist->markDone('nonsense', new Actor('7', 'Ada'));
    }

    public function testAPersonMarksAStepDoneAndOpensItAgainAndEachIsRecorded(): void
    {
        $this->checklist->markDone('theme', new Actor('7', 'Ada'), '  Saharan Sunset chosen.  ');
        $theme = $this->step('theme');
        self::assertTrue($theme['done']);
        self::assertTrue($theme['markable'], 'A marked step can be marked not done again.');
        self::assertSame(['2026-11-12T14:00:00+02:00', 'Saharan Sunset chosen.'], [$theme['marked_at'], $theme['note']]);
        self::assertSame('7', $this->marks->all()['theme']->by);
        $this->checklist->reopen('theme', new Actor('7', 'Ada'));
        self::assertFalse($this->step('theme')['done']);
        self::assertArrayNotHasKey('theme', $this->marks->all());
        $entries = $this->activity->recent(10, 'setup.step_marked');
        self::assertCount(2, $entries);
        self::assertSame([['step' => 'theme', 'done' => false], ['step' => 'theme', 'done' => true]], array_map(static fn($entry): array => $entry->details, $entries));
        self::assertSame(['setup_step', 'theme', '7', 'Ada'], [$entries[0]->subjectType, $entries[0]->subjectId, $entries[0]->actorId, $entries[0]->actorName]);
    }

    public function testAStepTheSiteFoundDoneOffersNothingToUndoUnlessAPersonMarkedIt(): void
    {
        $this->project->verificationConfigured = true;
        self::assertSame([true, false], [$this->step('verification')['done'], $this->step('verification')['markable']]);
        $this->project->verificationConfigured = false;
        $this->checklist->markDone('verification', new Actor('7', 'Ada'));
        self::assertSame([true, true], [$this->step('verification')['done'], $this->step('verification')['markable']], 'Marked done by a person until the site can tell.');
    }

    public function testASkippedStepIsPassedOverAsTheNextAndIsNotDone(): void
    {
        $this->project->detailsFilled = true;
        $this->checklist->skip('mail', new Actor('7', 'Ada'), 'No mail server yet.');
        $mail = $this->step('mail');
        self::assertSame([false, true, true], [$mail['done'], $mail['skipped'], $mail['markable']]);
        self::assertSame('verification', $this->checklist->progress()['next']['key'] ?? null);
        self::assertSame(1, $this->checklist->progress()['done']);
        self::assertCount(1, $this->activity->recent(10, 'setup.step_skipped'));
        $this->checklist->markDone('mail', new Actor('7', 'Ada'));
        self::assertSame([true, false], [$this->step('mail')['done'], $this->step('mail')['skipped']]);
        self::assertSame('A test email was sent.', $this->step('mail')['state'], 'The project words its state from the marks.');
    }

    public function testEveryStepDoneLeavesNothingNext(): void
    {
        $this->project->detailsFilled = true;
        foreach (['mail', 'verification', 'theme'] as $key) {
            $this->checklist->markDone($key, new Actor('7', 'Ada'));
        }
        self::assertSame(['done' => 4, 'total' => 4, 'next' => null], $this->checklist->progress());
    }

    public function testWhatAStepFoundAndANoteAreShownWithTheirSecretsHidden(): void
    {
        $this->project->mailState = 'Sending through smtp://site:hunter2@mail.example.com:587.';
        $this->checklist->markDone('theme', new Actor('7', 'Ada'), 'api_key=abc123');
        self::assertSame('Sending through smtp://site:••••@mail.example.com:587.', $this->step('mail')['state']);
        self::assertSame('api_key=••••', $this->step('theme')['note']);
    }

    public function testWithoutAnActorTheMarkIsTheSystems(): void
    {
        $this->checklist->markDone('theme');
        self::assertNull($this->marks->all()['theme']->by);
        self::assertSame('The system', $this->activity->recent(1)[0]->actorName);
    }

    public function testSeveralProvidersAreListedInTurnAndAStepNameIsTheirsAlone(): void
    {
        $more = new class implements SetupStepProvider {
            public function steps(SetupMarks $marks): iterable
            {
                yield new SetupStep('launch', 'Running', 'Go live', 'Make one real purchase.', 'Mark this step done when you have.', null, true, '/courses', 'Open the catalogue');
            }
        };
        $checklist = new SetupChecklist([$this->project, $more], $this->marks, $this->clock);
        self::assertSame(['details', 'mail', 'verification', 'theme', 'launch'], array_column($checklist->steps(), 'key'));
        $checklist->markDone('launch', new Actor('7', 'Ada'));
        self::assertTrue($this->marks->all()['launch']->isDone(), 'Without an activity log the mark is still kept.');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Two setup steps are named "details"');
        (new SetupChecklist([$this->project, new ExampleSetupSteps()], $this->marks, $this->clock))->steps();
    }

    public function testAStepIsNamedPlainlyAndOneTheSiteCannotCheckNeedsYou(): void
    {
        foreach (['Theme' => 'A setup step is named in small letters', '' => 'A setup step is named in small letters', 'site owner' => 'A setup step is named in small letters'] as $key => $words) {
            try {
                new SetupStep($key, 'Look', 'Theme', '', '', null, true, '/themes', 'Open');
                self::fail('"' . $key . '" is refused.');
            } catch (InvalidArgumentException $refused) {
                self::assertStringStartsWith($words, $refused->getMessage());
            }
        }
        foreach (['site-owner', 'web_identity', 'a'] as $key) {
            self::assertSame($key, (new SetupStep($key, 'Look', 'Title', '', '', true, false, '/', 'Open'))->key);
        }
        $this->expectExceptionMessage('The site cannot tell whether the setup step "theme" is done, so a person must mark it: it needs you.');
        new SetupStep('theme', 'Look', 'Theme', '', '', null, false, '/themes', 'Open');
    }

    /** @return array<string,mixed> */
    private function step(string $key): array
    {
        foreach ($this->checklist->steps() as $step) {
            if ($step['key'] === $key) return $step;
        }
        self::fail('No step ' . $key);
    }
}
