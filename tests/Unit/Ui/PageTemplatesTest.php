<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Ui;

use CattoPHP\Tests\Support\UiHarness;
use PHPUnit\Framework\TestCase;

/** The page head, the identity band under it and a section's actions: how every page opens. */
final class PageTemplatesTest extends TestCase
{
    public function testThePageHeadHoldsThePagesOnlyHeadingItsWordsAndItsActions(): void
    {
        $html = UiHarness::source("{% set ph_kicker = 'People' %}{% set ph_title = '<Everyone>' %}{% set ph_lead = 'All the accounts.' %}{% set ph_actions %}{{ ui('action.link', {label: 'Add a person', href: '/people/new', icon: 'action-add'}) }}{% endset %}{% include '@CattoPHP/page/head.html.twig' %}", ['identity_placement' => 'navigation']);
        $xpath = UiHarness::xpath($html);
        self::assertSame(1, $xpath->query('//h1')->length);
        self::assertSame('<Everyone>', trim((string) $xpath->query('//section[@class="cl-page-head "]//h1')->item(0)?->textContent));
        self::assertSame('People', trim((string) $xpath->query('//span[contains(@class,"cl-kicker")]')->item(0)?->textContent));
        self::assertSame(1, $xpath->query('//div[@class="cl-page-head-actions"]/a[@href="/people/new"]')->length);
        self::assertSame(0, $xpath->query('//*[contains(@class,"cl-picture-head")]')->length, 'Without a picture the head is text alone.');
        self::assertSame(0, $xpath->query('//*[contains(@class,"cl-identity")]')->length, 'A theme showing the band in its navigation leaves it out here.');
        $back = UiHarness::source("{% set ph_title = 'One person' %}{% set ph_action_href = '/people' %}{% include '@CattoPHP/page/head.html.twig' %}", ['identity_placement' => 'navigation']);
        self::assertStringContainsString('href="/people"', $back);
        self::assertStringContainsString('#action-back', $back, 'A sub-page\'s one link is Back, with its icon, unless it says otherwise.');
        $picture = UiHarness::source("{% set ph_title = 'Help' %}{% set ph_hero %}<svg viewBox=\"0 0 640 360\"></svg>{% endset %}{% include '@CattoPHP/page/head.html.twig' %}", ['identity_placement' => 'navigation']);
        self::assertStringContainsString('<section class="cl-page-head  cl-page-head--picture">', $picture);
        self::assertSame(1, UiHarness::xpath($picture)->query('//div[@class="cl-picture-head"]/div[@class="cl-picture-head-art" and @aria-hidden="true"]')->length);
    }

    public function testTheIdentityBandNamesWhoIsSignedInWithTheirReference(): void
    {
        $signedIn = ['is_authenticated' => true, 'identity_name' => 'Ada Lovelace', 'identity_email' => 'ada@example.com', 'identity_reference' => 'CL202601010001K', 'identity_roles' => ['Administrator'], 'identity_status' => 'Active', 'identity_image' => ''];
        $html = UiHarness::source("{% include '@CattoPHP/page/identity.html.twig' %}", $signedIn + ['identity_profile_href' => '/account/profile']);
        self::assertMatchesRegularExpression('#<h2 class="cl-identity-name">\s*Ada Lovelace\s*</h2>#', $html);
        self::assertStringContainsString('<span class="cl-identity-account-label">Account Reference:</span>', $html);
        self::assertStringContainsString('<a class="cl-identity-reference" href="/account/profile" title="Open your profile">CL-20260101-0001-K</a>' . "\n", $html, 'The line ends as written.');
        self::assertStringContainsString('cl-ui-badge--info', $html);
        self::assertStringContainsString('cl-ui-badge--success', $html);
        self::assertStringContainsString('<span class="cl-identity-reference">CL-20260101-0001-K</span>', UiHarness::source("{% include '@CattoPHP/page/identity.html.twig' %}", $signedIn), 'No profile to open: the reference alone.');
    }

    public function testASignedOutReaderIsGreetedAndShownTheWaysIn(): void
    {
        $html = UiHarness::source("{% include '@CattoPHP/page/identity.html.twig' %}", ['is_authenticated' => false, 'identity_welcome' => 'Welcome to Example', 'identity_invitation' => 'Sign in to carry on.', 'identity_actions' => [
            ['label' => 'Sign in', 'variant' => 'primary', 'href' => '/login', 'icon' => 'nav-signin'],
            ['label' => 'Register', 'variant' => 'secondary', 'href' => '/register', 'icon' => 'action-register'],
        ]]);
        self::assertMatchesRegularExpression('#<h2 class="cl-identity-name">\s*Welcome to Example\s*</h2>#', $html);
        self::assertMatchesRegularExpression('#<p class="cl-identity-email">\s*Sign in to carry on\.\s*</p>#', $html);
        $xpath = UiHarness::xpath($html);
        self::assertSame(['/login', '/register'], array_map(static fn (\DOMElement $link): string => $link->getAttribute('href'), $xpath->elements('//div[contains(@class,"cl-identity-roles")]/a')));
        self::assertStringContainsString('/img/icons.svg#nav-account', $html, 'The placeholder picture from the sprite.');
    }

    public function testASectionsActionsAreLinksOrButtonsThatOpenItsWindow(): void
    {
        $html = UiHarness::source("{% include '@CattoPHP/page/section-actions.html.twig' %}", ['actions' => [
            ['label' => 'Export', 'variant' => 'secondary', 'href' => '/people/export', 'modal' => '', 'icon' => 'action-download'],
            ['label' => 'Add a person', 'variant' => 'primary', 'href' => '', 'modal' => 'add-person', 'icon' => 'action-add'],
        ]]);
        $xpath = UiHarness::xpath($html);
        self::assertSame(1, $xpath->query('//a[@href="/people/export"]')->length);
        self::assertSame(1, $xpath->query('//button[@type="button" and @data-open-modal="add-person"]')->length);
    }
}
