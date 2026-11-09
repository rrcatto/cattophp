<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use CattoPHP\Access\FoundationPermissions;
use CattoPHP\Access\MemoryRoleStore;
use CattoPHP\Access\PermissionCatalogue;
use CattoPHP\Access\PermissionProvider;
use CattoPHP\Access\Roles;
use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\ActivityStore;
use CattoPHP\Activity\MemoryActivityStore;
use CattoPHP\Http\Throttle;
use CattoPHP\Installation\Installation;
use CattoPHP\Mail\Mailer;
use CattoPHP\Runtime\SiteMode;
use CattoPHP\Security\KeyedHash;
use CattoPHP\Security\SecretCipher;
use CattoPHP\Security\TwoStep\MemoryTwoStepStore;
use CattoPHP\Security\TwoStep\TwoStep;
use CattoPHP\Security\TwoStep\TwoStepStore;
use CattoPHP\Settings\EnvironmentValues;
use CattoPHP\Settings\FoundationSettings;
use CattoPHP\Settings\MemorySettingsStore;
use CattoPHP\Settings\Settings;
use CattoPHP\Settings\SettingsCatalogue;
use CattoPHP\Settings\SettingsProvider;
use CattoPHP\Settings\SettingsStore;
use CattoPHP\Links\FileLinkStore;
use CattoPHP\SignIn\OwnerOnlyAccounts;
use CattoPHP\SignIn\SignInAccounts;
use CattoPHP\SignIn\SignInLinks;
use CattoPHP\Links\LinkStore;
use CattoPHP\SignIn\SignInRules;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Loader\PhpFileLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\RequestContext;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * CattoPHP's foundations put together by hand, with test stores, a clock that moves only when told and a mail transport
 * that records, so a unit test needs no kernel and no database.
 */
final class Foundations
{
    public const KEY = 'cattophp-test-key-not-used-anywhere';

    public readonly MockClock $clock;
    public readonly ActivityStore $activityStore;
    public readonly ActivityLog $activity;
    public readonly SecretCipher $cipher;
    public readonly SettingsCatalogue $catalogue;
    public readonly Settings $settings;
    public readonly Installation $installation;
    public readonly SiteMode $siteMode;
    public readonly Roles $roles;
    public readonly MemoryRoleStore $roleStore;
    public readonly RecordingTransport $transport;
    public readonly Mailer $mailer;
    public readonly Environment $twig;

    /**
     * @param array<string,string> $env what .env gives
     * @param list<SettingsProvider> $settingsProviders more settings beside the foundations'
     * @param list<PermissionProvider> $permissionProviders more permissions beside the foundations'
     */
    public function __construct(
        array $env = [],
        ?SettingsStore $settingsStore = null,
        array $settingsProviders = [],
        array $permissionProviders = [],
        ?ActivityStore $activityStore = null,
        bool $siteMode = true,
    ) {
        $this->clock = new MockClock('2026-11-09 10:00:00', 'UTC');
        $this->activityStore = $activityStore ?? new MemoryActivityStore();
        $this->activity = new ActivityLog($this->activityStore, $this->clock);
        $this->cipher = new SecretCipher(self::KEY);
        $this->catalogue = new SettingsCatalogue([new FoundationSettings($siteMode), ...$settingsProviders]);
        $this->settings = new Settings($this->catalogue, $settingsStore ?? new MemorySettingsStore(), $this->cipher, new EnvironmentValues($env + [
            'APP_NAME' => 'Example Site',
            'APP_URL' => 'https://example.com',
            'APP_ADMIN_EMAIL' => 'owner@example.com',
            'MAIL_FROM_ADDRESS' => 'site@example.com',
        ]), $this->activity, $this->clock);
        $this->installation = new Installation($this->settings);
        $this->siteMode = new SiteMode($siteMode ? $this->settings : null);
        $this->roleStore = new MemoryRoleStore();
        $this->roles = new Roles($this->roleStore, new PermissionCatalogue([new FoundationPermissions(), ...$permissionProviders]), $this->activity, $this->clock);
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 2) . '/templates', 'CattoPHP');
        // As Symfony's Twig bundle has it: a .txt.twig template escapes nothing, a .html.twig one escapes HTML.
        $this->twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'name']);
        $this->transport = new RecordingTransport();
        $this->mailer = new Mailer($this->settings, $this->installation, $this->siteMode, $this->twig, $this->clock, $this->transport);
    }

    public function keyedHash(): KeyedHash
    {
        return new KeyedHash(self::KEY);
    }

    public function twoStep(?TwoStepStore $store = null, bool $recoveryCodes = true): TwoStep
    {
        return new TwoStep($store ?? new MemoryTwoStepStore(), $this->keyedHash(), $this->activity, $this->clock, $recoveryCodes);
    }

    public function signInLinks(string $folder, ?SignInAccounts $accounts = null, ?LinkStore $store = null): SignInLinks
    {
        $accounts ??= new OwnerOnlyAccounts($this->installation);

        return new SignInLinks(
            $store ?? new FileLinkStore($folder . '/links.json'),
            $accounts,
            new SignInRules($this->settings, $this->roles),
            $this->settings,
            new Throttle(new ArrayAdapter()),
            $this->mailer,
            $this->installation,
            self::urls(),
            $this->activity,
            $this->clock,
        );
    }

    /** The sign-in pages' addresses, from the bundle's own route file. */
    public static function urls(): UrlGenerator
    {
        $routes = (new PhpFileLoader(new FileLocator(dirname(__DIR__, 2) . '/config/routes')))->load('sign_in.php');

        return new UrlGenerator($routes, new RequestContext());
    }

    /** A folder of its own under the system's temporary folder, removed by the caller with remove(). */
    public static function folder(): string
    {
        $folder = sys_get_temp_dir() . '/cattophp-test-' . bin2hex(random_bytes(6));
        mkdir($folder, 0700, true);

        return $folder;
    }

    public static function remove(string $folder): void
    {
        if (!is_dir($folder)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($folder);
    }
}
