<?php

declare(strict_types=1);

namespace CattoPHP\Identity;

use RuntimeException;

/** A webhook that did not come from Didit, or not within the last 5 minutes: refused with 401 and never acted on. */
final class InvalidDiditWebhook extends RuntimeException
{
}
