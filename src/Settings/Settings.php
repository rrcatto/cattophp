<?php

declare(strict_types=1);

namespace CattoPHP\Settings;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use CattoPHP\Foundation\Duration;
use CattoPHP\Installation\Installation;
use CattoPHP\Security\SecretCipher;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Throwable;

/**
 * Every setting's value: what the Settings page saved, else what .env gives, else the setting's own default. .env is
 * never written. Saving a value equal to what .env gives (or, with nothing in .env, the default) removes the override,
 * so a later change to .env applies again; Reset removes it outright. A setting that may be blank can be saved blank,
 * which overrides .env too; one that cannot be blank takes its default when left blank. A secret is stored encrypted and decrypted only
 * when the code asks for it; a secret left blank on the page keeps the one there is. Every change is in the activity
 * log with its old and new values, and a secret's change without them.
 *
 * A setting the installation holds fixed (FixedSettings: its configuration, or a project's FixedSettingsProvider) keeps
 * the value its configuration gives, whatever was saved for it before, and is refused by every way of changing one: save,
 * set, clear and reset. A length of time with a longest value is read as that longest when .env (or anything saved
 * before the bound was set) gives one longer.
 */
final class Settings
{
    public const FROM_SETTINGS = 'settings';
    public const FROM_ENV = 'env';
    public const FROM_DEFAULT = 'default';

    private const TRUE = ['1', 'true', 'on', 'yes'];

    /** How long the overrides read are trusted before they are read again, in seconds. */
    public const FRESH_FOR = 5;

    /** @var array<string,StoredSetting>|null the overrides, read at $readAt */
    private ?array $overrides = null;

    private int $readAt = 0;

    public function __construct(
        private readonly SettingsCatalogue $catalogue,
        private readonly SettingsStore $store,
        private readonly SecretCipher $cipher,
        private readonly EnvironmentValues $environment,
        private readonly ActivityLog $activity,
        private readonly ClockInterface $clock,
        private readonly ?FixedSettings $fixed = null,
    ) {
    }

    public function catalogue(): SettingsCatalogue
    {
        return $this->catalogue;
    }

    /** Whether the Settings page can save anything here, or the installation keeps its settings in .env alone. */
    public function canSave(): bool
    {
        return $this->store->isWritable();
    }

    /**
     * Why what the Settings page saved could not be read (a damaged or unreadable file), in plain words, or null. While
     * there is one, every setting has its starting value.
     */
    public function readProblem(): ?string
    {
        $this->overrides();

        return $this->store instanceof SettingsStoreProblem ? $this->store->readProblem() : null;
    }

    /** Whether the installation holds the setting fixed, so it keeps its configuration's value and is never changed here. */
    public function isFixed(string $name): bool
    {
        $this->catalogue->setting($name);

        return $this->fixed !== null && $this->fixed->isFixed($this->catalogue, $name);
    }

    /** Whether the setting is fixed and left off the Settings page altogether. */
    public function isHidden(string $name): bool
    {
        $this->catalogue->setting($name);

        return $this->fixed !== null && $this->fixed->isHidden($this->catalogue, $name);
    }

    /** Whether every setting of the group is fixed. */
    public function groupIsFixed(string $groupKey): bool
    {
        foreach (array_keys($this->catalogue->group($groupKey)->settings) as $name) {
            if (!$this->isFixed($name)) {
                return false;
            }
        }

        return true;
    }

    /** What a refusal of a fixed setting says. */
    public static function fixedWords(string $label): string
    {
        return sprintf('%s is fixed in this installation\'s configuration and cannot be changed here.', $label);
    }

    /** The value in force. A stored secret that can no longer be decrypted is an error, never a quiet fallback. */
    public function get(string $name): string
    {
        $setting = $this->catalogue->setting($name);
        $stored = $this->overrides()[$name] ?? null;
        if ($stored !== null) {
            return $stored->secret ? $this->cipher->decrypt($stored->value) : self::bounded($setting, $stored->value);
        }

        return $this->inherited($setting);
    }

    public function int(string $name): int
    {
        $value = $this->get($name);
        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        $default = $this->catalogue->setting($name)->default;

        return preg_match('/^-?\d+$/', $default) === 1 ? (int) $default : 0;
    }

