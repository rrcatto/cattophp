<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Ui\Contract;

use CattoPHP\Ui\Contract\SourceAudit;
use PHPUnit\Framework\TestCase;

final class SourceAuditTest extends TestCase
{
    public function testAScriptThatWritesComponentMarkupIsFoundAndSelectorsAndCommentsAreNot(): void
    {
        foreach (["node.innerHTML = '<button class=\"btn btn-primary\">Go</button>';", 'node.insertAdjacentHTML("beforeend", `<section class="card"><div class="card-body"></div></section>`);', "node.className = 'cl-ui-action';", "node.classList.add('row-actions');", "node.setAttribute('class', 'badge');"] as $source) {
            self::assertNotSame([], SourceAudit::javascript($source, 'example.js'), $source);
        }
        self::assertSame([], SourceAudit::javascript('// node.innerHTML = `<div class="card">`;' . "\n/* card */ document.querySelector('.card'); node.innerHTML = '<strong>Text</strong>';", 'safe.js'));
    }

    public function testAStimulusActionBrokenByTwigOrAnAttributeIsFound(): void
    {
        self::assertNotSame([], SourceAudit::stimulusActions('<input data-action="change-{% if props.help %} aria-describedby="bad"{% endif %}>profile-image#choose">', 'profile.twig'));
        self::assertSame([], SourceAudit::stimulusActions('<input data-action="change->profile-image#choose">', 'profile.twig'));
    }

    public function testAThemeLeavesTheComponentsLayoutToThemAndMayDecorateASectionHead(): void
    {
        foreach ([
            '@media(max-width:700px){.cl-ui-row-actions{flex-wrap:wrap}}',
            '.cl-ui-section-head::before{content:"";background:gold}',
            '@media(max-width:640px){.pagination-controls{flex-wrap:wrap}}',
            '.pagination-row{flex-direction:column}',
            '.dataset-search{width:500px}',
            '.cl-ui-section-head,.cl-ui-section-head::before{content:"";position:absolute;pointer-events:none}',
            ':is(.family-a,.family-b,{color:red}',
            '.cl-ui-stat-card{color:red}}',
        ] as $broken) {
            self::assertNotSame([], SourceAudit::themeGeometry($broken, 'broken.css'), $broken);
        }
        self::assertSame([], SourceAudit::themeGeometry('.cl-ui-action{color:red;border-radius:5px}', 'theme.css'));
        self::assertSame([], SourceAudit::themeGeometry(<<<'CSS'
:root{--cl-context-surface:#efdca9}
.cl-footer{display:grid;grid-template-columns:1fr 1fr}
.cl-shell{display:grid;min-height:100vh}
:is(.family-a,.family-b) .cl-ui-section-head::before,
.family-c .cl-ui-section-head::before{content:"";position:absolute;pointer-events:none;inset:0;background:gold}
.cl-ui-section-head h2::after{content:"";position:absolute;pointer-events:none;bottom:0;width:92px;height:5px;background:gold}
.pagination-row{background:#def;border-radius:10px}
CSS, 'decorative.css'));
    }

    public function testAProjectsOwnFunctionalClassesAreTheComponentsToo(): void
    {
        self::assertSame([], SourceAudit::themeGeometry('.app-card{display:grid}', 'theme.css'), 'Not functional unless the project says so.');
        self::assertNotSame([], SourceAudit::themeGeometry('.app-card{display:grid}', 'theme.css', ['app-card']));
        self::assertNotSame([], SourceAudit::themeGeometry('.app-context-name{align-items:center}', 'theme.css', ['app-context(?:-[\w-]+)?']));
    }
}
