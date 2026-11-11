<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Ui;

use CattoPHP\Tests\Support\UiHarness;
use CattoPHP\Twig\UiExtension;
use CattoPHP\Ui\Components;
use CattoPHP\Ui\ComponentSet;
use CattoPHP\Ui\DrawingCheck;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;

/** The registry: one template per component, properties bounded to data, and a project's own components beside them. */
final class ComponentsTest extends TestCase
{
    public function testEveryComponentIsNamespacedWithOneTemplateThatExists(): void
    {
        $templates = [];
        foreach (Components::COMPONENTS as $name => $definition) {
            self::assertTrue($name === 'icon' || str_contains($name, '.'), $name . ' is not namespaced.');
            self::assertStringContainsString('/', $definition['template']);
            self::assertFileExists(dirname(__DIR__, 3) . '/templates/ui/' . $definition['template'] . '.html.twig');
            $templates[] = $definition['template'];
        }
        self::assertSame($templates, array_values(array_unique($templates)), 'Each component has one template; aliases are forbidden.');
        self::assertSame('@CattoPHP/ui/feedback/notice.html.twig', UiHarness::components()->template('feedback.notice'));
        $this->expectExceptionMessage('Unknown UI component: card');
        UiHarness::components()->template('card');
    }

    public function testEveryComponentRejectsStructuralEscapeHatches(): void
    {
        $ui = UiHarness::components();
        foreach (array_keys(Components::COMPONENTS) as $name) {
            foreach (['class', 'wrapper_class', 'style', 'css', 'container_class', 'html'] as $key) {
                try {
                    $ui->properties($name, [$key => '<div>escape</div>']);
                    self::fail($name . ' accepted ' . $key);
                } catch (InvalidArgumentException $exception) {
                    self::assertStringContainsString('Unknown UI property', $exception->getMessage());
                }
            }
        }
    }

    public function testSemanticValuesAreBounded(): void
    {
        foreach (['feedback.badge' => ['label' => 'Example', 'tone' => 'good'], 'layout.surface' => ['variant' => 'custom'], 'form.grid' => ['columns' => 8], 'action.link' => ['href' => '/', 'label' => 'Go', 'size' => 'giant'], 'form.field' => ['label' => 'Title', 'span' => '7']] as $name => $properties) {
            try {
                UiHarness::components()->properties($name, $properties);
                self::fail('Invalid variant accepted for ' . $name);
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('Invalid semantic UI value', $exception->getMessage());
            }
        }
    }

    public function testRenderedMarkupAndObjectsCannotBypassTextEscaping(): void
    {
        $this->expectExceptionMessage('UI properties contain data, not objects or rendered markup.');
        UiHarness::components()->properties('action.button', ['label' => new Markup('<b>Unsafe</b>', 'UTF-8')]);
    }

    public function testARequiredPropertyMustBeGiven(): void
    {
        $this->expectExceptionMessage('Required UI property: feedback.badge.label');
        UiHarness::components()->properties('feedback.badge', []);
    }

    public function testActionElementSpecificPropertiesAreRejected(): void
    {
        foreach (['action.link' => ['disabled', 'form', 'name', 'value', 'close_modal', 'stimulus_action', 'type'], 'action.button' => ['href', 'new_window', 'rel', 'navigation_key', 'close_disclosure']] as $component => $properties) {
            foreach ($properties as $property) {
                try {
                    UiHarness::components()->properties($component, ['label' => 'Test', $property => true]);
                    self::fail($component . ' accepted ' . $property);
                } catch (InvalidArgumentException $error) {
                    self::assertStringContainsString($component . ': ' . $property, $error->getMessage());
                }
            }
        }
    }

