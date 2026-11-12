<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Theme;

use CattoPHP\Http\HtmlLayout;
use CattoPHP\Tests\Support\ThemeFolders;
use CattoPHP\Theme\PageProject;
use CattoPHP\Theme\PageRenderer;
use CattoPHP\Theme\Templates;
use CattoPHP\Theme\ThemeManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/** A project's page drawn in its theme: the theme, its wrapper, a preview, the generator tag, the layout and the data's rules. */
final class PageRendererTest extends TestCase
{
    private const BASE = '<!doctype html><html data-bs-theme="{{ color_scheme }}"><head><title>{{ title }}</title>{% for url in platform.styles|merge(theme.styles) %}<link rel="stylesheet" href="{{ url }}">{% endfor %}<meta name="generator" content="the theme"></head>'
        . '<body class="{{ body_class }}"><main>{% block page_content %}{% block page_body %}{% endblock %}{% endblock %}</main><footer>{{ theme.name }} {{ theme.version }}{% if theme_preview_active %} (preview){% endif %}</footer>{% for url in platform.scripts %}<script src="{{ url }}"></script>{% endfor %}</body></html>';

    private ThemeFolders $site;
    private ThemeManager $themes;
    private Environment $twig;
    private Templates $templates;

    protected function setUp(): void
    {
        $this->site = new ThemeFolders();
        $this->site->write($this->site->root . '/bundled/plain', 'Plain', 'plain', '1.2.0', 'body{color:#222}', files: ['base.html.twig' => self::BASE]);
        $this->site->write($this->site->root . '/installed/harbour-v2.0.0', 'Harbour', 'harbour', '2.0.0', 'body{color:#036}', files: [
            'base.html.twig' => str_replace('<main>', '<main class="harbour">', self::BASE),
            'pages/admin.html.twig' => "{% extends '@theme/base.html.twig' %}{% block page_content %}<div class=\"admin-frame\">{{ block('page_body') }}</div>{% endblock %}",
        ]);
        mkdir($this->site->root . '/pages/pages', 0775, true);
        mkdir($this->site->root . '/pages/partials', 0775, true);
        file_put_contents($this->site->root . '/pages/pages/home.html.twig', "{% extends layout %}{% block page_body %}<h1>{{ heading }}</h1><p>{{ site }}</p>{% endblock %}");
        file_put_contents($this->site->root . '/pages/pages/admin-people.html.twig', "{% extends layout %}{% block page_body %}<h1>People</h1>{% endblock %}");
        file_put_contents($this->site->root . '/pages/pages/reader.html.twig', "{% extends layout %}{% block page_body %}<p>Reading</p>{% endblock %}");
        file_put_contents($this->site->root . '/pages/pages/bare.html.twig', 'no head here');
        file_put_contents($this->site->root . '/pages/frame.html.twig', '<!doctype html><html><head><title>Frame</title></head><body><article>{% block page_content %}{% block page_body %}{% endblock %}{% endblock %}</article></body></html>');
        file_put_contents($this->site->root . '/pages/partials/count.html.twig', '<span>{{ site }}: {{ count }}</span>');
        mkdir($this->site->root . '/own/sign_in', 0775, true);
        file_put_contents($this->site->root . '/own/sign_in/start.html.twig', "{% extends layout %}{% block page_body %}<h1>{{ title }}</h1>{% endblock %}");
        file_put_contents($this->site->root . '/own/alone.html.twig', '<!doctype html><html><head><title>Alone</title></head><body><p>{{ title }}</p></body></html>');
        $loader = new FilesystemLoader();
        $loader->setPaths([$this->site->root . '/pages'], Templates::PLATFORM_NAMESPACE);
        $loader->setPaths([$this->site->root . '/own'], 'Own');
        $this->twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html']);
        $this->templates = new Templates($loader);
        $this->themes = $this->site->manager();
        $this->themes->publish('plain-v1.2.0');
        $this->themes->publish('harbour-v2.0.0');
    }

    protected function tearDown(): void
    {
        unset($_GET['theme_preview'], $_SERVER['HTTP_X_SERVED_HTML']);
        $this->site->remove();
    }

