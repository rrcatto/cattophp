<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Marketing;

use CattoPHP\Marketing\Destination;
use PHPUnit\Framework\TestCase;

/** A tracking link leads only to a path on the site or a whole address someone typed: never anywhere else. */
final class DestinationTest extends TestCase
{
    public function testAPathOrAWholeAddressIsTaken(): void
    {
        foreach (['/', '/offers/spring?colour=red#top', 'https://example.com/page', 'http://example.org'] as $good) {
            self::assertNull(Destination::problem($good), $good);
        }
        self::assertSame('/', Destination::normalise('  '));
    }

    public function testAnythingElseIsRefusedInWords(): void
    {
        foreach (['//example.com', 'javascript:alert(1)', 'example.com/page', '/with space', "/line\nbreak", 'ftp://example.com', 'https://user:pass@example.com', '/back\\slash', '/' . str_repeat('a', 2000)] as $bad) {
            self::assertNotNull(Destination::problem($bad), $bad);
        }
        self::assertStringContainsString('starting with /', (string) Destination::problem('offers'));
    }
}
