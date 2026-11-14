<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Settings;

use CattoPHP\Activity\Actor;
use CattoPHP\Settings\FileSettingsStore;
use CattoPHP\Settings\MemorySettingsStore;
use CattoPHP\Settings\Settings;
use CattoPHP\Settings\SettingsRefused;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\FullDisk;
use CattoPHP\Tests\Support\RecordingLogger;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * What the Settings page saves on an installation without a database: kept in one file of its storage folder that only
 * the application's own account can read, written whole and put in place, secrets encrypted as in the database; and a
 * file that cannot be read never stops the application.
 */
final class FileSettingsStoreTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        $this->folder = Foundations::folder();
    }

    protected function tearDown(): void
    {
        FullDisk::unregister();
        if (is_dir($this->folder)) {
            @chmod($this->folder . '/storage/settings.json', 0600);
        }
        Foundations::remove($this->folder);
    }

    public function testWhatIsSavedIsKeptInAFileOnlyTheAccountCanReadSecretsEncrypted(): void
    {
        $store = new FileSettingsStore($this->folder . '/storage/settings.json');
        $site = new Foundations(settingsStore: $store);
        $settings = $site->settings;
        self::assertTrue($settings->canSave(), 'The Settings page can save here.');
        self::assertNull($settings->readProblem());

        $settings->set('APP_NAME', 'Example Hall', new Actor('1', 'Olive Owner'));
        $settings->set('MAILER_DSN', 'smtp://site:hunter2@mail.example.com:587', new Actor('1', 'Olive Owner'));

        $path = $this->folder . '/storage/settings.json';
        self::assertSame('0700', substr(sprintf('%o', (int) fileperms($this->folder . '/storage')), -4), 'The folder, made by the store: the account alone.');
        self::assertSame('0600', substr(sprintf('%o', (int) fileperms($path)), -4), 'The file: the account alone.');
        $text = (string) file_get_contents($path);
        self::assertStringNotContainsString('hunter2', $text, 'A secret is stored encrypted.');
        $file = json_decode($text, true);
        self::assertIsArray($file);
        self::assertSame(1, $file['format']);
        self::assertSame(['APP_NAME', 'MAILER_DSN'], array_keys($file['settings']));
        self::assertSame(['value' => 'Example Hall', 'secret' => false, 'updated_at' => '2026-11-09 10:00:00+00:00', 'updated_by' => '1'], $file['settings']['APP_NAME']);
        self::assertTrue($file['settings']['MAILER_DSN']['secret']);
        self::assertSame([], glob($path . '.*.new') ?: [], 'No half-written file left beside it.');

        // Read afresh, as the next request does: the same values, the secret decrypted only when asked for.
        $again = new Foundations(settingsStore: new FileSettingsStore($path));
        self::assertSame('Example Hall', $again->settings->get('APP_NAME'));
        self::assertSame('smtp://site:hunter2@mail.example.com:587', $again->settings->get('MAILER_DSN'));
        self::assertSame(Settings::FROM_SETTINGS, $again->settings->source('APP_NAME'));
        self::assertSame('1', $again->settings->savedBy('APP_NAME')?->updatedBy);

        // Reset, and each change in the activity log as with a database.
        $settings->reset('APP_NAME', new Actor('1', 'Olive Owner'));
        self::assertSame('Example Site', (new Foundations(settingsStore: new FileSettingsStore($path)))->settings->get('APP_NAME'));
        self::assertSame(['settings.reset', 'settings.changed', 'settings.changed'], array_map(static fn ($entry): string => $entry->action, $site->activity->recent()));
        self::assertSame(['setting' => 'MAILER_DSN', 'secret_changed' => true], $site->activity->recent(1, 'settings.changed')[0]->details, 'A secret\'s change, without its values.');
    }

    public function testWithoutAFileEverySettingIsAsBefore(): void
    {
        $files = new Foundations(['AUTH_SESSION_TTL' => '12h'], new FileSettingsStore($this->folder . '/storage/settings.json'));
        $memory = new Foundations(['AUTH_SESSION_TTL' => '12h'], new MemorySettingsStore(false));
        foreach ($files->catalogue->groups() as $group) {
            foreach ($group->settings as $name => $setting) {
                self::assertSame($memory->settings->get($name), $files->settings->get($name), $name);
                self::assertSame($memory->settings->source($name), $files->settings->source($name), $name);
            }
        }
        self::assertNull($files->settings->readProblem());
        self::assertFileDoesNotExist($this->folder . '/storage/settings.json', 'Nothing is written until something is saved.');
    }

    public function testTwoStoresOnOneFileKeepBothOfTheirSaves(): void
    {
        $path = $this->folder . '/storage/settings.json';
        $first = new FileSettingsStore($path);
        $second = new FileSettingsStore($path);
        $at = new DateTimeImmutable('2026-11-09 10:00:00 UTC');
        $first->save('APP_NAME', 'Example Hall', false, $at, '1');
        $second->save('APP_ORGANISATION', 'Example Trust', false, $at, '2');
        $first->save('APP_LOCALE', 'en_ZA', false, $at, '1');

        self::assertSame(['APP_LOCALE', 'APP_NAME', 'APP_ORGANISATION'], array_keys((new FileSettingsStore($path))->all()), 'Each change reads the file again under the lock.');
        self::assertTrue($second->remove('APP_NAME'));
        self::assertFalse($second->remove('APP_NAME'));
        self::assertSame(2, $first->forgetAccount('1') + $second->forgetAccount('2'));
        foreach ((new FileSettingsStore($path))->all() as $saved) {
            self::assertNull($saved->updatedBy, 'Settings a deleted account saved stop saying who.');
        }
    }

    public function testADamagedFileLeavesTheStartingValuesSaysSoAndIsKeptAsideAtTheNextSave(): void
    {
        $path = $this->folder . '/storage/settings.json';
        mkdir(dirname($path), 0700);
        file_put_contents($path, '{"format":1,"settings":{"APP_NAME":{"value":"Exam');
        $logger = new RecordingLogger();
        $site = new Foundations(settingsStore: new FileSettingsStore($path, $logger));

        self::assertSame('Example Site', $site->settings->get('APP_NAME'), 'The starting value applies.');
        self::assertSame('The file of saved settings is damaged: it is not as CattoPHP writes it.', $site->settings->readProblem());
        $site->clock->modify('+10 seconds');
        $site->settings->get('APP_NAME');
        self::assertCount(1, $logger->lines, 'Logged once, not at every reading.');
        self::assertStringStartsWith('error: The file of saved settings is damaged', $logger->lines[0]);

        $site->settings->set('APP_NAME', 'Example Hall');
        self::assertNull($site->settings->readProblem());
        self::assertSame('Example Hall', (new Foundations(settingsStore: new FileSettingsStore($path)))->settings->get('APP_NAME'));
        $aside = glob($path . '.damaged-*') ?: [];
        self::assertCount(1, $aside, 'The damaged file is kept beside the new one.');
        self::assertSame('{"format":1,"settings":{"APP_NAME":{"value":"Exam', file_get_contents($aside[0]));
    }

    public function testAFileOfAnotherShapeOrWithAnUnreadableEntryAppliesNothingOfIt(): void
    {
        $path = $this->folder . '/storage/settings.json';
        mkdir(dirname($path), 0700);
        file_put_contents($path, json_encode(['format' => 1, 'settings' => [
            'APP_NAME' => ['value' => 'Example Hall', 'secret' => false, 'updated_at' => '2026-11-09 10:00:00+00:00', 'updated_by' => null],
            'APP_LOCALE' => ['value' => ['en_ZA'], 'secret' => false],
        ]]));
        $site = new Foundations(settingsStore: new FileSettingsStore($path));
        self::assertSame('Example Site', $site->settings->get('APP_NAME'));
        self::assertSame('The file of saved settings is damaged: 1 saved setting in it could not be read and was passed over.', $site->settings->readProblem());

        file_put_contents($path, json_encode(['format' => 7, 'settings' => []]));
        $site = new Foundations(settingsStore: new FileSettingsStore($path));
        self::assertSame('The file of saved settings is damaged: it is not as CattoPHP writes it.', $site->settings->readProblem());
    }

    public function testAFileTheAccountCannotReadLeavesTheStartingValues(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('The system\'s administrator reads every file.');
        }
        $path = $this->folder . '/storage/settings.json';
        $store = new FileSettingsStore($path);
        $store->save('APP_NAME', 'Example Hall', false, new DateTimeImmutable('2026-11-09 10:00:00 UTC'), null);
        chmod($path, 0000);
        $site = new Foundations(settingsStore: new FileSettingsStore($path));

        self::assertSame('Example Site', $site->settings->get('APP_NAME'));
        self::assertSame('The file of saved settings could not be read: it must belong to the account the application runs as.', $site->settings->readProblem());
    }

    public function testASaveThatCannotBeWrittenIsRefusedInWordsAndTheFileIsAsItWas(): void
    {
        FullDisk::register($this->folder);
        $store = new FileSettingsStore(FullDisk::SCHEME . '://storage/settings.json');
        $site = new Foundations(settingsStore: $store);
        $site->settings->set('APP_NAME', 'Example Hall');
        $before = (string) file_get_contents($this->folder . '/storage/settings.json');

        FullDisk::$full = true;
        try {
            $site->settings->set('APP_NAME', 'Example Lodge');
            self::fail('A save that was not written went unnoticed.');
        } catch (SettingsRefused $refused) {
            self::assertSame('The settings could not be saved: the storage folder could not be written: is the disk full? Nothing was changed.', $refused->errors()['']);
        }
        self::assertSame($before, (string) file_get_contents($this->folder . '/storage/settings.json'), 'The file as it was.');
        self::assertSame([], glob($this->folder . '/storage/settings.json.*.new') ?: [], 'Nothing half-written left beside it.');

        FullDisk::$full = false;
        $site->settings->set('APP_NAME', 'Example Lodge');
        self::assertSame('Example Lodge', (new FileSettingsStore(FullDisk::SCHEME . '://storage/settings.json'))->all()['APP_NAME']->value);
    }
}
