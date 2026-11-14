<?php

declare(strict_types=1);

namespace CattoPHP;

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
use CattoPHP\Settings\FactsProvider;
use CattoPHP\Settings\FixedSettingsProvider;
use CattoPHP\Settings\SettingsProvider;
use CattoPHP\Setup\SetupStepProvider;
use CattoPHP\Marketing\Landing\PictureSource;
use CattoPHP\Marketing\Landing\SectionKind;
use CattoPHP\Marketing\Landing\TemplateProvider;
use CattoPHP\Theme\MenuProvider;
use CattoPHP\Ui\ComponentSet;
use CattoPHP\Ui\DrawingCheck;

/**
 * The service tags by which CattoPHP finds a project's own extensions, one for each extension interface. Autoconfiguration
 * gives a service the tag of every interface it implements, so a project tags nothing by hand; a project that wires a
 * service itself (with a priority, say) names its tag from here, `->tag(ServiceTags::SCHEDULED_TASK, ['priority' => 5])`,
 * or as the string in YAML (`cattophp.scheduled_task`).
 *
 * The tags are here, not on the interfaces, so one class may implement several of them: an interface constant inherited
 * from two interfaces is a fatal error in PHP. An extension interface declares no constant.
 */
final class ServiceTags
{
    /** A `Settings\SettingsProvider`: its groups are on the Settings page, each setting named as in `.env`. */
    public const SETTINGS = 'cattophp.settings';

    /** A `Settings\FixedSettingsProvider`: settings the installation holds fixed, shown or left off the Settings page. */
    public const FIXED_SETTINGS = 'cattophp.fixed_settings';

    /** A `Settings\FactsProvider`: groups of facts the Settings page shows and never changes, by its tag's priority. */
    public const SETTINGS_FACTS = 'cattophp.settings_facts';

    /** An `Access\PermissionProvider`: its permissions are in the catalogue roles grant from. */
    public const PERMISSIONS = 'cattophp.permissions';

    /** A `Schema\SchemaStep`: applied once by `cattophp:migrate` with CattoPHP's steps, in the order of the ids. */
    public const SCHEMA_STEP = 'cattophp.schema_step';

    /** A `Ui\ComponentSet`: the project's own components, drawn by `ui()` beside CattoPHP's. */
    public const COMPONENT_SET = 'cattophp.ui_component_set';

    /** A `Ui\DrawingCheck`: which of the project's own drawings a feature card may show. */
    public const DRAWING_CHECK = 'cattophp.ui_drawing';

    /** An `Activity\ActionWords`: what the project's actions in the activity log mean, in words. */
    public const ACTION_WORDS = 'cattophp.action_words';

    /** An `Activity\DetailWords`: the project's own words for an activity entry's details. */
    public const DETAIL_WORDS = 'cattophp.detail_words';

    /** An `Activity\SubjectLinks`: the address of what an activity entry is about. */
    public const SUBJECT_LINKS = 'cattophp.subject_links';

    /** An `Account\ForgetsAccounts`: forgets its records of an account when the project deletes it. */
    public const FORGETS_ACCOUNTS = 'cattophp.forgets_accounts';

    /** A `Scheduler\ScheduledTask`: run by the scheduler when it is due (the scheduler module). */
    public const SCHEDULED_TASK = 'cattophp.scheduled_task';

    /** A `Setup\SetupStepProvider`: the project's steps on the setup checklist (the setup module). */
    public const SETUP_STEPS = 'cattophp.setup_steps';

    /** A `Mailing\CattoMail\CattoMailEventHandler`: what an event catto-mail reports means to the project (mailing). */
    public const CATTOMAIL_EVENT_HANDLER = 'cattophp.cattomail_event_handler';

    /** A `Search\SitemapSource`: addresses for the sitemap, by its tag's priority (search engines and AI). */
    public const SITEMAP_SOURCES = 'cattophp.sitemap_sources';

    /** A `Search\PublicPageProvider`: the project's public pages and their words (search engines and AI). */
    public const PUBLIC_PAGES = 'cattophp.public_pages';

    /** A `Theme\MenuProvider`: its sections and pages join the project's menu (`Theme\Menus`), by its tag's priority. */
    public const MENU = 'cattophp.menu';

    /** A `Marketing\Landing\SectionKind`: a kind of section landing pages can hold (the marketing module). */
    public const LANDING_SECTION = 'cattophp.marketing_landing_section';

    /** A `Marketing\Landing\PictureSource`: where landing pages' pictures come from (the marketing module). */
    public const LANDING_PICTURE_SOURCE = 'cattophp.marketing_landing_picture_source';

    /** A `Marketing\Landing\TemplateProvider`: built-in templates landing pages start from (the marketing module). */
    public const LANDING_TEMPLATES = 'cattophp.marketing_landing_templates';

    /** Each extension interface with its tag, as autoconfiguration gives it. */
    public const EXTENSION_POINTS = [
        SettingsProvider::class => self::SETTINGS,
        FixedSettingsProvider::class => self::FIXED_SETTINGS,
        FactsProvider::class => self::SETTINGS_FACTS,
        PermissionProvider::class => self::PERMISSIONS,
        SchemaStep::class => self::SCHEMA_STEP,
        ComponentSet::class => self::COMPONENT_SET,
        DrawingCheck::class => self::DRAWING_CHECK,
        ActionWords::class => self::ACTION_WORDS,
        DetailWords::class => self::DETAIL_WORDS,
        SubjectLinks::class => self::SUBJECT_LINKS,
        ForgetsAccounts::class => self::FORGETS_ACCOUNTS,
        ScheduledTask::class => self::SCHEDULED_TASK,
        SetupStepProvider::class => self::SETUP_STEPS,
        CattoMailEventHandler::class => self::CATTOMAIL_EVENT_HANDLER,
        SitemapSource::class => self::SITEMAP_SOURCES,
        PublicPageProvider::class => self::PUBLIC_PAGES,
        MenuProvider::class => self::MENU,
        SectionKind::class => self::LANDING_SECTION,
        PictureSource::class => self::LANDING_PICTURE_SOURCE,
        TemplateProvider::class => self::LANDING_TEMPLATES,
    ];

    private function __construct()
    {
    }
}
