<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Theme;

use CattoPHP\Theme\MenuEntry;
use CattoPHP\Theme\MenuProvider;
use CattoPHP\Theme\Menus;
use CattoPHP\Theme\MenuSection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** A module's pages join the project's menu: only what the reader may open, the page being read marked, in order. */
final class MenusTest extends TestCase
{
    private static function urls(): UrlGeneratorInterface
    {
        $routes = new RouteCollection();
        $routes->add('example_reports', new Route('/reports'));
        $routes->add('example_report', new Route('/reports/{code}'));
        $routes->add('example_report_settings', new Route('/reports/settings'));
        $routes->add('example_tools', new Route('/tools'));

        return new UrlGenerator($routes, new RequestContext());
    }

    /** @param iterable<MenuSection> $sections */
    private static function provider(iterable $sections): MenuProvider
    {
        return new class ($sections) implements MenuProvider {
            /** @param iterable<MenuSection> $sections */
            public function __construct(private readonly iterable $sections)
            {
            }

            public function menuSections(): iterable
            {
                return $this->sections;
            }
        };
    }

    /** @param list<string> $held */
    private static function holding(array $held): AuthorizationCheckerInterface
    {
        return new class ($held) implements AuthorizationCheckerInterface {
            /** @param list<string> $held */
            public function __construct(private readonly array $held)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return in_array($attribute, $this->held, true);
            }
        };
    }

    private static function reading(string $path, string $base = ''): RequestStack
    {
        $request = Request::create('https://example.com' . $base . $path, server: $base === '' ? [] : ['SCRIPT_FILENAME' => '/srv/example/public/index.php', 'SCRIPT_NAME' => $base . '/index.php', 'PHP_SELF' => $base . '/index.php']);
        $requests = new RequestStack();
        $requests->push($request);

        return $requests;
    }

    private static function reports(): MenuSection
    {
        return new MenuSection('reports', 'Reports', 'nav-reports', [
            MenuEntry::route('Overview', 'example_reports', permission: 'EXAMPLE.REPORTS.VIEW'),
            MenuEntry::route('Spring report', 'example_report', ['code' => 'spring'], 'EXAMPLE.REPORTS.VIEW', 'nav-report'),
            MenuEntry::route('Settings', 'example_report_settings', permission: 'EXAMPLE.REPORTS.MANAGE'),
        ]);
    }

    public function testTheReaderSeesOnlyTheEntriesTheyMayOpenAsPlainWords(): void
    {
        $menus = new Menus([self::provider([self::reports()])], self::urls(), self::reading('/'), self::holding(['EXAMPLE.REPORTS.VIEW']));
        self::assertSame([[
            'key' => 'reports', 'label' => 'Reports', 'icon' => 'nav-reports', 'current' => false,
            'entries' => [
                ['label' => 'Overview', 'href' => '/reports', 'icon' => '', 'current' => false],
                ['label' => 'Spring report', 'href' => '/reports/spring', 'icon' => 'nav-report', 'current' => false],
            ],
        ]], $menus->sections(), 'Settings needs a permission the reader does not hold.');
    }

    public function testASectionWithNothingTheReaderMayOpenIsLeftOut(): void
    {
        $tools = new MenuSection('tools', 'Tools', '', [MenuEntry::route('Tools', 'example_tools'), MenuEntry::address('Help', 'https://example.com/help')]);
        $everyone = new Menus([self::provider([self::reports(), $tools])], self::urls(), self::reading('/'), self::holding([]));
        self::assertSame(['tools'], array_column($everyone->sections(), 'key'), 'An entry needing no permission is everyone\'s.');
        self::assertSame(['/tools', 'https://example.com/help'], array_column($everyone->sections()[0]['entries'], 'href'));

        $signedOut = new Menus([self::provider([self::reports()])], self::urls(), self::reading('/'));
        self::assertSame([], $signedOut->sections(), 'Without the security system nobody holds a permission.');
    }

    public function testThePageBeingReadIsMarkedAtTheClosestEntry(): void
    {
        $all = self::holding(['EXAMPLE.REPORTS.VIEW', 'EXAMPLE.REPORTS.MANAGE']);
        $current = static function (string $path, string $base = '') use ($all): array {
            $sections = (new Menus([self::provider([self::reports()])], self::urls(), self::reading($path, $base), $all))->sections();
            $marked = array_values(array_filter($sections[0]['entries'], static fn (array $entry): bool => $entry['current']));

            return [$sections[0]['current'], array_column($marked, 'label')];
        };
        self::assertSame([true, ['Overview']], $current('/reports'));
        self::assertSame([true, ['Overview']], $current('/reports/'));
        self::assertSame([true, ['Spring report']], $current('/reports/spring'));
        self::assertSame([true, ['Spring report']], $current('/reports/spring/visitors'), 'A page under an entry is that entry\'s.');
        self::assertSame([true, ['Settings']], $current('/reports/settings'));
        self::assertSame([true, ['Overview']], $current('/reports/autumn'), 'Else the closest entry above it.');
        self::assertSame([false, []], $current('/reportsheet'), 'A longer word is another page.');
        self::assertSame([false, []], $current('/'));
    }

    public function testThePageIsFoundUnderTheSitesBasePath(): void
    {
        $routes = new RouteCollection();
        $routes->add('example_reports', new Route('/reports'));
        $requests = self::reading('/reports', '/app');
        $urls = new UrlGenerator($routes, (new RequestContext())->fromRequest($requests->getMainRequest() ?? throw new \LogicException('No request.')));
        $section = new MenuSection('reports', 'Reports', 'nav-reports', [MenuEntry::route('Overview', 'example_reports')]);
        $sections = (new Menus([self::provider([$section])], $urls, $requests))->sections();
        self::assertSame('/app/reports', $sections[0]['entries'][0]['href']);
        self::assertTrue($sections[0]['entries'][0]['current']);
    }

    public function testSectionsComeInTheProvidersOrderAndOneKeyGathersTheirEntries(): void
    {
        $first = self::provider([new MenuSection('admin', 'Administration', 'nav-admin', [MenuEntry::route('Tools', 'example_tools')])]);
        $second = self::provider([
            self::reports(),
            new MenuSection('admin', 'Admin, again', 'nav-other', [MenuEntry::address('Help', '/help', 'EXAMPLE.REPORTS.VIEW')]),
        ]);
        $sections = (new Menus([$first, $second], self::urls(), self::reading('/help'), self::holding(['EXAMPLE.REPORTS.VIEW'])))->sections();
        self::assertSame(['admin', 'reports'], array_column($sections, 'key'));
        self::assertSame(['Administration', 'nav-admin'], [$sections[0]['label'], $sections[0]['icon']], 'The first section of a key names it.');
        self::assertSame(['Tools', 'Help'], array_column($sections[0]['entries'], 'label'));
        self::assertTrue($sections[0]['entries'][1]['current']);
    }

    public function testWithNoProviderItCostsNothing(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects(self::never())->method('generate');
        $requests = $this->createMock(RequestStack::class);
        $requests->expects(self::never())->method('getMainRequest');
        self::assertSame([], (new Menus([], $urls, $requests, self::holding([])))->sections());
    }

    public function testAnEntryAndASectionRefuseWhatAMenuCannotHold(): void
    {
        foreach ([
            static fn () => MenuEntry::address('Open', 'javascript:alert(1)'),
            static fn () => MenuEntry::address('Open', '//example.org/'),
            static fn () => MenuEntry::address('Open', 'reports'),
            static fn () => MenuEntry::route('', 'example_tools'),
            static fn () => MenuEntry::route('Tools', ''),
            static fn () => MenuEntry::route('Tools', 'example_tools', permission: 'reports.view'),
            static fn () => MenuEntry::route('Tools', 'example_tools', icon: 'Nav Tools'),
            static fn () => new MenuSection('Reports', 'Reports', '', []),
            static fn () => new MenuSection('reports', ' ', '', []),
            static fn () => new MenuSection('reports', 'Reports', 'nav reports', []),
        ] as $position => $make) {
            try {
                $make();
                self::fail('Accepted case ' . ($position + 1));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
