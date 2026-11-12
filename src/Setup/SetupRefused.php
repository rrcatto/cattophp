<?php

declare(strict_types=1);

namespace CattoPHP\Setup;

use InvalidArgumentException;

/** A mark the checklist refuses, in words to show the person. */
final class SetupRefused extends InvalidArgumentException
{
}
