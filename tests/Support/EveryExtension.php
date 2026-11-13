<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use CattoPHP\Access\Permission;
use CattoPHP\Access\PermissionProvider;
use CattoPHP\Account\ForgetsAccounts;
use CattoPHP\Activity\ActionWords;
use CattoPHP\Activity\DetailWords;
use CattoPHP\Activity\SubjectLinks;
use CattoPHP\Mailing\CattoMail\CattoMailEventHandler;
use CattoPHP\Scheduler\ScheduledTask;
use CattoPHP\Schema\SchemaStep;
use CattoPHP\Search\PublicPageProvider;
use CattoPHP\Search\SitemapSource;
use CattoPHP\Settings\SettingsProvider;
use CattoPHP\Setup\SetupMarks;
use CattoPHP\Setup\SetupStepProvider;
use CattoPHP\Ui\ComponentSet;
use CattoPHP\Ui\DrawingCheck;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * One class implementing every extension interface at once, as a project's class may implement two or more (a
 * DetailWords and a SubjectLinks for the same records): it loads, and autoconfiguration gives it every tag. Each answer
 * is the smallest there is.
 */
final class EveryExtension implements SettingsProvider, PermissionProvider, SchemaStep, ComponentSet, DrawingCheck, ActionWords, DetailWords,
    SubjectLinks, ForgetsAccounts, ScheduledTask, SetupStepProvider, CattoMailEventHandler, SitemapSource, PublicPageProvider
{
    public function settingGroups(): iterable
    {
        return [];
    }

    public function permissions(): iterable
    {
        yield new Permission('EXAMPLE.EVERYTHING.VIEW', 'Example', 'See everything the example has.');
    }

    public function id(): string
    {
        return '202611131200-every-extension';
    }

    public function describe(): string
    {
        return 'Nothing: an example';
    }

    public function apply(Connection $connection): void
    {
    }

    public function components(): array
    {
        return [];
    }

    public function sharedContext(): array
    {
        return [];
    }

    public function check(string $component, array $values): array
    {
        return $values;
    }

    public function allows(string $drawing): bool
    {
        return false;
    }

    public function words(): array
    {
        return ['example.everything_done' => 'Everything done'];
    }

    /**
     * ScheduledTask::label() and DetailWords::label($action, $key) share a name, so one method answers both: the task's
     * name when asked with nothing, and for a detail an empty string, which the activity log takes as no words of its own.
     */
    public function label(string $action = '', string $key = ''): string
    {
        return $action === '' ? 'Every extension' : '';
    }

    public function value(string $action, string $key, mixed $value): ?string
    {
        return null;
    }

    public function href(string $type, string $id): ?string
    {
        return $type === 'example' ? '/examples/' . $id : null;
    }

    public function forgetAccount(string $account, ?string $email): array
    {
        return ['removed' => [], 'unnamed' => []];
    }

    public function key(): string
    {
        return 'every_extension';
    }

    public function description(): string
    {
        return 'Does nothing, as an example.';
    }

    public function interval(): int
    {
        return 86400;
    }

    public function runsAt(): ?string
    {
        return null;
    }

    public function overdueAfter(): int
    {
        return 2 * 86400;
    }

    public function run(DateTimeImmutable $now): string
    {
        return 'Nothing to do.';
    }

    public function steps(SetupMarks $marks): iterable
    {
        return [];
    }

    public function types(): array
    {
        return [];
    }

    public function handle(string $type, array $event): string
    {
        return 'Nothing done.';
    }

    public function count(): int
    {
        return 0;
    }

    public function entries(int $limit, int $offset): array
    {
        return [];
    }

    public function pages(): iterable
    {
        return [];
    }
}
