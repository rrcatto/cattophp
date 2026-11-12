<?php

declare(strict_types=1);

namespace CattoPHP\Protection;

use CattoPHP\Activity\Actor;
use CattoPHP\Foundation\Duration;
use CattoPHP\Settings\Settings;
use InvalidArgumentException;

/**
 * What one address may do before it is flagged, timed out or locked out, and how long the records are kept: the
 * settings `SITE_*`, each with its .env starting value and Settings over it. A rule is written as .env writes it:
 * on or off, how many, within how long, flag or timeout, and for how long (`on,20,10m,timeout,1h`). The settings are
 * declared by ProtectionSettingGroup (a settings provider never depends on Settings).
 */
class ProtectionSettings
{
    /** The rules, in order: what one address does, whether it is counted, how serious it is, and where it starts. */
    public const RULES = [
        'missing' => ['name' => 'Asks for pages that do not exist', 'counted' => true, 'level' => 'high', 'env' => 'SITE_RULE_MISSING', 'start' => 'on,20,10m,timeout,1h'],
        'flood' => ['name' => 'Asks for too many pages', 'counted' => true, 'level' => 'high', 'env' => 'SITE_RULE_FLOOD', 'start' => 'on,300,1m,timeout,10m'],
        'links' => ['name' => 'Asks for sign-in links for different email addresses', 'counted' => true, 'level' => 'high', 'env' => 'SITE_RULE_LINKS', 'start' => 'on,5,15m,timeout,1h'],
        'register' => ['name' => 'Tries to register', 'counted' => true, 'level' => 'medium', 'env' => 'SITE_RULE_REGISTER', 'start' => 'on,3,1h,flag,1h'],
        'tokens' => ['name' => 'Sends forms with a false or expired form token', 'counted' => true, 'level' => 'medium', 'env' => 'SITE_RULE_TOKENS', 'start' => 'on,10,10m,timeout,1h'],
        'trap' => ['name' => 'Fills in a hidden field that people never see', 'counted' => false, 'level' => 'medium', 'env' => 'SITE_RULE_TRAP', 'start' => 'on,1,10m,timeout,1d'],
        'script' => ['name' => 'Sends a form from a script, not a browser', 'counted' => false, 'level' => 'medium', 'env' => 'SITE_RULE_SCRIPT', 'start' => 'on,1,10m,flag,1h'],
        'old' => ['name' => 'Sends a form from a browser years out of date', 'counted' => false, 'level' => 'medium', 'env' => 'SITE_RULE_OLD', 'start' => 'on,1,10m,flag,1h'],
    ];

    /** How long an address marked not a problem is left alone. */
    public const FINE_DAYS = 30;

    /** The other values, each by its .env name with the value it starts at. */
    public const STARTS = [
        'SITE_TIMEOUT_DOUBLES' => 'on',
        'SITE_TIMEOUT_LONGEST' => '1w',
        'SITE_LOCKOUT_AFTER' => '3,1w,30d',
        'SITE_SPARE_ADMINISTRATORS' => 'on',
        'SITE_SPARE_SIGNED_IN' => 'on',
        'SITE_SPARE_SEARCH_ENGINES' => 'on',
        'SITE_NEVER_LOCK_OUT' => '',
        'SITE_LOG_KEPT_FOR' => '120d',
        'SITE_FLAGS_KEPT_FOR' => '1y',
        'SITE_FIGURES_LEAVE_OUT_ADMINISTRATORS' => 'on',
        'SITE_FIREWALL_LINK' => 'off',
    ];

    public const GROUP = 'site_protection';

    public function __construct(private readonly Settings $settings)
    {
    }

    /** @return list<string> every setting's .env name, the rules first */
    public static function names(): array
    {
        return [...array_column(self::RULES, 'env'), ...array_keys(self::STARTS)];
    }

    /**
     * What is in force: what Settings saved, else .env, else the starting values.
     *
     * @return array<string,mixed>
     */
    public function values(): array
    {
        return self::fromText(fn(string $name): string => $this->settings->get($name));
    }

    /** 'database' when Settings saved the values, else 'environment'. */
    public function source(): string
    {
        foreach (self::names() as $name) {
            if ($this->settings->isOverridden($name)) return 'database';
        }

        return 'environment';
    }

