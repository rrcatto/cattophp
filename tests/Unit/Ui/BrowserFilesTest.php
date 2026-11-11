<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/** CattoPHP's browser files reach a project's web root by bin/cattophp-assets, run after every Composer install and update. */
final class BrowserFilesTest extends TestCase
{
    public function testTheCopyReplacesWhatAnEarlierVersionPutThere(): void
    {
        $project = sys_get_temp_dir() . '/cattophp-assets-' . bin2hex(random_bytes(4));
        mkdir($project . '/public/cattophp/js', 0755, true);
        file_put_contents($project . '/public/cattophp/js/retired.js', 'old');
        file_put_contents($project . '/public/site.css', 'the project\'s own');
        try {
            $script = dirname(__DIR__, 3) . '/bin/cattophp-assets';
            exec('cd ' . escapeshellarg($project) . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' public 2>&1', $output, $status);
            self::assertSame(0, $status, implode("\n", $output));
            self::assertStringStartsWith('CattoPHP: ', $output[0] ?? '');
            self::assertFileEquals(dirname(__DIR__, 3) . '/public/js/cattophp.js', $project . '/public/cattophp/js/cattophp.js');
            self::assertFileEquals(dirname(__DIR__, 3) . '/public/css/cattophp.css', $project . '/public/cattophp/css/cattophp.css');
            self::assertFileDoesNotExist($project . '/public/cattophp/js/retired.js', 'A file an earlier version shipped is gone.');
            self::assertSame('the project\'s own', file_get_contents($project . '/public/site.css'), 'Nothing outside cattophp/ is touched.');
            exec('cd ' . escapeshellarg($project) . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' no-such-root 2>&1', $refused, $status);
            self::assertSame(1, $status);
            self::assertStringContainsString('There is no web root at', implode("\n", $refused));
        } finally {
            exec('rm -rf ' . escapeshellarg($project));
        }
    }

    public function testTheScriptGivesAProjectItsHelpersAndKeepsNoProjectsWords(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/public/js/cattophp.js');
        foreach (['window.CattoPHP = {ready, within, keepScrollerPlace};', 'window.CattoTreeSelect = {', 'extend: initialise =>', 'window.cattoLive = {'] as $contract) {
            self::assertStringContainsString($contract, $script);
        }
        self::assertDoesNotMatchRegularExpression('/\b(course|catalogue|cattolms|owner, 20)/i', $script, 'CattoPHP names no project\'s things.');
    }

    public function testTheStylesheetStylesTheComponentsAndNamesNoProjectsThings(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/css/cattophp.css');
        foreach (['.cl-ui-surface', '.cl-ui-notice', '.cl-page-head', '.pagination-row', '.cl-ui-tree-select', '.cl-ui-picture-choice'] as $component) {
            self::assertStringContainsString($component, $css, 'The stylesheet styles ' . $component . '.');
        }
        self::assertDoesNotMatchRegularExpression('/course|catalogue|bundle|learner|certificate|cattolms|owner, 20/i', $css, 'CattoPHP names no project\'s things.');
    }
}
