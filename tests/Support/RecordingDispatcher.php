<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use Psr\EventDispatcher\EventDispatcherInterface;

/** An event dispatcher that keeps every event it is given, in order, for a test to read; a listener may be given to fail. */
final class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    /** @var (callable(object):void)|null */
    public $listener = null;

    public function dispatch(object $event): object
    {
        $this->events[] = $event;
        if ($this->listener !== null) {
            ($this->listener)($event);
        }

        return $event;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return list<T> the events of one class
     */
    public function of(string $class): array
    {
        return array_values(array_filter($this->events, static fn(object $event): bool => $event instanceof $class));
    }
}
