<?php

declare(strict_types=1);

namespace CattoPHP\Runtime;

/**
 * How the program runs: APP_ENV is development or production, the one setting for it. A site is in production only
 * when APP_ENV says production, in either case; anything else, or nothing, is development. Debugging follows it: on in
 * development, off in production. It is read before the database is reached, so no Settings page changes it, and only
 * the site's owner does, in .env. A project's front controller and console start the kernel with these:
 *
 *     new Kernel(RunMode::environment(), RunMode::debug());
 */
final class RunMode
{
    public const DEVELOPMENT = 'development';
    public const PRODUCTION = 'production';

    /** development, unless $value (APP_ENV when null) says production. */
    public static function environment(?string $value = null): string
    {
        $value ??= self::read();

        return strtolower(trim($value)) === self::PRODUCTION ? self::PRODUCTION : self::DEVELOPMENT;
    }

    public static function debug(?string $value = null): bool
    {
        return self::environment($value) === self::DEVELOPMENT;
    }

    private static function read(): string
    {
        $value = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');

        return is_scalar($value) ? (string) $value : '';
    }
}
