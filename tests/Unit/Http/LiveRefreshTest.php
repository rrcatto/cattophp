<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Http;

use CattoPHP\Http\LiveRefresh;
use CattoPHP\Tests\Support\UiHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class LiveRefreshTest extends TestCase
{
    public function testOnlyALivePagesOwnRefreshIsMarkedAsNoVisit(): void
    {
        $refresh = Request::create('/live', 'GET', server: ['HTTP_X_LIVE_REFRESH' => '1', 'HTTP_HX_REQUEST' => 'true']);
        LiveRefresh::notAVisit($refresh);
        self::assertTrue(LiveRefresh::isRefresh($refresh));
        foreach ([Request::create('/live'), Request::create('/live', 'GET', server: ['HTTP_X_LIVE_REFRESH' => '1']), Request::create('/live', 'POST', server: ['HTTP_X_LIVE_REFRESH' => '1', 'HTTP_HX_REQUEST' => 'true'])] as $visit) {
            LiveRefresh::notAVisit($visit);
            self::assertFalse(LiveRefresh::isRefresh($visit), 'A reader\'s own visit, or a form sent, counts.');
        }
    }

    public function testALivePageSaysSoWithPauseAndAFormChangesThePageInPlace(): void
    {
        $html = UiHarness::source("{% include '@CattoPHP/page/live-status.html.twig' %}", ['live_seconds' => 15]);
        $xpath = UiHarness::xpath($html);
        self::assertSame(1, $xpath->query('//div[@id="catto-live" and @hidden and @data-live-seconds="15" and @hx-trigger="catto-live-refresh" and @hx-target="#cl-main"]')->length);
        self::assertStringContainsString('hx-headers=\'{"X-Live-Refresh":"1"}\'', $html);
        self::assertStringContainsString('Updates by itself every 15 seconds.', $html);
        self::assertSame(1, $xpath->query('//button[@id="live-pause"]')->length);
        self::assertSame(1, $xpath->query('//button[@id="live-resume" and @hidden]')->length);
        $form = UiHarness::source("<form method=\"get\" action=\"/people\" {% include '@CattoPHP/page/in-place-attrs.html.twig' %}></form>", ['in_place_action' => '/people']);
        self::assertStringContainsString('hx-get="/people" hx-target="#cl-main" hx-select="#cl-main" hx-swap="outerMorph" hx-push-url="true" hx-sync="#cl-main:replace"', $form);
    }
}
