<?php

declare(strict_types=1);

namespace CattoPHP\Marketing;

use DateTimeImmutable;

/**
 * One opening of a tracking link: by whom (a visitor, or none kept), for which campaign and publication, from what source
 * and tags, when, from where (country, the page it came from), what opened it (TouchAgent), whether it counts as a click
 * and why not, whether it was the visitor's first, whether the browser asked not to be tracked, where it was sent, and
 * the other site it came through (connected sites: that site, its click's reference and the publication's code there),
 * and its own reference, how it is named to a connected site (none for an opening from before step 3).
 */
final class Touch
{
    /** Why an opening is not counted as a click, in words. */
    public const NOT_COUNTED = [
        'preview' => 'A link preview', 'search' => 'A search engine', 'ai' => 'An AI crawler', 'bot' => 'Another bot', 'same_visit' => 'The same visit as an earlier click',
        'draft' => 'The campaign was a draft', 'paused' => 'The campaign was paused', 'archived' => 'The campaign was archived', 'not_started' => 'The campaign had not started',
        'ended' => 'The campaign had ended',
    ];

    /** The reasons a person's opening is not counted because of the campaign, not the person. */
    public const NOT_RUNNING = ['draft', 'paused', 'archived', 'not_started', 'ended'];

    /** @param array<string,?string> $utm the five utm_* values, by their names */
    public function __construct(
        public readonly int $id,
        public readonly ?int $visitorId,
        public readonly int $campaignId,
        public readonly ?int $publicationId,
        public readonly string $source,
        public readonly array $utm,
        public readonly DateTimeImmutable $at,
        public readonly ?string $ip,
        public readonly string $country,
        public readonly ?string $referrerHost,
        public readonly string $agent,
        public readonly bool $counted,
        public readonly ?string $notCountedBecause,
        public readonly bool $firstForVisitor,
        public readonly bool $doNotTrack,
        public readonly string $landing,
        public readonly ?string $originSite = null,
        public readonly ?string $originReference = null,
        public readonly ?string $reference = null,
        public readonly ?string $originPublication = null,
    ) {
    }
}
