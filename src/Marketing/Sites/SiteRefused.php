<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Sites;

/**
 * A signed arrival or message from another site that was refused: why, in words for this site's administrator ("its
 * signature did not match", "its time was 7 minutes from this site's"), and the connected site it named, when it named
 * one this site knows. The sender is never told why: an arrival is the ordinary page, a message a bare 401.
 */
final class SiteRefused extends \RuntimeException
{
    public function __construct(string $why, public readonly ?Site $site = null)
    {
        parent::__construct($why);
    }
}
