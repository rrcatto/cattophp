<?php

declare(strict_types=1);

namespace CattoPHP\Admin;

use CattoPHP\Http\StockPages;
use CattoPHP\Http\TypedForm;
use CattoPHP\Settings\Settings;
use CattoPHP\Settings\SettingsRefused;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Administration > Settings (`config/routes/admin.php`): every group of settings, CattoPHP's and the project's, each saved
 * on its own. .env gives every starting value; what is saved here overrides it until Reset, which puts .env's value (or the
 * setting's default) back. Each setting says where its value comes from. A secret is stored encrypted and never shown: a
 * saved one shows as asterisks, left as they are to keep it. Every change is in the activity log. Seeing it needs
 * PLATFORM.SETTINGS.VIEW, changing it SYSTEM.SETTINGS.MANAGE.
 */
final class SettingsController
{
    public function __construct(private readonly StockPages $pages, private readonly Settings $settings, private readonly SettingsPage $page)
    {
    }

    public function show(Request $request): Response
    {
        $this->pages->deny('PLATFORM.SETTINGS.VIEW');
        $errors = $this->pages->errors($request);
        $mayChange = $this->pages->may('SYSTEM.SETTINGS.MANAGE') && $this->settings->canSave();

        return $this->pages->page($request, 'admin-settings', '@CattoPHP/admin/settings.html.twig', [
            'title' => 'Settings',
            'lead' => 'Every value starts from .env. What you save here overrides it until you reset it, and every change is in the activity log.',
            'may_change' => $mayChange,
            'why_not' => $mayChange ? '' : ($this->settings->canSave() ? 'You may see these settings but not change them: that needs a role that allows changing settings.' : 'This installation keeps its settings in .env: change them there.'),
            'open' => (string) $request->query->get('open', ''),
            'groups' => $this->page->groups(fn (string $group): array => TypedForm::take($request, $this->pages->path('cattophp_admin_settings_save', ['group' => $group])), $errors),
        ]);
    }

    public function save(Request $request, string $group): RedirectResponse
    {
        $this->pages->deny('SYSTEM.SETTINGS.MANAGE');
        $label = $this->groupLabel($group);
        $secret = $this->page->secretNames($group);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_admin_settings', ['open' => $group], 'settings-' . $group, $secret);
        }
        $input = $request->request->all();
        foreach ($secret as $name) {
            // The asterisks a saved secret shows are not a new secret: left as they are, it is kept.
            if (($input[$name] ?? null) === SettingsPage::SECRET_SHOWN) {
                $input[$name] = '';
            }
        }
        try {
            $changed = $this->settings->save($group, $input, $this->pages->actor());
        } catch (SettingsRefused $refused) {
            return $this->pages->refused($request, $label . ' was not saved: ' . $refused->getMessage(), 'cattophp_admin_settings', ['open' => $group], 'settings-' . $group, $secret, $refused->errors());
        }

        return $this->pages->done($request, match (count($changed)) {
            0 => $label . ': nothing had changed, so nothing was saved.',
            1 => $label . ': 1 setting saved.',
            default => $label . ': ' . count($changed) . ' settings saved.',
        }, 'cattophp_admin_settings', ['open' => $group], 'settings-' . $group);
    }

    public function resetGroup(Request $request, string $group): RedirectResponse
    {
        $this->pages->deny('SYSTEM.SETTINGS.MANAGE');
        $label = $this->groupLabel($group);
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_admin_settings', ['open' => $group], 'settings-' . $group, $this->page->secretNames($group));
        }
        $this->settings->resetGroup($group, $this->pages->actor());

        return $this->pages->done($request, $label . ': every setting is back to .env\'s value or its default.', 'cattophp_admin_settings', ['open' => $group], 'settings-' . $group);
    }

    public function reset(Request $request, string $group, string $name): RedirectResponse
    {
        $this->pages->deny('SYSTEM.SETTINGS.MANAGE');
        $label = $this->groupLabel($group);
        $catalogue = $this->settings->catalogue();
        if (!$catalogue->has($name) || $catalogue->groupOf($name) !== $group) {
            throw new NotFoundHttpException('There is no such setting.');
        }
        if (!$this->pages->tokenIsValid($request)) {
            return $this->pages->expired($request, 'cattophp_admin_settings', ['open' => $group], 'settings-' . $group, $this->page->secretNames($group));
        }
        $this->settings->reset($name, $this->pages->actor());

        return $this->pages->done($request, $label . ': ' . $catalogue->setting($name)->label . ' is back to ' . ($this->settings->envValue($name) !== '' ? '.env\'s value.' : 'its default.'), 'cattophp_admin_settings', ['open' => $group], 'settings-' . $group);
    }

    /** A group's name, or not found. */
    private function groupLabel(string $group): string
    {
        try {
            return $this->settings->catalogue()->group($group)->label;
        } catch (InvalidArgumentException) {
            throw new NotFoundHttpException('There is no such group of settings.');
        }
    }
}
