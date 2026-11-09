<?php

declare(strict_types=1);

namespace CattoPHP\Access;

use InvalidArgumentException;

/** A change to roles that was refused, with why in plain words. Nothing was changed. */
final class AccessRefused extends InvalidArgumentException
{
}
