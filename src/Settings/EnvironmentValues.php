<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

/**
 * The starting values of the settings: what .env gives (what Symfony's Dotenv put in $_ENV and $_SERVER, or the
 * process's own environment), or what the project's configuration gives for a name in its place
 * (`cattophp.starting_values`): a value a project fixes, such as its name, or one its deployment already names
 * differently. The Settings page overrides either. Tests give their own.
 */
final class EnvironmentValues
{
    /**
     * @param array<string,string>|null $values null reads the process's environment
     * @param array<string,string|null> $configured values the project's configuration gives, used when not empty
     */
    public function __construct(private readonly ?array $values = null, private readonly array $configured = [])
    {
    }

    public function get(string $name): string
    {
        $configured = trim((string) ($this->configured[$name] ?? ''));
        if ($configured !== '') {
            return $configured;
        }
        if ($this->values !== null) {
            return trim($this->values[$name] ?? '');
        }
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
