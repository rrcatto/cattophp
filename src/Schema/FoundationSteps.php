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

/**
 * The schema steps of CattoPHP's own foundations, in one list: the bundle registers them, and a project whose own
 * migration tool runs them (a Phinx migration, say) takes them from here without starting the application.
 */
final class FoundationSteps
{
    /** @return list<SchemaStep> */
    public static function all(): array
    {
        return [new Step202611091000Settings(), new Step202611091001Activity(), new Step202611091002Access(), new Step202611091003SignIn(), new Step202611091004ActivityLookups(), new Step202611091005AccessBoundary(), new Step202611091006Links(), new Step202611091007Sessions()];
    }

    /** @return list<class-string<SchemaStep>> */
    public static function classes(): array
    {
        return array_map(static fn (SchemaStep $step): string => $step::class, self::all());
    }
}
