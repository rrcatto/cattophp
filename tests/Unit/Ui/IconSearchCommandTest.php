<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Ui;

use CattoPHP\Ui\IconLibrary;
use CattoPHP\Ui\IconSearchCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** cattophp:icons:search: the library's icons for some words, by name and by Lucide's tags, so a name is never guessed. */
final class IconSearchCommandTest extends TestCase
{
    public function testAnIconIsFoundByItsNameWithItsTagsBesideIt(): void
    {
        $tester = new CommandTester(new IconSearchCommand(new IconLibrary()));

        self::assertSame(Command::SUCCESS, $tester->execute(['words' => ['drill']]));
        $lines = explode("\n", trim($tester->getDisplay()));
        self::assertMatchesRegularExpression('/^drill\s{2,}power, bit, head, hole, diy, toolbox, build, construction$/', $lines[0], 'The name first, best first, then its words.');
        self::assertSame('1 icon matches "drill", best first.', end($lines));
    }

    public function testAnIconIsFoundByATagItsNameLacks(): void
    {
        $library = new IconLibrary();
        $tester = new CommandTester(new IconSearchCommand($library));

        self::assertSame(Command::SUCCESS, $tester->execute(['words' => ['borrow']]));
        self::assertStringStartsWith('hand-coins ', $tester->getDisplay(), 'No icon is named borrow; hand-coins is tagged with it.');
        self::assertContains('borrow', $library->tags('hand-coins'));
        self::assertSame([], $library->tags('no-such-icon'));
    }

    public function testEveryWordMustMatchAndTheLimitHolds(): void
    {
        $tester = new CommandTester(new IconSearchCommand(new IconLibrary()));

        self::assertSame(Command::SUCCESS, $tester->execute(['words' => ['arrow', 'down'], '--limit' => '3']));
        $lines = explode("\n", trim($tester->getDisplay()));
        self::assertCount(4, $lines, 'Three icons and the closing line.');
        foreach (array_slice($lines, 0, 3) as $line) {
            self::assertStringContainsString('arrow', $line);
            self::assertStringContainsString('down', $line);
        }
        self::assertSame('3 icons match "arrow down" (the first 3: --limit shows more), best first.', $lines[3]);

        self::assertSame(Command::INVALID, $tester->execute(['words' => ['arrow'], '--limit' => 'lots']));
        self::assertStringContainsString('The limit is a whole number', $tester->getDisplay());
    }

    public function testNothingMatchingSaysSoAndFails(): void
    {
        $tester = new CommandTester(new IconSearchCommand(new IconLibrary()));

        self::assertSame(Command::FAILURE, $tester->execute(['words' => ['zzqx']]));
        self::assertStringStartsWith('No icon matches "zzqx".', $tester->getDisplay());
    }
}
