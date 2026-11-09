<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/** A logger that keeps each message, with its placeholders filled in, for tests to read. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    /** @param array<string,mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }
        $this->lines[] = $level . ': ' . strtr((string) $message, $replace);
    }
}