    public function bool(string $name): bool
    {
        return in_array(strtolower($this->get($name)), self::TRUE, true);
    }

    /** A length of time; the setting's default when what is there cannot be read as one. */
    public function duration(string $name): Duration
    {
        $value = $this->get($name);
        if (Duration::isValid($value)) {
            return Duration::parse($value);
        }

        return Duration::parse($this->catalogue->setting($name)->default);
    }

    /** What .env gives for a setting, before the Settings page. */
    public function envValue(string $name): string
    {
        return $this->environment->get($this->catalogue->setting($name)->name);
    }

    public function isOverridden(string $name): bool
    {
        $this->catalogue->setting($name);

        return isset($this->overrides()[$name]);
    }

    /** Whether .env's value (or a value saved before) is longer than the setting's longest, so the longest applies. */
    public function isCutToLongest(string $name): bool
    {
        $setting = $this->catalogue->setting($name);
        if ($setting->longestTime === null || $setting->secret) {
            return false;
        }
        $stored = $this->overrides()[$name] ?? null;
        $given = $stored !== null ? $stored->value : $this->environment->get($name);

        return $given !== '' && self::bounded($setting, $given) !== $given;
    }

    /** Where the value in force comes from: settings, env or default. */
    public function source(string $name): string
    {
        return match (true) {
            $this->isOverridden($name) => self::FROM_SETTINGS,
            $this->envValue($name) !== '' => self::FROM_ENV,
            default => self::FROM_DEFAULT,
        };
    }

    /** When an override was saved and by whom, or null. */
    public function savedBy(string $name): ?StoredSetting
    {
        $this->catalogue->setting($name);

        return $this->overrides()[$name] ?? null;
    }

    /** Why a stored secret cannot be used, in plain words, or null. */
    public function secretProblem(string $name): ?string
    {
        $stored = $this->overrides()[$name] ?? null;
        if ($stored === null || !$stored->secret) {
            return null;
        }
        try {
            $this->cipher->decrypt($stored->value);

            return null;
        } catch (RuntimeException $problem) {
            return $problem->getMessage();
        }
    }

    /**
     * Checks every setting of a group as typed and saves them together; nothing is saved if any is refused.
     *
     * @param array<string,mixed> $input what the form sent, by setting name
     * @return list<string> the names whose value changed
     * @throws SettingsRefused
     */
    public function save(string $groupKey, array $input, ?Actor $actor = null): array
    {
        if (!$this->store->isWritable()) {
            throw new SettingsRefused(['' => 'This installation keeps its settings in .env: change them there.']);
        }
        $group = $this->catalogue->group($groupKey);
        if ($this->groupIsFixed($groupKey)) {
            throw new SettingsRefused(['' => self::fixedWords($group->label)]);
        }
        $values = [];
        $errors = [];
        foreach ($group->settings as $name => $setting) {
            if ($this->isFixed($name)) {
                // Never changed here: one sent all the same, other than as it is, is refused.
                if (array_key_exists($name, $input) && !$this->sentAsItIs($setting, $input[$name])) {
                    $errors[$name] = self::fixedWords($setting->label);
                }
                continue;
            }
            try {
                $values[$name] = $this->checked($setting, $input[$name] ?? null);
            } catch (InvalidArgumentException $refused) {
                $errors[$name] = $refused->getMessage();
            }
        }
        if ($errors !== []) {
            throw new SettingsRefused($errors);
        }
        $changed = [];
        foreach ($values as $name => $value) {
            // A secret left blank (null) keeps the one there is.
            if ($value !== null && $this->apply($group->settings[$name], $value, $actor)) {
                $changed[] = $name;
            }
        }

        return $changed;
    }

    /**
     * Checks one setting as typed and saves it on its own, for a page that changes one value of a group. True when its
     * value changed.
     *
     * @throws SettingsRefused
     */
    public function set(string $name, mixed $typed, ?Actor $actor = null): bool
    {
        if (!$this->store->isWritable()) {
            throw new SettingsRefused(['' => 'This installation keeps its settings in .env: change them there.']);
        }
        $setting = $this->catalogue->setting($name);
        $this->refuseFixed($setting);
        try {
            $value = $this->checked($setting, $typed);
        } catch (InvalidArgumentException $refused) {
            throw new SettingsRefused([$name => $refused->getMessage()]);
        }

        // A secret left blank keeps the one there is.
        return $value !== null && $this->apply($setting, $value, $actor);
    }

