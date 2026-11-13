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

    public function testTheStylesheetKeepsNoApplicationsOwnClasses(): void
    {
        $rules = self::rules();
        self::assertDoesNotMatchRegularExpression('/[.#][a-z-]*(flashcard|checkout|promo|question|share-page|lesson|enrol|wallet|invoice|categor|quiz|assess)/i', $rules, 'An application\'s own classes (its flash cards, checkout, question editor, share page, category tree) are in its own stylesheet.');
        self::assertStringNotContainsString('data-node-type', $rules, 'Nor what an application\'s own tree calls its nodes.');
    }

    public function testATableCellsSecondLineIsNotForcedOntoItsFirst(): void
    {
        self::assertDoesNotMatchRegularExpression('/td\s*>\s*(div|p)\b[^{]*\{[^}]*display:\s*inline/', self::rules(), 'A block in a table cell keeps its own line unless the project says otherwise.');
    }

    public function testANoticesIconSitsBesideItsWordsAndItsCloseButtonAtTheEnd(): void
    {
        $rules = self::rules();
        self::assertSame(1, preg_match('/(?:^|[}\s])\.cl-ui-notice\{([^}]*)\}/m', $rules, $notice), 'The notice\'s own rule.');
        $rule = $notice[1] ?? '';
        self::assertStringContainsString('justify-content:flex-start', $rule, 'Its icon and words side by side, not at opposite ends.');
        self::assertStringNotContainsString('space-between', $rule);
        self::assertStringContainsString('.cl-ui-notice>.cl-ui-close{margin-inline-start:auto}', $rules, 'A dismissable notice\'s close button still at the far end.');
    }

    public function testAFormAfterAFormOrADisclosureInACardHasAirAboveIt(): void
    {
        $rules = self::rules();
        self::assertStringContainsString('.cl-card-form+.cl-card-form{margin-top:1.5rem}', $rules, 'The next form keeps from the last one\'s actions what the actions keep above their rule.');
        self::assertStringContainsString('.cl-disclosure{margin-bottom:1rem}', $rules, 'What follows a disclosure keeps the space between two fields.');
        self::assertStringNotContainsString('form+form', str_replace('.cl-card-form+.cl-card-form', '', $rules), 'Only a page that asks: no other page\'s forms move.');
        $templates = dirname(__DIR__, 3) . '/templates';
        self::assertSame(2, substr_count((string) file_get_contents($templates . '/account/security.html.twig'), '<form class="cl-card-form"'), 'Sign-in and security\'s two forms in one card ask for it.');
        foreach (['account/two-step.html.twig', 'sign_in/code.html.twig'] as $page) {
            self::assertStringContainsString('<details class="cl-disclosure">', (string) file_get_contents($templates . '/' . $page), $page . '\'s Cannot scan it? asks for it.');
        }
        self::assertStringContainsString('.cl-ui-form-actions{display:flex;flex-wrap:wrap;align-items:center;gap:.6rem;margin-top:1.5rem', $rules, 'The actions\' own space, which the first matches.');
    }

    /** The stylesheet's rules, without its comments. */
    private static function rules(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(dirname(__DIR__, 3) . '/public/css/cattophp.css'));
    }
}
