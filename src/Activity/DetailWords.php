<?php

declare(strict_types=1);

namespace CattoPHP\Activity;

/**
 * How a project words the details of its own actions on a page that lists the activity log (Administration > Activity
 * log), beside ActionWords, which words the action itself. A project adds it with a service that implements this, tagged
 * by autoconfiguration. Each method answers null for what it has no words of its own for, and the log's own wording is
 * used (ActivityDetails): a key turned into words (needs_repair is "Needs repair"), a date or time in the installation's
 * time zone and in words, true or false as yes or no.
 */
interface DetailWords
{
    /** A detail's name in words, such as "Due back" for a loan's due_at; null for the log's own wording. */
    public function label(string $action, string $key): ?string;

    /** A detail's value in words, such as "Needs repair" for a tool's condition; null for the log's own wording. */
    public function value(string $action, string $key, mixed $value): ?string;
}
