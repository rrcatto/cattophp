<?php

declare(strict_types=1);

namespace CattoPHP\Telegram;

/** Sends the sign-in messages a request held once its answer has gone, so the page takes as long whether or not one went. */
final class TelegramDeliveryListener
{
    public function __construct(private readonly TelegramMessenger $messenger)
    {
    }

    public function __invoke(): void
    {
        $this->messenger->deliverHeld();
    }
}
