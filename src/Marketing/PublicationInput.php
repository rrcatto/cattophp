<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

/**
 * A publication as a person typed it, every value as text: Publications checks each and says why any is refused, by its
 * field (channel, advert, code, place, published_on, published_address, cost, destination, notes). The advert is its
 * reference; the date is typed as 2026-12-01; the cost as money is typed. What it stands for in the project (a mailing
 * message, say) is given by the project's code, never typed.
 */
final class PublicationInput
{
    public function __construct(
        public readonly string $channel = '',
        public readonly string $advert = '',
        public readonly string $code = '',
        public readonly string $place = '',
        public readonly string $publishedOn = '',
        public readonly string $publishedAddress = '',
        public readonly string $cost = '',
        public readonly string $destination = '',
        public readonly string $notes = '',
        public readonly ?string $sourceType = null,
        public readonly ?string $sourceReference = null,
    ) {
    }

    /** @param array<string,mixed> $input a form's fields by their names */
    public static function fromForm(array $input): self
    {
        return new self(Rows::typed($input['channel'] ?? ''), Rows::typed($input['advert'] ?? ''), strtolower(Rows::typed($input['code'] ?? '')), Rows::typed($input['place'] ?? ''),
            Rows::typed($input['published_on'] ?? ''), Rows::typed($input['published_address'] ?? ''), Rows::typed($input['cost'] ?? ''), Rows::typed($input['destination'] ?? ''),
            Rows::typed($input['notes'] ?? ''));
    }

    /** A publication as its edit form starts: what it holds now, the advert by its reference. */
    public static function of(Publication $publication, ?Advert $advert): self
    {
        return new self($publication->channelKey, $advert->reference ?? '', $publication->code, $publication->place, $publication->publishedOn?->format('Y-m-d') ?? '',
            $publication->publishedAddress ?? '', $publication->costMinor === 0 ? '' : TypedMoney::typed($publication->costMinor), $publication->destination ?? '', $publication->notes,
            $publication->sourceType, $publication->sourceReference);
    }
}
