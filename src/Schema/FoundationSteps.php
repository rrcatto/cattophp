<?php

declare(strict_types=1);

namespace CattoPHP\Schema;

use CattoPHP\Schema\Steps\Step202611091000Settings;
use CattoPHP\Schema\Steps\Step202611091001Activity;
use CattoPHP\Schema\Steps\Step202611091002Access;
use CattoPHP\Schema\Steps\Step202611091003SignIn;
use CattoPHP\Schema\Steps\Step202611091004ActivityLookups;
use CattoPHP\Schema\Steps\Step202611091005AccessBoundary;
use CattoPHP\Schema\Steps\Step202611091006Links;
use CattoPHP\Schema\Steps\Step202611091007Sessions;
use CattoPHP\Schema\Steps\Step202611120900ScheduledJobs;
use CattoPHP\Schema\Steps\Step202611121400SetupSteps;
use CattoPHP\Schema\Steps\Step202611121600SiteProtection;
use CattoPHP\Schema\Steps\Step202611121800Telegram;
use CattoPHP\Schema\Steps\Step202611122000SearchPages;
use CattoPHP\Schema\Steps\Step202611122100ApiTokens;
use CattoPHP\Schema\Steps\Step202611122200SignupAttempts;
use CattoPHP\Schema\Steps\Step202611122201IdentityChecks;
use CattoPHP\Schema\Steps\Step202611122300Inbox;
use CattoPHP\Schema\Steps\Step202611122310CattoMail;
use CattoPHP\Schema\Steps\Step202611122320MailingLists;
use CattoPHP\Schema\Steps\Step202611122330MailingCampaigns;
use CattoPHP\Schema\Steps\Step202611122340MailingForwards;
use CattoPHP\Schema\Steps\Step202611130100IdentityConnection;
use CattoPHP\Schema\Steps\Step202611130200Connections;
use CattoPHP\Schema\Steps\Step202611130300ScheduledJobTrials;

/**
 * The schema steps of CattoPHP's records in one list: the foundations' (always applied) and each module's (applied only when
 * the module is switched on, Module\Modules::schemaSteps()). The bundle registers the steps a project's modules need; a
 * project whose own migration tool runs them (a Phinx migration, say) takes them from here without starting the
 * application: all() for every step, or Modules::schemaSteps() for its modules alone.
 */
final class FoundationSteps
{
    /** @return list<class-string<SchemaStep>> the foundations' steps, which every project with a database is given */
    public static function foundationClasses(): array
    {
        return [Step202611091000Settings::class, Step202611091001Activity::class, Step202611091002Access::class, Step202611091003SignIn::class, Step202611091004ActivityLookups::class,
            Step202611091005AccessBoundary::class, Step202611091006Links::class, Step202611091007Sessions::class];
    }

    /** @return list<SchemaStep> every step, the foundations' and every module's, in the order they were written */
    public static function all(): array
    {
        return [new Step202611091000Settings(), new Step202611091001Activity(), new Step202611091002Access(), new Step202611091003SignIn(), new Step202611091004ActivityLookups(), new Step202611091005AccessBoundary(), new Step202611091006Links(), new Step202611091007Sessions(), new Step202611120900ScheduledJobs(), new Step202611121400SetupSteps(), new Step202611121600SiteProtection(), new Step202611121800Telegram(), new Step202611122000SearchPages(), new Step202611122100ApiTokens(), new Step202611122200SignupAttempts(), new Step202611122201IdentityChecks(), new Step202611122300Inbox(), new Step202611122310CattoMail(), new Step202611122320MailingLists(), new Step202611122330MailingCampaigns(), new Step202611122340MailingForwards(), new Step202611130100IdentityConnection(), new Step202611130200Connections(), new Step202611130300ScheduledJobTrials()];
    }

    /** @return list<class-string<SchemaStep>> */
    public static function classes(): array
    {
        return array_map(static fn (SchemaStep $step): string => $step::class, self::all());
    }
}
