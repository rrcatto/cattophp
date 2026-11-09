<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Links;

use CattoPHP\Links\FileLinkStore;
use CattoPHP\Links\MemoryLinkStore;
use CattoPHP\Tests\Support\Foundations;
use CattoPHP\Tests\Support\LinkStoreContract;
use PHPUnit\Framework\TestCase;

final class LinkStoresTest extends TestCase
{
    public function testMemoryKeepsLinksAsEveryStoreDoes(): void
    {
        LinkStoreContract::holds(new MemoryLinkStore(), 'memory');
    }

    public function testAFileKeepsLinksAsEveryStoreDoesAndOnlyItsOwnerCanReadIt(): void
    {
        $folder = Foundations::folder();
        try {
            LinkStoreContract::holds(new FileLinkStore($folder . '/links.json'), 'file');
            self::assertSame(0600, fileperms($folder . '/links.json') & 0777);
            self::assertNotNull((new FileLinkStore($folder . '/links.json'))->find('hash-fresh', 'sign_in'), 'read again by another');
        } finally {
            Foundations::remove($folder);
        }
    }
}