    public function testAButtonPostsItsFormElsewhereOnlyOnThisSiteAndIntoANamedFrame(): void
    {
        $html = UiHarness::render('action.button', ['label' => 'Update preview', 'type' => 'submit', 'form_action' => '/designs/preview.pdf', 'form_target' => 'design-preview', 'skip_validation' => true]);
        foreach (['formaction="/designs/preview.pdf"', 'formtarget="design-preview"', 'formnovalidate'] as $attribute) {
            self::assertStringContainsString($attribute, $html);
        }
        foreach ([['form_action' => 'https://example.com/steal'], ['form_action' => '//example.com/x'], ['form_action' => 'javascript:alert(1)'], ['form_target' => '_top'], ['form_target' => 'Frame name']] as $broken) {
            try {
                UiHarness::components()->properties('action.button', $broken + ['label' => 'Go']);
                self::fail('Accepted ' . json_encode($broken));
            } catch (InvalidArgumentException $refused) {
                self::assertStringContainsString('only to a path on this site', $refused->getMessage());
            }
        }
    }

    public function testTabItemsAreBounded(): void
    {
        foreach ([[], [['key' => 'a', 'label' => 'A']], [['key' => 'a', 'label' => 'A', 'href' => '/', 'class' => 'x']], [['key' => '', 'label' => 'A', 'href' => '/']]] as $items) {
            try {
                UiHarness::components()->properties('layout.tabs', ['items' => $items, 'aria_label' => 'Tabs']);
                self::fail('Invalid tab items accepted');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('ab', $exception->getMessage());
            }
        }
    }