    /** Saves a checked value: an override, or none when it is what .env or the default gives. True when it changed. */
    private function apply(Setting $setting, string $value, ?Actor $actor): bool
    {
        $name = $setting->name;
        $before = $this->safely($name);
        $isOverridden = isset($this->overrides()[$name]);
        if ($value === $this->inherited($setting)) {
            if ($isOverridden) {
                $this->store->remove($name);
                $this->forget();
            }
        } elseif (!$isOverridden || $before !== $value) {
            $this->store->save($name, $setting->secret ? $this->cipher->encrypt($value) : $value, $setting->secret, $this->clock->now(), $actor !== null ? $actor->id : $this->activity->signedIn()?->id);
            $this->forget();
        }
        if ($before === $value) {
            return false;
        }
        $this->activity->record('settings.changed', $setting->secret ? ['setting' => $name, 'secret_changed' => true] : ['setting' => $name, 'old' => $before, 'new' => $value], 'setting', $name, $actor);

        return true;
    }

    /** Removes one override, so .env, else the default, applies again. A fixed setting is refused. */
    public function reset(string $name, ?Actor $actor = null): void
    {
        $setting = $this->catalogue->setting($name);
        $this->refuseFixed($setting);
        if (!isset($this->overrides()[$name])) {
            return;
        }
        $before = $this->safely($name);
        $this->store->remove($name);
        $this->forget();
        $this->activity->record('settings.reset', $setting->secret ? ['setting' => $name] : ['setting' => $name, 'old' => $before, 'new' => $this->inherited($setting)], 'setting', $name, $actor);
    }

    /**
     * Saves the setting as empty on purpose, whatever .env says: a connection dropped stays dropped, even where .env holds
     * keys, until new ones are saved or reset() lets .env apply again (Settings override every .env value). Where .env
     * and the default give nothing, the override is simply removed.
     */
    public function clear(string $name, ?Actor $actor = null): void
    {
        if (!$this->store->isWritable()) {
            throw new SettingsRefused(['' => 'This installation keeps its settings in .env: change them there.']);
        }
        $setting = $this->catalogue->setting($name);
        $this->refuseFixed($setting);
        $before = $this->safely($name);
        if ($this->inherited($setting) === '') {
            if (isset($this->overrides()[$name])) {
                $this->store->remove($name);
            }
        } else {
            $this->store->save($name, $setting->secret ? $this->cipher->encrypt('') : '', $setting->secret, $this->clock->now(), $actor !== null ? $actor->id : null);
        }
        $this->forget();
        if ($before !== '') {
            $this->activity->record('settings.changed', $setting->secret ? ['setting' => $name, 'secret_changed' => true] : ['setting' => $name, 'old' => $before, 'new' => ''], 'setting', $name, $actor);
        }
    }

    /** Removes every override of a group but its fixed settings'; a group fixed whole is refused. */
    public function resetGroup(string $groupKey, ?Actor $actor = null): void
    {
        $group = $this->catalogue->group($groupKey);
        if ($this->groupIsFixed($groupKey)) {
            throw new SettingsRefused(['' => self::fixedWords($group->label)]);
        }
        foreach (array_keys($group->settings) as $name) {
            if (!$this->isFixed($name)) {
                $this->reset($name, $actor);
            }
        }
    }

    /** @throws SettingsRefused when the setting is fixed */
    private function refuseFixed(Setting $setting): void
    {
        if ($this->isFixed($setting->name)) {
            throw new SettingsRefused([$setting->name => self::fixedWords($setting->label)]);
        }
    }

