<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Theme;

use CattoPHP\Tests\Support\ThemeFolders;
use CattoPHP\Theme\InstallThemesCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class InstallThemesCommandTest extends TestCase
{
    private ThemeFolders $folders;

    protected function setUp(): void
    {
        $this->folders = new ThemeFolders();
    }

    protected function tearDown(): void
    {
        $this->folders->remove();
    }

    public function testEveryBundledThemeIsInstalledAndPublished(): void
    {
        $this->folders->write($this->folders->root . '/bundled/coast', 'Coast', 'coast', '1.0.0', 'body{color:#036}');
        $tester = new CommandTester(new InstallThemesCommand($this->folders->manager()));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $said = $tester->getDisplay();
        self::assertStringContainsString('plain: the default theme, published from the code', $said);
        self::assertStringContainsString('coast: installed and published', $said);
        self::assertFileExists($this->folders->root . '/public/themes/plain-v1.2.0/css/theme.css', 'The default theme\'s browser files are in the web root.');
        self::assertDirectoryExists($this->folders->root . '/installed/coast-v1.0.0');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('coast: already installed, published again', $tester->getDisplay(), 'An installed release is left as it is.');
        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));
        self::assertStringContainsString('coast: replaced and published', $tester->getDisplay());
    }

    public function testAThemeThatCannotBeInstalledIsSaidAndFailsTheCommand(): void
    {
        mkdir($this->folders->root . '/bundled/broken');
        file_put_contents($this->folders->root . '/bundled/broken/theme.json', '{"format":"something-else"}');
        $tester = new CommandTester(new InstallThemesCommand($this->folders->manager()));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('broken: not installed:', $tester->getDisplay());
        self::assertStringContainsString('plain: the default theme, published from the code', $tester->getDisplay(), 'The others are still installed.');
    }
}
