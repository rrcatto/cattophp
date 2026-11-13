<?php

declare(strict_types=1);

namespace CattoPHP\Http;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Throwable;

/**
 * Work done once the response has gone (kernel.terminate), so the person waits for none of it and its time never shows in
 * how long the page took: the sign-in page asks for a link this way for every address, whether it has an account, may
 * join or neither, so the time taken never tells which. Each piece runs in the order given; one that fails is written to
 * the log and the rest still run. The bundle registers it as a kernel.terminate listener (onTerminate); run() does the
 * same for a command or a test.
 */
final class AfterResponse
{
    /** @var list<Closure():void> */
    private array $waiting = [];

    private readonly LoggerInterface $logger;

    /** @param RequestStack|null $requests the request the work was asked for is put back on it while the work runs */
    public function __construct(?LoggerInterface $logger = null, private readonly ?RequestStack $requests = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /** @param Closure():void $work */
    public function later(Closure $work): void
    {
        $this->waiting[] = $work;
    }

    /** How many pieces of work wait for the response to go. */
    public function waiting(): int
    {
        return count($this->waiting);
    }

    /** Runs everything waiting, in the order given: what the kernel.terminate listener calls. */
    public function run(): void
    {
        while ($this->waiting !== []) {
            $work = array_shift($this->waiting);
            try {
                $work();
            } catch (Throwable $failed) {
                $this->logger->error('Work after the response failed: {message}', ['message' => $failed->getMessage(), 'exception' => $failed]);
            }
        }
    }

    /**
     * After the response: the request is put back on the request stack while the work runs (Symfony has taken it off by
     * then), so what the work records, such as the activity log's network address, is as it would have been before.
     */
    public function onTerminate(TerminateEvent $event): void
    {
        if ($this->waiting === []) {
            return;
        }
        $requests = $this->requests !== null && $this->requests->getMainRequest() === null ? $this->requests : null;
        $requests?->push($event->getRequest());
        try {
            $this->run();
        } finally {
            $requests?->pop();
        }
    }
}
