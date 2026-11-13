<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Http;

use CattoPHP\Http\StockPages;
use PHPUnit\Framework\TestCase;

/**
 * The names CattoPHP's own pages are drawn as are the ones StockPages::PAGES lists, and the README names, since a
 * project's PageProject is given them and puts each in one of its families.
 */
final class StockPageNamesTest extends TestCase
{
    public function testEveryPageCattoPHPDrawsIsNamedInTheListAndNoOther(): void
    {
        $drawn = [];
        $source = dirname(__DIR__, 3) . '/src';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $php = (string) file_get_contents($file->getPathname());
            preg_match_all('/->page\(\$request, \'([a-z][a-z-]*)\'|->renderTemplate\(\'([a-z][a-z-]*)\'/', $php, $found, PREG_SET_ORDER);
            foreach ($found as $match) {
                $second = $match[2] ?? '';
                $drawn[$second !== '' ? $second : ($match[1] ?? '')] = true;
            }
        }
        $drawn = array_keys($drawn);
        sort($drawn);
        $listed = array_keys(StockPages::PAGES);
        sort($listed);

        self::assertSame($listed, $drawn);
    }

    public function testTheReadmeNamesEachOfThem(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 3) . '/README.md');

        foreach (array_keys(StockPages::PAGES) as $page) {
            self::assertStringContainsString('`' . $page . '`', $readme, $page . ' is in the README\'s list of page names.');
        }
    }
}
