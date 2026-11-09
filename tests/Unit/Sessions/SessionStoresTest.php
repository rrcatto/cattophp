<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Sessions;

use CattoPHP\Sessions\FileSessionStore;
use CattoPHP\Sessions\MemorySessionStore;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\SessionStoreContract;
use PHPUnit\Framework\TestCase;

final class SessionStoresTest extends TestCase
{
    public function testMemoryKeepsSignInsAsEveryStoreDoes(): void
    {
        SessionStoreContract::holds(new MemorySessionStore(), 'memory');
    }

    public function testAFileKeepsSignInsAsEveryStoreDoesAndOnlyItsOwnerCanReadIt(): void
    {
        $folder = Foundations::folder();
        try {
            SessionStoreContract::holds(new FileSessionStore($folder . '/sessions.json'), 'file');
            self::assertSame(0600, fileperms($folder . '/sessions.json') & 0777);
            self::assertNotNull((new FileSessionStore($folder . '/sessions.json'))->active('hash-d', new \DateTimeImmutable('2026-11-10 10:00:00+00:00')), 'read again by another');
        } finally {
            Foundations::remove($folder);
        }
    }
}
