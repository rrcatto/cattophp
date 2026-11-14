<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use CattoPHP\Marketing\Sales\SaleLine;
use CattoPHP\Marketing\Sales\SaleReport;
use CattoPHP\Marketing\TrackedOpening;
use CattoPHP\Marketing\Visitors;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;

/**
 * The example shop's orders as its project would report them to Marketing\Sales, and the clicks that come before them,
 * made through the tracking links as a browser opens them: rooibos tea (two boxes, with the spring discount), an enamel
 * mug and the gift box bundle. The harness's clock moves only when told.
 */
final class MarketingSalesShop
{
    public const PERSON = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:146.0) Gecko/20100101 Firefox/146.0';
    public const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';
    public const PREVIEW = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';

    public function __construct(public readonly MarketingHarness $marketing)
    {
    }

    /** A browser opening a campaign's tracking link (its publication's, when given): the opening it recorded. */
    public function click(string $campaign, ?string $publication = null, ?string $token = null, string $agent = self::PERSON): TrackedOpening
    {
        $request = $this->browser($token, $agent);
        $opening = $this->marketing->links->open($campaign, $publication, $request);
        Assert::assertNotNull($opening, 'The link opens.');

        return $this->marketing->links->recordOpening($opening, $request);
    }

    /** The token a browser keeps after an opening gave it a visitor. */
    public static function token(TrackedOpening $opening): string
    {
        Assert::assertNotNull($opening->cookie, 'A person is given a visitor.');

        return (string) $opening->cookie->getValue();
    }

    /**
     * A request from a browser in South Africa, with its visitor's token when it has one.
     *
     * @param array<string,string> $headers more of the request's server values, such as HTTP_DNT
     */
    public function browser(?string $token = null, string $agent = self::PERSON, array $headers = []): Request
    {
        return Request::create('/checkout', 'POST', [], $token === null ? [] : [Visitors::COOKIE => $token], [], ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => $agent] + $headers);
    }

    /**
     * An order as the shop describes it: two boxes of tea (R190 less R19) and a mug (R150) unless other lines are given.
     *
     * @param list<SaleLine>|null $lines
     */
    public function report(string $reference, ?string $account = '1', ?string $promotion = null, int $wallet = 0, ?array $lines = null, ?string $organisation = null,
        string $currency = 'ZAR', ?DateTimeImmutable $placedAt = null): SaleReport
    {
        $lines ??= [self::tea(), self::mug()];
        $total = array_sum(array_map(static fn(SaleLine $line): int => $line->paidMinor(), $lines));

        return new SaleReport($reference, $account, $organisation, $placedAt ?? $this->now(), $currency, $total, $wallet, $promotion, $lines);
    }

    public static function tea(int $quantity = 2, int $discount = 1900, string $key = 'item:1'): SaleLine
    {
        return new SaleLine($key, 'product', 'tea-100', 'Rooibos tea, 100 bags', 'Product', $quantity, 9500 * $quantity, 12000 * $quantity, $discount);
    }

    public static function mug(string $key = 'item:2'): SaleLine
    {
        return new SaleLine($key, 'product', 'mug-01', 'Enamel mug', 'Product', 1, 15000);
    }

    public static function giftBox(string $key = 'item:3'): SaleLine
    {
        return new SaleLine($key, 'bundle', 'gift-box', 'Gift box', 'Bundle', 1, 32000, contains: ['product:tea-100', 'product:mug-01']);
    }

    public function now(): DateTimeImmutable
    {
        return $this->marketing->site->clock->now();
    }

    /** The clock moved on (such as +2 days), and the moment it shows now. */
    public function later(string $modify): DateTimeImmutable
    {
        $this->marketing->site->clock->modify($modify);

        return $this->now();
    }
}
