<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Marketing;

use CattoPHP\Marketing\TouchAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Only a person's opening of a tracking link is a click: a link preview, a search engine, an AI's crawler or a bot is set aside. */
final class TouchAgentTest extends TestCase
{
    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function agents(): array
    {
        return [
            'a phone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1', 'person', 'phone'],
            'a computer' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:146.0) Gecko/20100101 Firefox/146.0', 'person', 'desktop'],
            'a tablet' => ['Mozilla/5.0 (iPad; CPU OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1', 'person', 'tablet'],
            'WhatsApp\'s preview' => ['WhatsApp/2.24.1.6 A', 'preview', 'unknown'],
            'Facebook\'s preview' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', 'preview', 'unknown'],
            'LinkedIn\'s preview' => ['LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)', 'preview', 'unknown'],
            'a search engine' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'search', 'unknown'],
            'an AI crawler' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)', 'ai', 'unknown'],
            'a script' => ['curl/8.5.0', 'bot', 'unknown'],
            'nothing named' => ['', 'bot', 'unknown'],
        ];
    }

    #[DataProvider('agents')]
    public function testWhatOpenedALinkIsRead(string $agent, string $kind, string $device): void
    {
        $read = TouchAgent::read($agent);
        self::assertSame([$kind, $device, $kind === 'person'], [$read->kind, $read->device, $read->isPerson()]);
        self::assertArrayHasKey($read->kind, TouchAgent::WORDS);
    }
}
