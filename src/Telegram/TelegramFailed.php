<?php

declare(strict_types=1);

namespace CattoPHP\Telegram;

use RuntimeException;

/**
 * Telegram did not take what was asked of it, or could not be reached. Its message is in plain words and never holds the
 * bot's key, which is part of Telegram's address, so it is safe to show and to store (TelegramClient scrubs it).
 */
final class TelegramFailed extends RuntimeException
{
}
