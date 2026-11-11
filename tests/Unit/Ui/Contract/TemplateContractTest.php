<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Ui\Contract;

use CattoPHP\Ui\Components;
use CattoPHP\Ui\Contract\TemplateContract;
use PHPUnit\Framework\TestCase;

final class TemplateContractTest extends TestCase
{
    /** CattoPHP's own templates keep the rules it gives projects. */
    public function testCattoPHPsTemplatesKeepTheirOwnRules(): void
    {
        $templates = TemplateContract::cattophpTemplates();
        self::assertArrayHasKey('@CattoPHP/ui/data/card.html.twig', $templates);
        self::assertSame([], TemplateContract::duplicates($templates));
        // A page number is its own label; an icon beside every number would double a bar kept short.
        $exceptions = ['@CattoPHP/ui/data/pagination.html.twig (pg_item.page)' => 'A page number is its own label.'];
        self::assertSame([], TemplateContract::withoutIcon($templates, $exceptions));
        self::assertSame([], TemplateContract::unusedExceptions($templates, $exceptions));
        self::assertSame([], TemplateContract::menusWithoutIcon($templates));
        self::assertGreaterThan(5, count(TemplateContract::namedIcons($templates)));
    }

    public function testEveryOwnedClassIsWrittenByTheTemplateThatOwnsIt(): void
    {
        $templates = TemplateContract::cattophpTemplates();
        foreach (TemplateContract::OWNERS as $class => $owner) {
            self::assertArrayHasKey(TemplateContract::owner($owner), $templates, $class);
            // As duplicates() reads a class: in a class attribute, or a class set first (card_class = '…').
            self::assertMatchesRegularExpression('/class\\s*=\\s*["\'][^"\']*(?<![a-z0-9_-])' . preg_quote($class, '/') . '(?![a-z0-9_-])/', $templates[TemplateContract::owner($owner)], $owner . ' writes ' . $class . '.');
        }
        foreach (TemplateContract::SIGNATURES as $template) {
            self::assertContains(TemplateContract::owner($template), array_map(static fn (array $definition): string => TemplateContract::owner($definition['template']), Components::COMPONENTS));
        }
    }

    public function testAPageThatDrawsItsOwnCopyOfAComponentIsFound(): void
    {
        $problems = TemplateContract::duplicates([
            'page.html.twig' => '<div class="cl-ui-notice cl-ui-notice--info">Saved</div>{# <table> in a comment is nothing #}',
            'other.html.twig' => '<table><tr><td>x</td></tr></table>',
            'mine.html.twig' => '<div class="app-card">x</div>',
            'card.html.twig' => '<div class="app-card">x</div>',
        ], ['app-card' => 'card.html.twig']);
        self::assertSame([
            'page.html.twig writes cl-ui-notice, which @CattoPHP/ui/feedback/notice.html.twig owns: draw it with its component instead.',
            'other.html.twig draws what @CattoPHP/ui/data/data-table.html.twig draws: use its component instead.',
            'mine.html.twig writes app-card, which card.html.twig owns: draw it with its component instead.',
        ], $problems);
        self::assertSame([], TemplateContract::duplicates(['page.html.twig' => '<div class="cl-ui-notice-extra cl-ui-surfaces">x</div>']), 'A longer class is another class.');
    }

    public function testAnActionWithoutAnIconIsFoundAndAnIconMissingFromTheSprite(): void
    {
        $templates = ['page.html.twig' => "{{ ui('action.button', {label: 'Save', type: 'submit'}) }}{{ ui('action.link', {label: 'Back', href: '/', icon: 'nav-home'}) }}{{ ui('action.link', {label: 'Go', href: '/', icon: done ? 'action-done' : 'action-open', icon_only: true}) }}"];
        self::assertSame(["page.html.twig 'Save'"], TemplateContract::withoutIcon($templates));
        self::assertSame([], TemplateContract::withoutIcon($templates, ["page.html.twig 'Save'" => 'Its reason.']));
        self::assertSame(['page.html.twig gone'], TemplateContract::unusedExceptions($templates, ["page.html.twig 'Save'" => 'x', 'page.html.twig gone' => 'y']));
        $named = TemplateContract::namedIcons($templates + ['menu.html.twig' => "{% embed ui_template('action.row') with {props: ui_props('action.row', {menu: true, icon: 'action-more'})} %}{% endembed %}"]);
        self::assertSame(['action-done', 'action-more', 'action-open', 'nav-home'], array_keys($named));
        self::assertSame(['action-more (menu.html.twig)', 'action-open (page.html.twig)'], TemplateContract::missingSymbols($named, '<svg><symbol id="nav-home"/><symbol id="action-done"/></svg>'));
        self::assertSame(["menu.html.twig {menu: true}"], TemplateContract::menusWithoutIcon(['menu.html.twig' => "ui_props('action.row', {menu: true})"]));
        $this->expectExceptionMessage('page.html.twig: an action takes a literal property hash.');
        TemplateContract::actions(['page.html.twig' => "{{ ui('action.button', props) }}"]);
    }

    public function testAPropertyHashIsReadAtItsOwnLevel(): void
    {
        self::assertSame(['label' => "'Save, then go'", 'items' => "[{icon: 'x'}]", 'icon' => "done ? 'action-done' : 'action-open'"], TemplateContract::properties("{label: 'Save, then go', items: [{icon: 'x'}], icon: done ? 'action-done' : 'action-open'}"));
    }
}
