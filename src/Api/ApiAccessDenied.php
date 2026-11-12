<?php

declare(strict_types=1);

namespace CattoPHP\Api;

use RuntimeException;

/** A token without the scope, or an account without the permission, an operation needs: the API answers 403 with this message. */
final class ApiAccessDenied extends RuntimeException
{
}