    /** @return array<string,mixed> what .env says, which Reset returns to */
    public function environment(): array
    {
        return self::fromText(fn(string $name): string => $this->settings->envValue($name));
    }

    /**
     * The values from each setting's text, as .env writes it.
     *
     * @param callable(string):string $text
     * @return array<string,mixed>
     */
    public static function fromText(callable $text): array
    {
        $value = static function (string $name) use ($text): string {
            $given = trim($text($name));

            return $given !== '' ? $given : self::STARTS[$name];
        };
        $rules = [];
        foreach (self::RULES as $key => $rule) {
            $rules[$key] = self::ruleFrom(trim($text($rule['env'])), $rule['start']);
        }
        $after = array_map('trim', explode(',', $value('SITE_LOCKOUT_AFTER')));

        return [
            'rules' => $rules,
            'doubles' => $value('SITE_TIMEOUT_DOUBLES') !== 'off',
            'longest' => self::lengthOr($value('SITE_TIMEOUT_LONGEST'), '1w'),
            'lockout_count' => ctype_digit($after[0]) ? max(2, (int) $after[0]) : 3,
            'lockout_within' => self::lengthOr($after[1] ?? '', '1w'),
            'lockout_length' => self::lengthOr($after[2] ?? '', '30d'),
            'spare_administrators' => $value('SITE_SPARE_ADMINISTRATORS') !== 'off',
            'spare_people' => $value('SITE_SPARE_SIGNED_IN') !== 'off',
            'spare_search_engines' => $value('SITE_SPARE_SEARCH_ENGINES') !== 'off',
            'never_lock_out' => Addresses::lines($text('SITE_NEVER_LOCK_OUT')),
            'log_kept_for' => self::lengthOr($value('SITE_LOG_KEPT_FOR'), '120d'),
            'flags_kept_for' => self::lengthOr($value('SITE_FLAGS_KEPT_FOR'), '1y'),
            'leave_out_administrators' => $value('SITE_FIGURES_LEAVE_OUT_ADMINISTRATORS') !== 'off',
            'firewall_link' => $value('SITE_FIREWALL_LINK') === 'on',
        ];
    }

    /**
     * The values as each setting's text, as .env writes it.
     *
     * @param array<string,mixed> $values
     * @return array<string,string>
     */
    public static function toText(array $values): array
    {
        $onOff = static fn(mixed $on): string => $on ? 'on' : 'off';
        $texts = [];
        foreach (self::RULES as $key => $rule) {
            $r = $values['rules'][$key];
            $texts[$rule['env']] = implode(',', [$onOff($r['on']), (string) $r['count'], $r['within'], $r['then'], $r['length']]);
        }

        return $texts + [
            'SITE_TIMEOUT_DOUBLES' => $onOff($values['doubles']),
            'SITE_TIMEOUT_LONGEST' => (string) $values['longest'],
            'SITE_LOCKOUT_AFTER' => $values['lockout_count'] . ',' . $values['lockout_within'] . ',' . $values['lockout_length'],
            'SITE_SPARE_ADMINISTRATORS' => $onOff($values['spare_administrators']),
            'SITE_SPARE_SIGNED_IN' => $onOff($values['spare_people']),
            'SITE_SPARE_SEARCH_ENGINES' => $onOff($values['spare_search_engines']),
            'SITE_NEVER_LOCK_OUT' => implode("\n", (array) $values['never_lock_out']),
            'SITE_LOG_KEPT_FOR' => (string) $values['log_kept_for'],
            'SITE_FLAGS_KEPT_FOR' => (string) $values['flags_kept_for'],
            'SITE_FIGURES_LEAVE_OUT_ADMINISTRATORS' => $onOff($values['leave_out_administrators']),
            'SITE_FIREWALL_LINK' => $onOff($values['firewall_link']),
        ];
    }

