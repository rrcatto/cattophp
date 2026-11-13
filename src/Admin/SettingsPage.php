<?php

declare(strict_types=1);

namespace CattoPHP\Admin;

use CattoPHP\Foundation\Duration;
use CattoPHP\Installation\Installation;
use CattoPHP\Settings\Setting;
use CattoPHP\Settings\SettingGroup;
use CattoPHP\Settings\Settings;
use CattoPHP\Settings\SettingType;
use DateTimeImmutable;
use Throwable;

/**
 * The Settings page's groups as the page draws them: every setting of every group CattoPHP and the project provide, its
 * field, the value in force (never a secret's: a saved one shows as asterisks), where that value comes from in words
 * (saved here, when and by whom; .env; or its default) with what Reset goes back to, and for a refused form what was typed
 * and why it was refused.
 */
final class SettingsPage
{
    /** What a secret's field shows when one is set: asterisks, never blank and never the browser's dots. */
    public const SECRET_SHOWN = '************';

    public function __construct(private readonly Settings $settings, private readonly Installation $installation, private readonly AccountNames $names)
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
            $sent = $typed($group->key);
            $settings = [];
            foreach ($group->settings as $setting) {
                $settings[] = $this->setting($setting, $sent, $errors[$setting->name] ?? '');
            }
            $saved = count(array_filter($settings, static fn (array $setting): bool => $setting['overridden'] === true));
            $groups[] = [
                'key' => $group->key,
                'label' => $group->label,
                'description' => $group->description,
                'summary' => self::summary($saved, count($settings)),
                'saved' => $saved,
                'refused' => array_intersect_key($errors, $group->settings) !== [],
                'settings' => $settings,
            ];
        }

        return $groups;
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
        $help = trim($setting->help . ($setting->type === SettingType::Duration && !str_contains($setting->help, Duration::HOW) ? ' ' . Duration::HOW : ''));
        $source = $this->source($setting, $problem);
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
