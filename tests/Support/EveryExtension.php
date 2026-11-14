<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use CattoPHP\Access\Permission;
use CattoPHP\Access\PermissionProvider;
use CattoPHP\Account\ForgetsAccounts;
use CattoPHP\Activity\ActionWords;
use CattoPHP\Activity\DetailWords;
use CattoPHP\Activity\SubjectLinks;
use CattoPHP\Activity\Actor;
use CattoPHP\Mailing\CattoMail\CattoMailEventHandler;
use CattoPHP\Marketing\Landing\LandingContext;
use CattoPHP\Marketing\Landing\LandingVisit;
use CattoPHP\Marketing\Landing\PictureSource;
use CattoPHP\Marketing\Landing\ResolvedPicture;
use CattoPHP\Marketing\Landing\SectionKind;
use CattoPHP\Marketing\Landing\TemplateProvider;
use CattoPHP\Scheduler\ScheduledTask;
use CattoPHP\Schema\SchemaStep;
use CattoPHP\Search\PublicPageProvider;
use CattoPHP\Search\SitemapSource;
use CattoPHP\Settings\FactsProvider;
use CattoPHP\Settings\FixedSettingsProvider;
use CattoPHP\Settings\SettingsProvider;
use CattoPHP\Setup\SetupMarks;
use CattoPHP\Setup\SetupStepProvider;
use CattoPHP\Theme\MenuProvider;
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
    SubjectLinks, ForgetsAccounts, ScheduledTask, SetupStepProvider, CattoMailEventHandler, SitemapSource, PublicPageProvider,
    MenuProvider, SectionKind, PictureSource, TemplateProvider, FixedSettingsProvider, FactsProvider
{
    public function settingGroups(): iterable
    {
        return [];
    }

    public function fixedSettings(): iterable
    {
        return [];
    }

    public function hiddenSettings(): iterable
    {
        return [];
    }

    public function settingsFacts(): iterable
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

    public function menuSections(): iterable
    {
        return [];
    }

    public function icon(): string
    {
        return 'box';
    }

    public function once(): bool
    {
        return true;
    }

    public function alwaysFull(): bool
    {
        return false;
    }

    /** SectionKind's and PictureSource's question alike: never on a page. */
    public function appliesTo(LandingContext $page): bool
    {
        return false;
    }

    public function defaultHeading(): string
    {
        return '';
    }

    public function defaults(): array
    {
        return [];
    }

    public function normalise(array $input, LandingContext $page): array
    {
        return [];
    }

    public function missing(array $data, LandingContext $page): array
    {
        return [];
    }

    public function summary(array $data, LandingContext $page): string
    {
        return '';
    }

    public function pictures(array $data): array
    {
        return [];
    }

    public function form(LandingContext $page): array
    {
        return [];
    }

    public function formTemplate(): string
    {
        return '@CattoPHP/marketing/landing/forms/words.html.twig';
    }

    public function view(array $data, LandingContext $page, LandingVisit $visit): ?array
    {
        return null;
    }

    public function template(): string
    {
        return '@CattoPHP/marketing/landing/sections/words.html.twig';
    }

    public function needs(): array
    {
        return [];
    }

    public function choices(LandingContext $page): array
    {
        return [];
    }

    public function mayUpload(): bool
    {
        return false;
    }

    public function upload(string $path, string $fileName, string $title, LandingContext $page, ?Actor $actor = null): string
    {
        throw new \LogicException('Nothing is uploaded to an example.');
    }

    public function exists(string $ref, LandingContext $page): bool
    {
        return false;
    }

    public function resolve(string $ref, LandingContext $page): ?ResolvedPicture
    {
        return null;
    }

    public function templates(): iterable
    {
        return [];
    }
}
