<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel\App;

use CattoPHP\Activity\SubjectLinks;

/**
 * Where a project's own records live, registered with nothing but autoconfiguration: a garden tool's page, nothing for a
 * tool it calls lost, and an address that is not one for a broken one, which the page must not link.
 */
final class ExampleSubjectLinks implements SubjectLinks
{
    public function href(string $type, string $id): ?string
    {
        return match (true) {
            $type === 'garden_tool' && $id === 'lost0000' => null,
            $type === 'garden_tool' && $id === 'broken00' => 'javascript:alert(1)',
            $type === 'garden_tool' => '/garden-tools/' . $id . '?from=log&a="b"',
            default => null,
        };
    }
}
