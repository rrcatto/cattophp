<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Mailing;

use CattoPHP\Mailing\CattoMail\CattoMailClient;
use CattoPHP\Mailing\CattoMail\CattoMailTransport;
use CattoPHP\Mailing\MailingSettingGroup;
use CattoPHP\Mailing\MailingSettings;
use CattoPHP\Mailing\MailingTransports;
use CattoPHP\Mailing\OutgoingCopy;
use CattoPHP\Mailing\RecipientAddresses;
use CattoPHP\Mailing\SenderIdentity;
use CattoPHP\Mailing\SmtpMailingTransport;
use CattoPHP\Mailing\TransportRefused;
use CattoPHP\Settings\MemorySettingsStore;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\RecordingTransport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/** Settings > Mailing and a mail server's copies, each with the bulk-mail headers a mail server does not add itself. */
final class MailingTransportsTest extends TestCase
{
    public function testEachCopyThroughAMailServerCarriesItsListHeaders(): void
    {
        $site = new Foundations(['MAILER_DSN' => 'smtp://localhost:1025'], settingsProviders: [new MailingSettingGroup()]);
        $settings = new MailingSettings($site->settings);
        $recording = new RecordingTransport();
        $addresses = new class implements RecipientAddresses {
            public function deliveryAddress(string $email): string
            {
                return str_ends_with($email, '.invalid') ? 'catch@example.test' : $email;
            }
        };
        $smtp = new SmtpMailingTransport($settings, $site->settings, 'example', $addresses, $recording);
        $copy = new OutgoingCopy(41, str_repeat('a', 32), 'learner@example.test', 'News', '<p>News</p>', 'News', 'https://news.example.test/m/u/' . str_repeat('a', 32), '7', 'News "and" Updates');
        $result = $smtp->send('12', 'digest', new SenderIdentity('news@news.example.test', 'Example News'), [$copy, new OutgoingCopy(42, str_repeat('b', 32), 'made-up@company.invalid', 'News', '<p>News</p>', 'News', 'https://news.example.test/m/u/' . str_repeat('b', 32), '7', 'News')]);
        self::assertSame([41, 42], array_keys($result->handed));
        $email = $recording->sent[0];
        $headers = $email->getHeaders();
        self::assertSame('<https://news.example.test/m/u/' . str_repeat('a', 32) . '>', $headers->get('List-Unsubscribe')?->getBodyAsString());
        self::assertSame('List-Unsubscribe=One-Click', $headers->get('List-Unsubscribe-Post')?->getBodyAsString());
        self::assertSame('"News and Updates" <list-7.news.example.test>', $headers->get('List-Id')?->getBodyAsString());
        self::assertSame('12:digest:example', $headers->get('Feedback-ID')?->getBodyAsString());
        self::assertSame('news@news.example.test', $email->getReplyTo()[0]->getAddress(), 'Replies go to the sender without a reply-to.');
        self::assertSame('Example News', $email->getFrom()[0]->getName());
        self::assertSame('catch@example.test', $recording->sent[1]->getTo()[0]->getAddress(), 'A made-up address is delivered where the project says.');
    }

    public function testWithoutAMailServerTheCopiesAreRefusedWithWhatToDo(): void
    {
        $site = new Foundations(settingsProviders: [new MailingSettingGroup()]);
        $smtp = new SmtpMailingTransport(new MailingSettings($site->settings), $site->settings, 'example');
        $this->expectException(TransportRefused::class);
        $this->expectExceptionMessage('No mail server is set: set it in Settings, under Mailing or Mail.');
        $smtp->send('1', 'message', new SenderIdentity('news@example.test', 'News'), [new OutgoingCopy(1, 'a', 'b@example.test', 'S', '<p>S</p>', 'S', 'https://example.test/u', '1', 'News')]);
    }

    public function testSettingsChooseTheTransportAndCheckWhatIsTyped(): void
    {
        $store = new MemorySettingsStore();
        $site = new Foundations(['MAILING_FROM_NAME' => 'Example News'], $store, [new MailingSettingGroup()]);
        $settings = new MailingSettings($site->settings);
        $smtp = new SmtpMailingTransport($settings, $site->settings, 'example');
        $transports = new MailingTransports($settings, $smtp, new CattoMailTransport(new CattoMailClient(new MockHttpClient(), $settings), 'example'));
        self::assertSame('smtp', $transports->current()->name(), 'A mail server until something else is chosen.');
        self::assertSame(['Example News', 'environment'], [$settings->fromName('Site'), $settings->source('from_name')]);
        self::assertSame(['site@example.com', 'https://example.com'], [$settings->fromAddress('site@example.com'), $settings->linkBase('https://example.com/')]);
        self::assertSame(['Sender address', 'Address of the links', 'Sent through', 'cattomail API key'], $settings->save(['from_address' => 'news@news.example.test', 'link_base' => 'https://news.example.test/', 'transport' => 'cattomail', 'cattomail_key' => 'shk_one'], '1'));
        self::assertSame(['news@news.example.test', 'https://news.example.test', 'database'], [$settings->fromAddress('site@example.com'), $settings->linkBase('https://example.com'), $settings->source('from_address')]);
        self::assertSame('cattomail', $transports->current()->name());
        self::assertSame([], $settings->save(['cattomail_key' => ''], '1'), 'An empty secret keeps the one saved.');
        self::assertSame('shk_one', $settings->value('cattomail_key'));
        self::assertNotSame('shk_one', $store->all()['CATTOMAIL_API_KEY']->value);
        $settings->reset();
        self::assertSame(['smtp', 'shk_one'], [$settings->transport(), $settings->value('cattomail_key')], 'Reset leaves the catto-mail connection alone.');
        foreach ([['from_address' => 'not an address'], ['link_base' => 'https://news.example.test/path'], ['cattomail_url' => 'http://mail.example.test'], ['transport' => 'pigeon'], ['dsn' => 'localhost:25']] as $wrong) {
            try {
                $settings->save($wrong, '1');
                self::fail('Saved ' . key($wrong) . ' wrongly typed.');
            } catch (InvalidArgumentException $refused) {
                self::assertStringStartsWith(key($wrong) === 'transport' ? 'Choose' : 'Enter', $refused->getMessage());
            }
        }
    }
}