    public function testAPageIsDrawnInTheActiveThemeWithTheGeneratorFirstAndLaidOut(): void
    {
        $html = $this->renderer()->render('home', ['title' => 'Welcome', 'heading' => 'Hello']);

        self::assertStringStartsWith("<!doctype html>\n<html data-bs-theme=\"light\">\n\t<head>\n\t\t<meta name=\"generator\" content=\"Example 1.2.0\">\n", $html);
        self::assertStringNotContainsString('content="the theme"', $html, 'A theme\'s own generator tag gives way to the project\'s.');
        self::assertStringContainsString('<link rel="stylesheet" href="/site.css">', $html, 'The project\'s own stylesheet before the theme\'s.');
        self::assertStringContainsString('<script src="/site.js"></script>', $html);
        self::assertStringContainsString('<link rel="stylesheet" href="/themes/plain-v1.2.0/css/theme.css?v=' . substr(hash('sha256', 'body{color:#222}'), 0, 16) . '">', $html);
        self::assertStringContainsString('<h1>Hello</h1>', $html);
        self::assertStringContainsString('<p>An example site</p>', $html, 'The project\'s values reach every page.');
        self::assertStringContainsString('class="family-home"', $html, 'The project\'s model is drawn.');
        self::assertStringContainsString('Plain 1.2.0', $html);
    }

    public function testAFamilyWrapperOfTheThemeIsUsedAndAProjectLayoutWins(): void
    {
        $this->site->choice->choose('harbour-v2.0.0', null);
        $admin = $this->renderer()->render('admin-people', ['title' => 'People']);
        self::assertStringContainsString('<div class="admin-frame">', $admin);
        self::assertStringContainsString('<main class="harbour">', $admin);

        $reader = $this->renderer()->render('reader', ['title' => 'Read']);
        self::assertStringContainsString('<article>', $reader, 'The project\'s own frame for its reader.');
        self::assertStringNotContainsString('<main', $reader);
    }

    public function testAPreviewIsDrawnOnlyForAReaderWhoMay(): void
    {
        $_GET['theme_preview'] = 'harbour-v2.0.0';
        self::assertStringContainsString('Harbour 2.0.0 (preview)', $this->renderer(mayPreview: true)->render('home', ['title' => 'Hi', 'heading' => 'Hi']));
        self::assertStringContainsString('Plain 1.2.0</footer>', $this->renderer(mayPreview: false)->render('home', ['title' => 'Hi', 'heading' => 'Hi']));
        $_GET['theme_preview'] = 'nothing-v0.0.1';
        self::assertStringContainsString('Plain 1.2.0</footer>', $this->renderer(mayPreview: true)->render('home', ['title' => 'Hi', 'heading' => 'Hi']), 'A mistyped key draws the live theme.');
    }

    public function testADevelopmentSiteServesThePageAsBuiltWhenAsked(): void
    {
        $_SERVER['HTTP_X_SERVED_HTML'] = 'as-built';
        $built = $this->renderer(debug: true)->render('home', ['title' => 'T', 'heading' => 'H']);
        self::assertStringStartsWith('<!doctype html><html data-bs-theme="light"><head><meta name="generator" content="Example 1.2.0"><title>T</title><link rel="stylesheet" href="/site.css">', $built);

        $live = $this->renderer(debug: false)->render('home', ['title' => 'T', 'heading' => 'H']);
        self::assertStringStartsWith("<!doctype html>\n", $live, 'A production site always lays the page out.');
    }

    public function testAPageWithoutAHeadIsServedAsItIs(): void
    {
        self::assertSame('no head here', $this->renderer()->render('bare'));
    }

    public function testAFragmentReadsTheProjectValuesWhichItsDataMayReplace(): void
    {
        self::assertSame('<span>An example site: 3</span>', $this->renderer()->renderFragment('partials/count', ['count' => 3]));
        self::assertSame('<span>Elsewhere: 4</span>', $this->renderer()->renderFragment('partials/count', ['count' => 4, 'site' => 'Elsewhere']));
    }