    public function testAFieldsControlCarriesOnlyItsRelationshipsToHelpAndError(): void
    {
        $ui = UiHarness::components();
        self::assertSame('aria-describedby="name-help name-error" aria-invalid="true"', (string) $ui->fieldAttributes(['label' => 'Name', 'for' => 'name', 'help' => 'Your full name', 'error' => 'Give a name']));
        self::assertSame('', (string) $ui->fieldAttributes(['label' => 'Name', 'for' => 'name']));
        try {
            $ui->fieldAttributes(['label' => 'Name', 'for' => 'two ids']);
            self::fail('Two controls accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('form.field.for must be a single control ID.', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $ui->fieldAttributes(['label' => 'Name', 'for' => 'name', 'attributes' => ['onclick' => 'bad()']]);
    }

    /** A word a project's script finds an element by: the accordion's data-<word>-section, the button's data-<hook>. */
    public function testAWordForTheProjectsScriptIsAWordAndNothingElse(): void
    {
        self::assertStringStartsWith('<details class="cl-ui-accordion-section" data-admin-section="people">', UiHarness::render('overlay.accordion-section', ['heading' => 'People', 'workspace' => 'admin', 'section_key' => 'people']));
        self::assertStringStartsWith('<details class="cl-ui-accordion-section">', UiHarness::render('overlay.accordion-section', ['heading' => 'People']));
        self::assertStringContainsString(' data-picker-new-toggle>', UiHarness::render('action.button', ['label' => 'New', 'type' => 'button', 'hook' => 'picker-new-toggle']));
        self::assertStringNotContainsString(' data-', UiHarness::render('action.button', ['label' => 'Save']));
        foreach ([['overlay.accordion-section', ['heading' => 'x', 'workspace' => 'Admin']], ['overlay.accordion-section', ['heading' => 'x', 'workspace' => 'a b']], ['action.button', ['label' => 'x', 'hook' => '" onclick="x()']], ['action.button', ['label' => 'x', 'hook' => '-x']]] as [$component, $properties]) {
            try {
                UiHarness::components()->properties($component, $properties);
                self::fail($component . ' accepted ' . json_encode($properties));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('Invalid semantic UI value', $exception->getMessage());
            }
        }
    }

    public function testAProjectsOwnComponentsAreDrawnBesideCattoPHPsWithThePageValuesTheyRead(): void
    {
        $components = new Components([self::notes()]);
        self::assertSame('@app/ui/note.html.twig', $components->template('app.note'));
        self::assertArrayHasKey('app.note', $components->definitions());
        self::assertArrayHasKey('feedback.notice', $components->definitions());
        $twig = new Environment(new ChainLoader([new ArrayLoader(['@app/ui/note.html.twig' => '<p data-token="{{ csrf }}">{{ props.text }}</p>', 'page' => "{{ ui('app.note', {text: '<Hello>'}) }}{{ ui('feedback.badge', {label: 'New'}) }}"]), self::loader()]), ['strict_variables' => true, 'autoescape' => 'html']);
        $twig->addExtension(new UiExtension($components));
        $html = $twig->render('page', ['csrf' => 'token-1', 'secret' => 'not handed on']);
        self::assertStringContainsString('<p data-token="token-1">&lt;Hello&gt;</p>', $html, 'The set\'s page values reach its components.');
        self::assertStringContainsString('cl-ui-badge', $html);
        try {
            $components->properties('app.note', ['text' => 'x', 'class' => 'y']);
            self::fail('An unknown property accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Unknown UI property for app.note: class', $exception->getMessage());
        }
        $this->expectExceptionMessage('A note says something.');
        $components->properties('app.note', ['text' => '']);
    }

    public function testTwoComponentsCannotShareAName(): void
    {
        $this->expectException(LogicException::class);
        new Components([new class implements ComponentSet {
            public function components(): array { return ['feedback.notice' => ['template' => '@app/notice.html.twig', 'defaults' => []]]; }
            public function sharedContext(): array { return []; }
            public function check(string $component, array $values): array { return $values; }
        }]);
    }

    public function testADrawingIsAFeatureCardsPictureOnlyWhenTheProjectsCheckAllowsIt(): void
    {
        $drawing = '<div class="app-drawing"><svg viewBox="0 0 10 10"></svg></div>';
        $card = ['name' => 'look', 'legend' => 'Look', 'items' => [['value' => 'a', 'label' => 'A', 'drawing' => $drawing]]];
        try {
            UiHarness::components()->properties('form.picture-choice', $card);
            self::fail('A drawing nobody allows was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('picture choice', $exception->getMessage());
        }
        $allowed = new Components([], [new class implements DrawingCheck {
            public function allows(string $drawing): bool { return str_starts_with($drawing, '<div class="app-drawing">'); }
        }]);
        $html = UiHarness::render('form.picture-choice', $card, $allowed);
        self::assertSame(1, UiHarness::xpath($html)->query('//span[@class="cl-ui-picture-choice-drawing"]/div[@class="app-drawing"]')->length);
    }

    public function testTheIconSpriteIsFingerprintedByItsContent(): void
    {
        $root = sys_get_temp_dir() . '/cattophp-sprite-' . bin2hex(random_bytes(4));
        mkdir($root . '/img', 0700, true);
        file_put_contents($root . '/img/icons.svg', '<svg xmlns="http://www.w3.org/2000/svg"><symbol id="action-add"/></svg>');
        try {
            $sprite = (new Components([], [], '/img/icons.svg', $root))->iconSprite();
            self::assertSame('/img/icons.svg?v=' . substr(hash_file('sha256', $root . '/img/icons.svg') ?: '', 0, 16), $sprite);
            self::assertSame('/img/icons.svg', UiHarness::components()->iconSprite(), 'Without the file, the address alone.');
            self::assertStringContainsString('href="' . $sprite . '#action-add"', UiHarness::source("{{ ui('icon', {name: 'action-add'}) }}", [], new Components([], [], '/img/icons.svg', $root)), 'ui() hands the icon its sprite.');
        } finally {
            unlink($root . '/img/icons.svg');
            rmdir($root . '/img');
            rmdir($root);
        }
    }

    private static function notes(): ComponentSet
    {
        return new class implements ComponentSet {
            public function components(): array { return ['app.note' => ['template' => '@app/ui/note.html.twig', 'defaults' => ['text' => null]]]; }
            public function sharedContext(): array { return ['csrf']; }
            public function check(string $component, array $values): array
            {
                if ($values['text'] === '') throw new InvalidArgumentException('A note says something.');
                return $values;
            }
        };
    }

    private static function loader(): FilesystemLoader
    {
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 3) . '/templates', 'CattoPHP');

        return $loader;
    }
}
