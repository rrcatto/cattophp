<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sales;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A sale another site reports about, as a connected site sends it (Marketing\Sites): the report's key and kind
 * (order_placed, order_cancelled, sale_paid, sale_refunded, sale_charged_back), its moment, the click it came from (this
 * site's touch, by its reference) and whether that click is the sale's first touch, its last or both; the sale itself
 * (its reference there, currency, state, when it was placed and paid, what was paid, refunded and charged back, and its
 * lines: label, offer, kind's label, quantity, paid and discount); and for a refund or chargeback, the movement's key and
 * amount. It never holds a name, an email address, an account or an IP address. body() is what is sent; fromBody() reads
 * what was received, refusing anything that is not one (InvalidArgumentException, in words).
 */
final class ReportedSale
{
    /** The form of the body this class writes and reads. */
    public const VERSION = 1;

    /** The kinds of report about a sale. */
    public const KINDS = ['order_placed', 'order_cancelled', 'sale_paid', 'sale_refunded', 'sale_charged_back'];

    /** @param list<SaleLine> $lines keyed line:1, line:2... in the order sent */
    public function __construct(
        public readonly string $key,
        public readonly string $kind,
        public readonly DateTimeImmutable $at,
        public readonly string $clickReference,
        public readonly bool $first,
        public readonly bool $last,
        public readonly string $reference,
        public readonly string $currency,
        public readonly string $state,
        public readonly DateTimeImmutable $placedAt,
        public readonly ?DateTimeImmutable $paidAt,
        public readonly int $paidMinor,
        public readonly int $refundedMinor,
        public readonly int $chargedBackMinor,
        public readonly array $lines,
        public readonly ?string $movementKey = null,
        public readonly ?int $movementMinor = null,
    ) {
        $problems = [];
        if ($key === '' || mb_strlen($key) > 200) {
            $problems[] = 'its key is 1 to 200 characters';
        }
        if (!in_array($kind, self::KINDS, true)) {
            $problems[] = sprintf('its kind, "%s", is not one of a sale\'s', mb_substr($kind, 0, 40));
        }
        if (preg_match('/^[a-z0-9]{8,10}$/', $clickReference) !== 1) {
            $problems[] = 'its click reference is 8 to 10 small letters and digits';
        }
        if (!$first && !$last) {
            $problems[] = 'its click is the sale\'s first touch, its last or both';
        }
        if (preg_match(SaleReport::REFERENCE, $reference) !== 1) {
            $problems[] = 'its sale\'s reference is 1 to 60 characters with no spaces';
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $problems[] = 'its currency is three capital letters';
        }
        if (!in_array($state, Sale::STATES, true)) {
            $problems[] = 'its state is waiting, paid or cancelled';
        }
        if (($state === Sale::PAID) !== ($paidAt !== null)) {
            $problems[] = 'a paid sale has its moment of payment, and only a paid sale';
        }
        if ($paidMinor < 0 || $refundedMinor < 0 || $chargedBackMinor < 0 || ($movementMinor !== null && $movementMinor < 0)) {
            $problems[] = 'its amounts are nothing or more';
        } elseif ($refundedMinor + $chargedBackMinor > $paidMinor) {
            $problems[] = 'its refunds and chargebacks are more than was paid';
        }
        if ($state !== Sale::PAID && ($paidMinor !== 0 || $refundedMinor !== 0 || $chargedBackMinor !== 0)) {
            $problems[] = 'only a paid sale has money paid, refunded or charged back';
        }
        if (in_array($kind, ['sale_refunded', 'sale_charged_back'], true) !== ($movementKey !== null && $movementMinor !== null)) {
            $problems[] = 'a refund or chargeback, and only one, has its movement\'s key and amount';
        }
        if ($movementKey !== null && ($movementKey === '' || mb_strlen($movementKey) > 120)) {
            $problems[] = 'its movement\'s key is 1 to 120 characters';
        }
        if ($lines === []) {
            $problems[] = 'its sale has at least one line';
        }
        foreach ($lines as $line) {
            foreach ($line->problems() as $problem) {
                $problems[] = lcfirst(rtrim($problem, '.'));
            }
        }
        if ($problems !== []) {
            throw new \InvalidArgumentException('A connected site\'s report about a sale was refused: ' . implode('; ', array_unique($problems)) . '.');
        }
    }

    /**
     * The report about a sale of this site's to send to the connected site its click came from.
     *
     * @param Sale $sale read with its lines
     */
    public static function of(Sale $sale, string $kind, string $key, DateTimeImmutable $at, string $clickReference, bool $first, bool $last, ?SaleMovement $movement = null): self
    {
        $lines = [];
        foreach ($sale->lines as $n => $line) {
            $lines[] = new SaleLine('line:' . ($n + 1), $line->offerType, $line->offerReference, $line->label, $line->kindLabel, $line->quantity, $line->priceMinor,
                discountMinor: $line->discountMinor);
        }

        return new self($key, $kind, $at, $clickReference, $first, $last, $sale->reference, $sale->currency, $sale->state, $sale->placedAt, $sale->paidAt, $sale->paidMinor,
            $sale->refundedMinor, $sale->chargedBackMinor, $lines, $movement?->key, $movement?->amountMinor);
    }

    /**
     * What is sent: plain values only, moments in UTC as ISO 8601.
     *
     * @return array<string,mixed>
     */
    public function body(): array
    {
        $utc = new DateTimeZone('UTC');
        $body = ['version' => self::VERSION, 'key' => $this->key, 'kind' => $this->kind, 'at' => $this->at->setTimezone($utc)->format(DATE_ATOM), 'click' => $this->clickReference,
            'first' => $this->first, 'last' => $this->last, 'sale' => ['reference' => $this->reference, 'currency' => $this->currency, 'state' => $this->state,
                'placed_at' => $this->placedAt->setTimezone($utc)->format(DATE_ATOM), 'paid_at' => $this->paidAt?->setTimezone($utc)->format(DATE_ATOM),
                'paid_minor' => $this->paidMinor, 'refunded_minor' => $this->refundedMinor, 'charged_back_minor' => $this->chargedBackMinor,
                'lines' => array_map(static fn(SaleLine $line): array => ['label' => $line->label, 'offer_type' => $line->offerType, 'offer_reference' => $line->offerReference,
                    'kind_label' => $line->kindLabel, 'quantity' => $line->quantity, 'paid_minor' => $line->paidMinor(), 'discount_minor' => $line->discountMinor], $this->lines)]];
        if ($this->movementKey !== null) {
            $body['movement'] = ['key' => $this->movementKey, 'amount_minor' => $this->movementMinor];
        }

        return $body;
    }

    /**
     * A report received, read from its body.
     *
     * @param array<mixed> $body
     * @throws \InvalidArgumentException when it is not a report about a sale of this form
     */
    public static function fromBody(array $body): self
    {
        if (($body['version'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('A connected site\'s report about a sale was refused: it is not of version ' . self::VERSION . '.');
        }
        $sale = $body['sale'] ?? null;
        if (!is_array($sale) || !is_array($sale['lines'] ?? null)) {
            throw new \InvalidArgumentException('A connected site\'s report about a sale was refused: it holds no sale with its lines.');
        }
        $lines = [];
        foreach (array_values($sale['lines']) as $n => $line) {
            if (!is_array($line)) {
                throw new \InvalidArgumentException('A connected site\'s report about a sale was refused: a line is not a list of values.');
            }
            $paid = self::int($line, 'paid_minor');
            $discount = self::int($line, 'discount_minor');
            $lines[] = new SaleLine('line:' . ($n + 1), self::text($line, 'offer_type'), self::text($line, 'offer_reference'), self::text($line, 'label'), self::text($line, 'kind_label'),
                self::int($line, 'quantity'), $paid + $discount, discountMinor: $discount);
        }
        $movement = $body['movement'] ?? null;
        $movement = is_array($movement) ? $movement : null;

        return new self(self::text($body, 'key'), self::text($body, 'kind'), self::moment($body, 'at') ?? throw new \InvalidArgumentException('A connected site\'s report about a sale was refused: it has no moment.'),
            self::text($body, 'click'), ($body['first'] ?? false) === true, ($body['last'] ?? false) === true, self::text($sale, 'reference'), self::text($sale, 'currency'),
            self::text($sale, 'state'), self::moment($sale, 'placed_at') ?? throw new \InvalidArgumentException('A connected site\'s report about a sale was refused: its sale has no moment it was placed.'),
            self::moment($sale, 'paid_at'), self::int($sale, 'paid_minor'), self::int($sale, 'refunded_minor'), self::int($sale, 'charged_back_minor'), $lines,
            $movement === null ? null : self::text($movement, 'key'), $movement === null ? null : self::int($movement, 'amount_minor'));
    }

    /** @param array<mixed> $values */
    private static function text(array $values, string $name): string
    {
        $value = $values[$name] ?? '';

        return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
    }

    /** @param array<mixed> $values */
    private static function int(array $values, string $name): int
    {
        $value = $values[$name] ?? null;
        if (!is_int($value)) {
            throw new \InvalidArgumentException(sprintf('A connected site\'s report about a sale was refused: its %s is not a whole number.', str_replace('_', ' ', $name)));
        }

        return $value;
    }

    /** @param array<mixed> $values */
    private static function moment(array $values, string $name): ?DateTimeImmutable
    {
        $value = $values[$name] ?? null;
        if ($value === null) {
            return null;
        }
        $moment = is_string($value) ? DateTimeImmutable::createFromFormat(DATE_ATOM, $value) : false;
        if ($moment === false) {
            throw new \InvalidArgumentException(sprintf('A connected site\'s report about a sale was refused: its %s is not a moment such as 2026-11-14T09:30:00+00:00.', str_replace('_', ' ', $name)));
        }

        return $moment;
    }
}