    /** Whether what a form sent for a fixed setting is the value in force (or a secret left blank), so changes nothing. */
    private function sentAsItIs(Setting $setting, mixed $typed): bool
    {
        if ($setting->secret && ($typed === null || (is_string($typed) && trim($typed) === ''))) {
            return true;
        }
        try {
            $value = $this->checked($setting, $typed);

            return $value === null || $value === $this->get($setting->name);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A value as typed, checked and put in its one form; null for a secret left blank (keep the one there is).
     *
     * @throws InvalidArgumentException with why, in plain words
     */
    public function checked(Setting $setting, mixed $typed): ?string
    {
        if ($setting->type === SettingType::Switch) {
            return is_scalar($typed) && in_array(strtolower(trim((string) $typed)), self::TRUE, true) ? 'true' : 'false';
        }
        if ($typed !== null && !is_scalar($typed)) {
            throw new InvalidArgumentException(sprintf('%s: type one value.', $setting->label));
        }
        $value = $typed === null ? '' : (string) $typed;
        $value = $setting->type === SettingType::Paragraph ? trim(str_replace("\r\n", "\n", $value)) : trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            if ($setting->secret && $this->secretIsSet($setting->name)) {
                return null;
            }
            if ($setting->optional) {
                // Blank is a value of its own (no addresses listed, no organisation), overriding .env until Reset.
                return '';
            }
            if ($setting->default !== '') {
                // A value that cannot be blank (a number, a length of time) takes the default: .env's, else its own.
                return $this->inherited($setting);
            }
            throw new InvalidArgumentException(sprintf('%s cannot be empty.', $setting->label));
        }
        if (mb_strlen($value) > $setting->longest()) {
            throw new InvalidArgumentException(sprintf('%s can be at most %d characters.', $setting->label, $setting->longest()));
        }

        return match ($setting->type) {
            SettingType::Text, SettingType::Paragraph => $value,
            SettingType::Email => filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value
                : throw new InvalidArgumentException(sprintf('%s must be an email address, such as someone@example.com.', $setting->label)),
            SettingType::Address => self::address($setting, $value),
            SettingType::Number => self::number($setting, $value),
            SettingType::Duration => self::lengthOfTime($setting, $value),
            SettingType::Choice => self::choiceKey($setting, $value) ?? ($this->isInForce($setting, $value) ? $value
                : throw new InvalidArgumentException(sprintf('%s: choose one of %s.', $setting->label, implode(', ', $setting->choices)))),
            SettingType::TimeZone => Installation::timeZoneName($value) ?? ($this->isInForce($setting, $value) ? $value
                : throw new InvalidArgumentException(sprintf('%s must be a time zone by its name, such as Africa/Johannesburg or Europe/London.', $setting->label))),
            SettingType::MailTransport => self::mailTransport($setting, $value),
        };
    }

    /**
     * What applies without an override: .env, else the setting's own default. A switch is true or false; a choice or a
     * time zone written in another case is the one it names (.env's AUTH_TWO_STEP=everyone is EVERYONE, APP_SITE_MODE=LIVE
     * is live, APP_TIMEZONE=africa/johannesburg is Africa/Johannesburg), as those who read them have always read them.
     */
    private function inherited(Setting $setting): string
    {
        $env = $this->environment->get($setting->name);
        if ($env === '') {
            return $setting->default;
        }

        return match ($setting->type) {
            SettingType::Switch => in_array(strtolower($env), self::TRUE, true) ? 'true' : 'false',
            SettingType::Choice => self::choiceKey($setting, $env) ?? $env,
            SettingType::TimeZone => Installation::timeZoneName($env) ?? $env,
            SettingType::Duration => self::bounded($setting, $env),
            default => $env,
        };
    }

    /** A length of time longer than the setting's longest, read as the longest; anything else as it is. */
    private static function bounded(Setting $setting, string $value): string
    {
        if ($setting->type !== SettingType::Duration || $setting->longestTime === null || !Duration::isValid($value)) {
            return $value;
        }
        $longest = Duration::parse($setting->longestTime);

        return Duration::parse($value)->lengthInSeconds() > $longest->lengthInSeconds() ? $longest->text : $value;
    }

    /** The choice a value names, whatever its case and spaces around it, as the choice's own key; null for none. */
    public static function choiceKey(Setting $setting, string $value): ?string
    {
        $value = trim($value);
        foreach (array_keys($setting->choices) as $key) {
            if (strcasecmp((string) $key, $value) === 0) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * Whether a value is exactly the one in force now: a choice or a time zone that is in no option (.env's spelling of
     * something unknown, say) is kept as it stands when the form sends it back unchanged, and is refused only once someone
     * picks it on purpose. Saving a group never quietly puts another value in its place.
     */
    private function isInForce(Setting $setting, string $value): bool
    {
        try {
            return $value === $this->get($setting->name);
        } catch (Throwable) {
            return false;
        }
    }

    /** Whether a secret is set, saved or from .env; one saved that can no longer be read counts as set, and is kept. */
    private function secretIsSet(string $name): bool
    {
        try {
            return $this->get($name) !== '';
        } catch (RuntimeException) {
            return true;
        }
    }

    private static function address(Setting $setting, string $value): string
    {
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        if (filter_var($value, FILTER_VALIDATE_URL) === false || !in_array($scheme, $setting->httpsOnly ? ['https'] : ['http', 'https'], true)) {
            throw new InvalidArgumentException(sprintf('%s must be a web address starting with %s, such as https://example.com.', $setting->label, $setting->httpsOnly ? 'https://' : 'https:// or http://'));
        }

        return $value;
    }

    private static function number(Setting $setting, string $value): string
    {
        if (preg_match('/^-?\d{1,15}$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%s must be a whole number.', $setting->label));
        }
        $number = (int) $value;
        if (($setting->min !== null && $number < $setting->min) || ($setting->max !== null && $number > $setting->max)) {
            throw new InvalidArgumentException(match (true) {
                $setting->min !== null && $setting->max !== null => sprintf('%s must be from %d to %d.', $setting->label, $setting->min, $setting->max),
                $setting->min !== null => sprintf('%s must be %d or more.', $setting->label, $setting->min),
                default => sprintf('%s must be %d or less.', $setting->label, (int) $setting->max),
            });
        }

        return (string) $number;
    }

    private static function lengthOfTime(Setting $setting, string $value): string
    {
        try {
            $length = Duration::parse($value);
        } catch (InvalidArgumentException $refused) {
            throw new InvalidArgumentException(sprintf('%s: %s', $setting->label, $refused->getMessage()));
        }
        if ($setting->shortest !== null) {
            $shortest = Duration::parse($setting->shortest);
            if ($length->lengthInSeconds() < $shortest->lengthInSeconds()) {
                throw new InvalidArgumentException(sprintf('%s must be at least %s.', $setting->label, $shortest->words()));
            }
        }
        if ($setting->longestTime !== null) {
            $longest = Duration::parse($setting->longestTime);
            if ($length->lengthInSeconds() > $longest->lengthInSeconds()) {
                throw new InvalidArgumentException(sprintf('%s must be at most %s.', $setting->label, $longest->words()));
            }
        }

        return $length->text;
    }

    private static function mailTransport(Setting $setting, string $value): string
    {
        $parts = parse_url($value);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if (!is_array($parts) || !in_array($scheme, ['smtp', 'smtps', 'sendmail', 'null'], true) || ($scheme !== 'null' && $scheme !== 'sendmail' && ($parts['host'] ?? '') === '')) {
            throw new InvalidArgumentException(sprintf('%s must be a mail server\'s address, such as smtp://mail.example.com:587 or smtps://user:password@mail.example.com.', $setting->label));
        }

        return $value;
    }

    /** The value in force, or a note when a secret cannot be read: for the activity log. */
    private function safely(string $name): string
    {
        try {
            return $this->get($name);
        } catch (Throwable) {
            return '(could not be read)';
        }
    }

    /**
     * The overrides, read again once they are FRESH_FOR seconds old, so a process that runs for hours (a scheduler, a
     * worker) sees what the Settings page saved since it started.
     *
     * @return array<string,StoredSetting>
     */
    private function overrides(): array
    {
        $now = $this->clock->now()->getTimestamp();
        if ($this->overrides === null || $now - $this->readAt >= self::FRESH_FOR) {
            // What was saved for a setting now fixed is passed over: the configuration's value applies.
            $this->overrides = array_filter($this->store->all(), fn (string $name): bool => $this->catalogue->has($name) && !$this->isFixed($name), ARRAY_FILTER_USE_KEY);
            $this->readAt = $now;
        }

        return $this->overrides;
    }

    /** Forgets what was read, so the next value is read afresh: for a long-running process between its jobs. */
    public function refresh(): void
    {
        $this->forget();
    }

    private function forget(): void
    {
        $this->overrides = null;
    }
}
