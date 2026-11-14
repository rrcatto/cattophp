<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

use CattoPHP\Foundation\Duration;
use InvalidArgumentException;

/**
 * One value the Settings page may change, named as it is in .env. Its starting value comes from .env, else $default;
 * what the Settings page saves overrides both until Reset. A secret is stored encrypted and never written into a page.
 * A length of time may have a shortest and a longest value ($shortest, $longestTime, each in Duration's notation): one
 * outside them is refused on save, and a starting value longer than the longest is read as the longest.
 */
final class Setting
{
    /**
     * @param array<string,string> $choices for a choice: each value and the words a person reads for it
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly SettingType $type = SettingType::Text,
        public readonly string $default = '',
        public readonly string $help = '',
        public readonly bool $optional = false,
        public readonly bool $secret = false,
        public readonly ?int $min = null,
        public readonly ?int $max = null,
        public readonly ?string $shortest = null,
        public readonly array $choices = [],
        public readonly bool $httpsOnly = false,
        public readonly ?int $longest = null,
        public readonly ?string $longestTime = null,
    ) {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('A setting is named as in .env, in capitals: "%s" is not.', $name));
        }
        if ($type === SettingType::Choice && $choices === []) {
            throw new InvalidArgumentException(sprintf('The setting %s is a choice and needs its choices.', $name));
        }
        if ($longestTime !== null) {
            $longestLength = self::seconds($name, 'longest', $longestTime);
            if ($shortest !== null && self::seconds($name, 'shortest', $shortest) > $longestLength) {
                throw new InvalidArgumentException(sprintf('The setting %s has a shortest value longer than its longest.', $name));
            }
            if (Duration::isValid($default) && Duration::parse($default)->lengthInSeconds() > $longestLength) {
                throw new InvalidArgumentException(sprintf('The setting %s has a default longer than its longest value.', $name));
            }
        }
    }

    /** A bound's length in seconds, or why it cannot be one. */
    private static function seconds(string $name, string $which, string $bound): int
    {
        if (!Duration::isValid($bound)) {
            throw new InvalidArgumentException(sprintf('The setting %s has a %s value that is no length of time: "%s".', $name, $which, $bound));
        }

        return Duration::parse($bound)->lengthInSeconds();
    }

    /** The same setting with a project's own default in place of its own. */
    public function withDefault(string $default): self
    {
        return new self($this->name, $this->label, $this->type, $default, $this->help, $this->optional, $this->secret, $this->min, $this->max,
            $this->shortest, $this->choices, $this->httpsOnly, $this->longest, $this->longestTime);
    }

    /** The longest value it takes, in characters. */
    public function longest(): int
    {
        return $this->longest ?? match ($this->type) {
            SettingType::Paragraph => 5000,
            SettingType::MailTransport, SettingType::Address => 2000,
            default => 300,
        };
    }
}
