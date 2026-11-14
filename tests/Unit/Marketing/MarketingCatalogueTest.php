<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Marketing;

use CattoPHP\Access\PermissionCatalogue;
use CattoPHP\Marketing\MarketingActionWords;
use CattoPHP\Marketing\MarketingPermissions;
use PHPUnit\Framework\TestCase;

/** The module's permissions are in the catalogue roles grant from, and every action it records has its words. */
final class MarketingCatalogueTest extends TestCase
{
    public function testItsPermissionsAreNamedAsTheCatalogueNamesThem(): void
    {
        $catalogue = new PermissionCatalogue([new MarketingPermissions()]);
        foreach ([MarketingPermissions::VIEW, MarketingPermissions::MANAGE, MarketingPermissions::PEOPLE, MarketingPermissions::SETTINGS] as $key) {
            self::assertTrue($catalogue->has($key), $key);
            self::assertMatchesRegularExpression('/^[A-Z]+(\.[A-Z]+){1,3}$/', $key);
        }
    }

    public function testEveryActionTheModuleRecordsHasItsWords(): void
    {
        $recorded = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src/Marketing', \FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var \SplFileInfo $file */
            preg_match_all("/'(marketing(?:\.[a-z_]+)+)'/", (string) file_get_contents($file->getPathname()), $found);
            $recorded = [...$recorded, ...$found[1]];
        }
        $recorded = array_values(array_unique($recorded));
        sort($recorded);
        $words = array_keys(MarketingActionWords::WORDS);
        sort($words);
        self::assertSame($words, $recorded, 'Each action recorded has words, and each word is for an action recorded.');
        foreach (MarketingActionWords::WORDS as $words) {
            self::assertDoesNotMatchRegularExpression('/[\x{2014}!]/u', $words);
        }
    }
}