    public function testTheThemeViewNamesTheTheme(): void
    {
        $view = $this->renderer()->themeView('harbour-v2.0.0', true);
        self::assertSame('Harbour', $view['theme_name']);
        self::assertSame('light', $view['color_scheme']);
        self::assertSame('/themes/harbour-v2.0.0', $view['theme_asset_url']);
        self::assertTrue($view['theme_preview_active']);
        self::assertSame(['name' => 'Harbour', 'version' => '2.0.0', 'slug' => 'harbour', 'key' => 'harbour-v2.0.0'], array_slice($view['theme'], 0, 4));
        self::assertSame([], $view['theme']['external_styles']);
        self::assertFalse($view['theme']['is_child']);
    }

    /** @return iterable<string,array{0:string,1:array<array-key,mixed>,2:string}> */
    public static function refusals(): iterable
    {
        yield 'a page name with a dot' => ['../home', [], 'Invalid page template.'];
        yield 'a variable name no template can use' => ['home', ['two words' => 1], 'Invalid template variable name: two words'];
        yield 'a numbered variable' => ['home', [0 => 'a'], 'Invalid template variable name: 0'];
        yield 'an object' => ['home', ['items' => [['when' => new \DateTimeImmutable()]]], 'Template data contains an object at items[0][when].'];
    }

    /** @param array<array-key,mixed> $data */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testDataATemplateShouldNeverSeeIsRefused(string $page, array $data, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        /** @var array<string,mixed> $data */
        $this->renderer()->render($page, $data);
    }

    /** CattoPHP's own pages (sign-in, the error page) wear the theme as the page they are named as; one that extends nothing is served alone. */
    public function testATemplateThatIsNotAProjectPageIsDrawnInTheThemeToo(): void
    {
        $html = $this->renderer()->renderTemplate('sign-in', '@Own/sign_in/start.html.twig', ['title' => 'Sign in']);
        self::assertStringContainsString('<meta name="generator" content="Example 1.2.0">', $html);
        self::assertStringContainsString('<link rel="stylesheet" href="/themes/plain-v1.2.0/css/theme.css', $html, 'The theme\'s frame is drawn around it.');
        self::assertStringContainsString('<h1>Sign in</h1>', $html);
        self::assertStringContainsString('class="family-content"', $html, 'The project\'s model is drawn for the page it is named as.');
        $alone = $this->renderer()->renderTemplate('error', '@Own/alone.html.twig', ['title' => 'Not found']);
        self::assertStringContainsString('<meta name="generator" content="Example 1.2.0">', $alone);
        self::assertStringContainsString('<p>Not found</p>', $alone);
        foreach (['Own/sign_in/start.html.twig', '@Own/../pages/home.html.twig', '@Own/sign_in/start.twig'] as $refused) {
            try {
                $this->renderer()->renderTemplate('sign-in', $refused, ['title' => 'x']);
                self::fail('A template name was accepted: ' . $refused);
            } catch (RuntimeException $error) {
                self::assertSame('Invalid template.', $error->getMessage());
            }
        }
    }

    public function testAFragmentNameIsChecked(): void
    {
        $this->expectExceptionMessage('Invalid fragment template.');
        $this->renderer()->renderFragment('partials/count.html.twig');
    }

    private function renderer(bool $mayPreview = false, bool $debug = false): PageRenderer
    {
        $project = new class ($mayPreview) implements PageProject {
            public function __construct(private readonly bool $mayPreview)
            {
            }

            public function family(string $page): string
            {
                return match (true) {
                    str_starts_with($page, 'admin-') => 'admin',
                    $page === 'reader' => 'reader',
                    default => 'content',
                };
            }

            public function mayPreview(array $data): bool
            {
                return $this->mayPreview;
            }

            public function scalars(): array
            {
                return ['site' => 'An example site'];
            }

            public function prepare(string $page, string $family, array $data): array
            {
                return $data + ['title' => '', 'heading' => ''];
            }

            public function model(string $page, string $family, array $data, array $view): array
            {
                return $data + $this->scalars() + $view + ['body_class' => 'family-' . ($page === 'home' ? 'home' : $family), 'platform' => ['styles' => ['/site.css'], 'scripts' => ['/site.js']]];
            }

            public function layout(string $family): ?string
            {
                return $family === 'reader' ? '@platform/frame.html.twig' : null;
            }

            public function generator(): string
            {
                return 'Example 1.2.0';
            }
        };

        return new PageRenderer($this->twig, $this->templates, $this->themes, $project, new HtmlLayout(), $debug);
    }
}
