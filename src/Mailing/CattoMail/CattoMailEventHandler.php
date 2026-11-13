<?php

declare(strict_types=1);

namespace CattoPHP\Mailing\CattoMail;

/**
 * What an event catto-mail reports means to the project (a hard bounce or a complaint about a copy, a send job finished):
 * a service tagged `cattophp.cattomail_event_handler`, given each kept event of the types it names, once, in the order
 * they came. An event no handler names is kept with nothing done.
 */
interface CattoMailEventHandler
{
    /** @return list<string> the event types it handles, such as message.hard_bounced */
    public function types(): array;

    /**
     * Does what the event means and says what it did, in a few words for the events' page.
     *
     * @param array<string,mixed> $event the event as catto-mail sent it
     */
    public function handle(string $type, array $event): string;
}
