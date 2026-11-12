<?php

declare(strict_types=1);

namespace CattoPHP\Api;

use RuntimeException;

/** A request's token was missing, not a Bearer token, or invalid, expired or revoked: the API answers 401 with this message. */
final class ApiRefused extends RuntimeException
{
}
