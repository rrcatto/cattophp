<?php

declare(strict_types=1);

namespace CattoPHP\Admin;

use CattoPHP\Activity\Actor;
use CattoPHP\Foundation\Duration;
use CattoPHP\Installation\Installation;
use CattoPHP\Settings\Fact;
use CattoPHP\Settings\FactsProvider;
use CattoPHP\Settings\Setting;
use CattoPHP\Settings\SettingGroup;
use CattoPHP\Settings\Settings;
use CattoPHP\Settings\SettingType;
use CattoPHP\Settings\SettingsRefused;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * The Settings page's groups as the page draws them: every setting of every group CattoPHP and the project provide, its
 * field, the value in force (never a secret's: a saved one shows as asterisks), where that value comes from in words
 * (saved here, when and by whom; .env; or its default) with what Reset goes back to, and for a refused form what was typed
 * and why it was refused. A setting the installation holds fixed is shown with its value, saying so, and nothing to change
 * it with; one it hides, and a group whose every setting it hides, is not drawn at all. The project's facts
 * (Settings\FactsProvider) follow the groups, shown and never changed.
 */
final class SettingsPage
{
    /** What a secret's field shows when one is set: asterisks, never blank and never the browser's dots. */
    public const SECRET_SHOWN = '************';

    /** @param iterable<FactsProvider> $facts */
    public function __construct(private readonly Settings $settings, private readonly Installation $installation, private readonly AccountNames $names, private readonly iterable $facts = [])
    {
    }

    /**
     * @param callable(string): array<string,mixed> $typed what a refused form sent to a group's address, by its key
     * @param array<string,string> $errors why each setting was refused, by its name
     * @return list<array<string,mixed>>
     */
    public function groups(callable $typed, array $errors): array
    {
        $groups = [];
        foreach ($this->settings->catalogue()->groups() as $group) {
            if ($this->shows($group->key)) {
                $groups[] = $this->drawn($group, $typed($group->key), $errors);
            }
        }

        return $groups;
    }

    /** Whether the page shows the group: false when the installation hides every setting in it. */
    public function shows(string $group): bool
    {
        foreach (array_keys($this->settings->catalogue()->group($group)->settings) as $name) {
            if (!$this->settings->isHidden($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The project's facts, each group with its heading, its explanation and its lines, in the order of its providers.
     *
     * @return list<array{key:string,heading:string,explanation:string,summary:string,items:list<array{label:string,value:string}>}>
     */
    public function facts(): array
    {
        $groups = [];
        foreach ($this->facts as $provider) {
            foreach ($provider->settingsFacts() as $group) {
                $items = array_map(static fn (Fact $fact): array => ['label' => $fact->label, 'value' => $fact->value], $group->facts);
                $groups[] = [
                    'key' => $group->key,
                    'heading' => $group->heading,
                    'explanation' => $group->explanation,
                    'summary' => 'Shown only: ' . (count($items) === 1 ? '1 fact' : count($items) . ' facts') . ' this page cannot change.',
                    'items' => $items,
                ];
            }
        }

        return $groups;
    }

    /**
     * One group as a page draws it, for a module's own page that saves its group as this page does (the Campaign
     * Marketing Manager's Settings): the same values, words and Resets.
     *
     * @param array<string,mixed> $sent what a refused form sent, by setting name
     * @param array<string,string> $errors why each setting was refused, by its name
     * @return array<string,mixed>
     */
    public function group(string $key, array $sent, array $errors): array
    {
        return $this->drawn($this->settings->catalogue()->group($key), $sent, $errors);
    }

    /**
     * Saves a group as its form sent it, a saved secret's asterisks left as they are keeping it, and says what was done
     * in words ("Mail: 1 setting saved.").
     *
     * @param array<string,mixed> $input
     * @throws SettingsRefused
     */
    public function save(string $group, array $input, ?Actor $actor): string
    {
        $label = $this->settings->catalogue()->group($group)->label;
        foreach ($this->secretNames($group) as $name) {
            // The asterisks a saved secret shows are not a new secret: left as they are, it is kept.
            if (($input[$name] ?? null) === self::SECRET_SHOWN) {
                $input[$name] = '';
            }
        }
        $changed = $this->settings->save($group, $input, $actor);

        return match (count($changed)) {
            0 => $label . ': nothing had changed, so nothing was saved.',
            1 => $label . ': 1 setting saved.',
            default => $label . ': ' . count($changed) . ' settings saved.',
        };
    }

    /** Puts every setting of a group back to .env's value or its default, and says so. */
    public function resetGroup(string $group, ?Actor $actor): string
    {
        $label = $this->settings->catalogue()->group($group)->label;
        $this->settings->resetGroup($group, $actor);

        return $label . ': every setting is back to .env\'s value or its default.';
    }

    /** Whether a setting of this name is in the group and shown on the page. */
    public function inGroup(string $group, string $name): bool
    {
        $catalogue = $this->settings->catalogue();

        return $catalogue->has($name) && $catalogue->groupOf($name) === $group && !$this->settings->isHidden($name);
    }

    /** Puts one setting of a group back to .env's value or its default, and says so. */
    public function reset(string $group, string $name, ?Actor $actor): string
    {
        $catalogue = $this->settings->catalogue();
        if (!$this->inGroup($group, $name)) {
            throw new InvalidArgumentException('There is no such setting.');
        }
        $this->settings->reset($name, $actor);

        return $catalogue->group($group)->label . ': ' . $catalogue->setting($name)->label . ' is back to ' . ($this->settings->envValue($name) !== '' ? '.env\'s value.' : 'its default.');
    }

    /**
     * @param array<string,mixed> $sent
     * @param array<string,string> $errors
     * @return array<string,mixed>
     */
    private function drawn(SettingGroup $group, array $sent, array $errors): array
    {
        $settings = [];
        foreach ($group->settings as $setting) {
            if (!$this->settings->isHidden($setting->name)) {
                $settings[] = $this->setting($setting, $sent, $errors[$setting->name] ?? '');
            }
        }
        $saved = count(array_filter($settings, static fn (array $setting): bool => $setting['overridden'] === true));
        $fixed = count(array_filter($settings, static fn (array $setting): bool => $setting['fixed'] === true));

        return [
            'key' => $group->key,
            'label' => $group->label,
            'description' => $group->description,
            'summary' => $fixed > 0 && $fixed === count($settings) ? 'Fixed in this installation\'s configuration.' : self::summary($saved, count($settings)),
            'saved' => $saved,
            // Every setting shown is fixed: nothing to save or reset.
            'fixed' => $fixed > 0 && $fixed === count($settings),
            'refused' => array_intersect_key($errors, $group->settings) !== [],
            'settings' => $settings,
        ];
    }

    /** @return list<string> the names of a group's secret settings, which a refused form never keeps */
    public function secretNames(string $group): array
    {
        $names = [];
        foreach ($this->settings->catalogue()->group($group)->settings as $setting) {
            if ($setting->secret) {
                $names[] = $setting->name;
            }
        }

        return $names;
    }

    /**
     * @param array<string,mixed> $sent
     * @return array<string,mixed>
     */
    private function setting(Setting $setting, array $sent, string $error): array
    {
        $name = $setting->name;
        $problem = $setting->secret ? $this->settings->secretProblem($name) : null;
        $value = '';
        $secretSet = false;
        if ($setting->secret) {
            try {
                $secretSet = $this->settings->get($name) !== '';
            } catch (Throwable) {
                $secretSet = true;
            }
        } else {
            $value = array_key_exists($name, $sent) && is_scalar($sent[$name]) ? (string) $sent[$name] : $this->settings->get($name);
        }
        $fixed = $this->settings->isFixed($name);
        $help = $fixed ? $setting->help : trim($setting->help . ($setting->type === SettingType::Duration && !str_contains($setting->help, Duration::HOW) ? ' ' . Duration::HOW : '') . self::bounds($setting));
        $source = ($fixed ? 'Fixed in this installation\'s configuration: it cannot be changed here. ' : '') . $this->source($setting, $problem);
        $overridden = $this->settings->isOverridden($name);

        return [
            'name' => $name,
            'id' => 'setting-' . strtolower(str_replace('_', '-', $name)),
            'label' => $setting->label,
            'help' => trim($help . ' ' . ($setting->secret ? $this->secretWords($setting, $secretSet) : '') . ' ' . $source),
            'control' => self::control($setting),
            'value' => $value,
            'checked' => $setting->type === SettingType::Switch && in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true),
            'choices' => $this->choices($setting, $value),
            'required' => !$setting->optional && $setting->default === '' && !$setting->secret,
            'maxlength' => $setting->longest(),
            'min' => $setting->min,
            'max' => $setting->max,
            'secret' => $setting->secret,
            'secret_set' => $secretSet,
            'shown' => $setting->secret && $secretSet ? self::SECRET_SHOWN : '',
            'overridden' => $overridden,
            'fixed' => $fixed,
            'reset_label' => $this->settings->envValue($name) !== '' ? 'Reset to .env' : 'Reset to its default',
            'reset_confirm' => 'Put ' . $setting->label . ' back to ' . ($this->settings->envValue($name) !== '' ? '.env\'s value' : 'its default') . '? What is saved here goes.',
            'error' => $error,
        ];
    }

    /** What a secret's field says: saved and never shown, with the asterisks to leave as they are; or that none is set. */
    private function secretWords(Setting $setting, bool $set): string
    {
        if (!$set) {
            return 'None is set. Type it to save it here, encrypted: it is never shown again.';
        }
        if ($this->settings->isOverridden($setting->name)) {
            return 'Saved, encrypted and never shown. Leave the asterisks to keep it, or type a new one.';
        }

        return 'Given by .env and never shown. Leave the asterisks to keep it, or type a new one to save here, encrypted.';
    }

    /** Where the value in force comes from, in words. */
    private function source(Setting $setting, ?string $problem): string
    {
        $name = $setting->name;
        if ($problem !== null) {
            return 'Saved here, but it cannot be read: ' . $problem . ' Save it again, or reset it to .env.';
        }
        if ($this->settings->isCutToLongest($name)) {
            $longest = Duration::parse((string) $setting->longestTime)->words();

            return $this->settings->isOverridden($name)
                ? 'Saved here as longer than the longest it may be, so ' . $longest . ' applies. Reset goes back to .env\'s value or its default.'
                : '.env (' . $name . ') gives ' . self::shown($this->settings->envValue($name)) . ', longer than the longest it may be, so ' . $longest . ' applies.';
        }

        return match ($this->settings->source($name)) {
            Settings::FROM_SETTINGS => $this->savedHere($setting),
            Settings::FROM_ENV => 'From .env (' . $name . ').',
            default => $setting->default === '' ? 'Not set: .env gives nothing for ' . $name . '.' : 'Its default: .env gives nothing for ' . $name . '.',
        };
    }

    private function savedHere(Setting $setting): string
    {
        $saved = $this->settings->savedBy($setting->name);
        $words = 'Saved here';
        if ($saved !== null) {
            try {
                $words .= ' on ' . $this->installation->localTime(new DateTimeImmutable($saved->updatedAt))->format('j F Y, H:i');
            } catch (Throwable) {
            }
            if ($saved->updatedBy !== null && $saved->updatedBy !== '') {
                $words .= ' by ' . $this->names->name($saved->updatedBy);
            }
        }
        if ($setting->secret) {
            return $words . ', overriding .env. Reset goes back to ' . ($this->settings->envValue($setting->name) !== '' ? '.env\'s' : 'none') . '.';
        }
        $env = $this->settings->envValue($setting->name);

        return $words . ', overriding .env. Reset goes back to ' . ($env !== '' ? '.env\'s ' . self::shown($env) : ($setting->default !== '' ? 'its default, ' . self::shown($setting->default) : 'nothing')) . '.';
    }

    /** A length of time's bounds in words, where it has a longest: " It may be from 5 minutes to 365 days.", or none. */
    private static function bounds(Setting $setting): string
    {
        if ($setting->type !== SettingType::Duration || $setting->longestTime === null) {
            return '';
        }
        $longest = Duration::parse($setting->longestTime)->words();

        return $setting->shortest !== null ? ' It may be from ' . Duration::parse($setting->shortest)->words() . ' to ' . $longest . '.' : ' It may be at most ' . $longest . '.';
    }

    /** The control a setting is typed or chosen in. */
    private static function control(Setting $setting): string
    {
        if ($setting->secret) {
            return 'secret';
        }

        return match ($setting->type) {
            SettingType::Paragraph => 'paragraph',
            SettingType::Email => 'email',
            SettingType::Address => 'url',
            SettingType::Number => 'number',
            SettingType::Switch => 'switch',
            SettingType::Choice, SettingType::TimeZone => 'choice',
            default => 'text',
        };
    }

    /**
     * A choice's options, the one in force selected whatever its case (.env's AUTH_TWO_STEP=everyone selects "Required of
     * everyone"). A value in force that is none of them (a time zone PHP still knows by an older name, such as
     * Asia/Calcutta, or .env's spelling of something unknown) comes first, selected and said to be so, so saving the group
     * for another reason keeps it rather than quietly putting the first option in its place.
     *
     * @return list<array{value:string,label:string,selected:bool}>
     */
    private function choices(Setting $setting, string $value): array
    {
        $choices = match ($setting->type) {
            SettingType::Choice => $setting->choices,
            SettingType::TimeZone => array_combine(timezone_identifiers_list(), timezone_identifiers_list()),
            default => [],
        };
        if ($choices === []) {
            return [];
        }
        $list = [];
        $found = false;
        foreach ($choices as $key => $label) {
            $selected = !$found && strcasecmp((string) $key, trim($value)) === 0;
            $found = $found || $selected;
            $list[] = ['value' => (string) $key, 'label' => (string) $label, 'selected' => $selected];
        }
        if (!$found && trim($value) !== '') {
            $from = $this->settings->isOverridden($setting->name) ? 'as saved here' : 'as .env gives it';
            $known = $setting->type === SettingType::TimeZone && Installation::timeZoneName($value) !== null;
            array_unshift($list, ['value' => $value, 'label' => $value . ' (' . $from . ($known ? '' : ', not one of these') . ')', 'selected' => true]);
        }

        return $list;
    }

    private static function summary(int $saved, int $all): string
    {
        return match (true) {
            $saved === 0 => 'All from .env or their defaults.',
            $saved === $all => ($all === 1 ? 'Saved here.' : 'All ' . $all . ' saved here.'),
            default => $saved . ' of ' . $all . ' saved here, the rest from .env or their defaults.',
        };
    }

    /** A value as a sentence shows it: long ones cut short, a paragraph on one line. */
    private static function shown(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return '"' . (mb_strlen($value) > 60 ? mb_substr($value, 0, 57) . '...' : $value) . '"';
    }
}