    /**
     * Saves what a settings page sent, which then overrides .env; refused in words naming the field.
     *
     * @param array<string,mixed> $form
     * @return array<string,mixed> what was saved
     */
    public function save(array $form, ?Actor $actor = null): array
    {
        $rules = [];
        foreach (self::RULES as $key => $rule) {
            $sent = is_array($form['rule'][$key] ?? null) ? $form['rule'][$key] : [];
            $start = self::ruleFrom('', $rule['start']);
            $then = (string) ($sent['then'] ?? 'flag') === 'timeout' ? 'timeout' : 'flag';
            $how = $rule['counted'] ? trim((string) ($sent['count'] ?? '')) : (string) $start['count'];
            if (ctype_digit($how) === false || (int) $how < 1 || (int) $how > 100000) throw new InvalidArgumentException($rule['name'] . ': how many must be a whole number of 1 or more.');
            $rules[$key] = [
                'on' => !empty($sent['on']),
                'count' => (int) $how,
                'within' => $rule['counted'] ? self::length((string) ($sent['within'] ?? ''), $rule['name'] . ': within') : $start['within'],
                'then' => $then,
                // A rule that only flags keeps the length it had, ready for when it times out again.
                'length' => $then === 'timeout' ? self::length((string) ($sent['length'] ?? ''), $rule['name'] . ': for how long') : self::lengthOr((string) ($sent['length'] ?? ''), $start['length']),
            ];
        }
        $count = trim((string) ($form['lockout_count'] ?? ''));
        if (!ctype_digit($count) || (int) $count < 2) throw new InvalidArgumentException('Timed out how many times must be a whole number of 2 or more.');
        $values = [
            'rules' => $rules,
            'doubles' => !empty($form['doubles']),
            'longest' => self::length((string) ($form['longest'] ?? ''), 'Longest time-out'),
            'lockout_count' => (int) $count,
            'lockout_within' => self::length((string) ($form['lockout_within'] ?? ''), 'Timed out within'),
            'lockout_length' => self::length((string) ($form['lockout_length'] ?? ''), 'Locks it out for'),
            'spare_administrators' => !empty($form['spare_administrators']),
            'spare_people' => !empty($form['spare_people']),
            'spare_search_engines' => !empty($form['spare_search_engines']),
            'never_lock_out' => self::checkedAddresses((string) ($form['never_lock_out'] ?? '')),
            'log_kept_for' => self::length((string) ($form['log_kept_for'] ?? ''), 'Keep the Site Log for'),
            'flags_kept_for' => self::length((string) ($form['flags_kept_for'] ?? ''), 'Keep flagged activity for'),
            'leave_out_administrators' => !empty($form['leave_out_administrators']),
            'firewall_link' => !empty($form['firewall_link']),
        ];
        foreach (self::toText($values) as $name => $setting) $this->settings->set($name, $setting, $actor);

        return $values;
    }

    /** Removes what Settings saved: the values follow .env again. */
    public function reset(?Actor $actor = null): void
    {
        foreach (self::names() as $name) $this->settings->reset($name, $actor);
    }

    /** Whether an address or range lies on the Never lock out list. */
    public function isNeverLockedOut(string $address): bool
    {
        return Addresses::listHolds($this->settings->get('SITE_NEVER_LOCK_OUT'), $address);
    }

    /** @return array{on:bool,count:int,within:string,then:string,length:string} */
    private static function ruleFrom(string $line, string $start): array
    {
        $parts = array_map('trim', explode(',', $line !== '' ? $line : $start));
        $starting = array_map('trim', explode(',', $start));
        if (count($parts) !== 5 || !ctype_digit($parts[1]) || !Duration::isValid($parts[2]) || !Duration::isValid($parts[4])) $parts = $starting;

        return ['on' => $parts[0] !== 'off', 'count' => max(1, (int) $parts[1]), 'within' => $parts[2], 'then' => $parts[3] === 'timeout' ? 'timeout' : 'flag', 'length' => $parts[4]];
    }

    private static function lengthOr(string $text, string $default): string
    {
        $text = trim($text);

        return $text !== '' && Duration::isValid($text) ? $text : $default;
    }

    private static function length(string $text, string $name): string
    {
        $text = trim($text);
        if ($text === '' || !Duration::isValid($text)) throw new InvalidArgumentException($name . ': ' . Duration::HOW);

        return $text;
    }

    /** @return list<string> */
    private static function checkedAddresses(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $text) ?: []), static fn(string $line): bool => $line !== ''));
        foreach ($lines as $line) {
            if (!Addresses::isAddressOrRange($line)) throw new InvalidArgumentException('Never lock out: ' . $line . ' is not an address or a range.');
        }

        return array_values(array_unique($lines));
    }
}
