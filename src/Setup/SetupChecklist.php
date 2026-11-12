<?php

declare(strict_types=1);

namespace CattoPHP\Setup;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use CattoPHP\Security\Redactor;
use LogicException;
use Psr\Clock\ClockInterface;

/**
 * The setup checklist: every step a new installation needs, in order, from the project's providers, each with whether
 * it is done. A step the site checks is done while the site finds it so; a step that needs you is done when a person
 * marks it, and can be skipped for now or opened again. Whatever a step says it found is shown with its secrets hidden.
 */
final class SetupChecklist
{
    /** @param iterable<SetupStepProvider> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly SetupMarkStore $marks,
        private readonly ClockInterface $clock,
        private readonly ?ActivityLog $activity = null,
    ) {
    }

    /**
     * Every step in order, with whether it is done and whether a person can mark it.
     *
     * @return list<array{key:string,group:string,title:string,purpose:string,state:string,done:bool,skipped:bool,needs_you:bool,markable:bool,marked_at:?string,note:string,href:string,link:string,action:string}>
     */
    public function steps(): array
    {
        $marks = $this->marks->all();
        $rows = [];
        foreach ($this->collect(new SetupMarks($marks)) as $step) {
            $mark = $step->needsYou ? ($marks[$step->key] ?? null) : null;
            $done = $step->checked === true || $mark?->isDone() === true;
            $rows[] = [
                'key' => $step->key,
                'group' => $step->group,
                'title' => $step->title,
                'purpose' => $step->purpose,
                'state' => Redactor::text($step->state),
                'done' => $done,
                'skipped' => !$done && $mark !== null && !$mark->isDone(),
                'needs_you' => $step->needsYou,
                // Marked only where the mark decides: a step the site found done offers nothing to undo.
                'markable' => $step->needsYou && (!$done || $mark !== null),
                'marked_at' => $mark?->at->format(DATE_ATOM),
                'note' => Redactor::text($mark->note ?? ''),
                'href' => $step->href,
                'link' => $step->link,
                'action' => $step->action,
            ];
        }
        return $rows;
    }

    /**
     * How far the setup has come, and the next step to do (a skipped step is passed over).
     *
     * @return array{done:int,total:int,next:?array<string,mixed>}
     */
    public function progress(): array
    {
        $steps = $this->steps();
        $next = null;
        foreach ($steps as $step) {
            if (!$step['done'] && !$step['skipped']) {
                $next = $step;
                break;
            }
        }
        return ['done' => count(array_filter($steps, static fn(array $step): bool => $step['done'])), 'total' => count($steps), 'next' => $next];
    }

    /** A person marks a step that needs them done. */
    public function markDone(string $key, ?Actor $actor = null, string $note = ''): void
    {
        $this->markable($key);
        $actor = $this->who($actor);
        $this->marks->save($key, new SetupMark(SetupMark::DONE, $this->clock->now(), $actor->id, $note));
        $this->activity?->record('setup.step_marked', ['step' => $key, 'done' => true], 'setup_step', $key, $actor);
    }

    /** A person leaves a step that needs them for now: it is passed over as the next step, and not done. */
    public function skip(string $key, ?Actor $actor = null, string $note = ''): void
    {
        $this->markable($key);
        $actor = $this->who($actor);
        $this->marks->save($key, new SetupMark(SetupMark::SKIPPED, $this->clock->now(), $actor->id, $note));
        $this->activity?->record('setup.step_skipped', ['step' => $key], 'setup_step', $key, $actor);
    }

    /** A person takes their mark off a step: it is to do again. */
    public function reopen(string $key, ?Actor $actor = null): void
    {
        $this->markable($key);
        $this->marks->remove($key);
        $this->activity?->record('setup.step_marked', ['step' => $key, 'done' => false], 'setup_step', $key, $this->who($actor));
    }

    /** Refuses a step that does not exist, or one the site decides by itself. */
    private function markable(string $key): void
    {
        foreach ($this->collect(new SetupMarks($this->marks->all())) as $step) {
            if ($step->key !== $key) continue;
            if (!$step->needsYou) throw new SetupRefused('That step is done by what the site holds, not by marking it.');
            return;
        }
        throw new SetupRefused('There is no setup step of that name.');
    }

    private function who(?Actor $actor): Actor
    {
        return $actor ?? $this->activity?->signedIn() ?? Actor::system();
    }

    /** @return list<SetupStep> */
    private function collect(SetupMarks $marks): array
    {
        $steps = [];
        $seen = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->steps($marks) as $step) {
                if (isset($seen[$step->key])) {
                    throw new LogicException(sprintf('Two setup steps are named "%s": %s and %s.', $step->key, $seen[$step->key], $provider::class));
                }
                $seen[$step->key] = $provider::class;
                $steps[] = $step;
            }
        }
        return $steps;
    }
}
