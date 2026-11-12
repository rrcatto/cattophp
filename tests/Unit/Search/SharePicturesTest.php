<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Search;

use CattoPHP\Foundation\SvgRaster;
use CattoPHP\Search\SharePictures;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Share pictures drawn from SVG covers, ported from cattolms's search tests. */
final class SharePicturesTest extends TestCase
{
    public function testACoverIsSlicedIntoTheShareCanvasNeverStretched(): void
    {
        $sized = SharePictures::sized('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 360" preserveAspectRatio="xMidYMid meet"><rect width="640" height="360" fill="#0f3d3e"/></svg>');
        self::assertMatchesRegularExpression('/^<svg[^>]*\bwidth="1200"[^>]*\bheight="630"/', $sized);
        self::assertStringContainsString('preserveAspectRatio="xMidYMid slice"', $sized);
        self::assertStringContainsString('viewBox="0 0 640 360"', $sized);
        self::assertStringContainsString('viewBox="0 0 300 150"', SharePictures::sized('<svg xmlns="http://www.w3.org/2000/svg" width="300px" height="150"><rect width="300" height="150"/></svg>'), 'A cover without a viewBox gets its own size.');
        self::assertStringContainsString('viewBox="0 0 640 360"', SharePictures::sized('<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>'), 'Nor a size: the usual canvas.');
        self::assertFalse(SharePictures::usable('<p>not a picture</p>'));
        self::assertFalse(SharePictures::usable(''));
        self::assertNull(SharePictures::version(''));
        self::assertSame(16, strlen((string) SharePictures::version('<svg xmlns="http://www.w3.org/2000/svg"/>')));
        self::assertNotSame(SharePictures::version('<svg xmlns="http://www.w3.org/2000/svg"/>'), SharePictures::version('<svg xmlns="http://www.w3.org/2000/svg" width="1"/>'), 'A changed cover is a new version.');
        $this->expectException(RuntimeException::class);
        SharePictures::sized('<svg');
    }

    public function testThePictureIsDrawnOnceAndStoredUnderItsCover(): void
    {
        $folder = sys_get_temp_dir() . '/share-pictures-test-' . bin2hex(random_bytes(4));
        $pictures = new SharePictures($folder);
        if (!$pictures->available()) {
            self::markTestSkipped('rsvg-convert is not installed here.');
        }
        $cover = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 360" preserveAspectRatio="xMidYMid slice"><defs><linearGradient id="g"><stop offset="0" stop-color="#15232b"/><stop offset="1" stop-color="#0f4231"/></linearGradient></defs><rect width="640" height="360" fill="url(#g)"/></svg>';
        try {
            self::assertFalse($pictures->has($cover));
            self::assertNull($pictures->bytes($cover));
            self::assertTrue($pictures->make($cover));
            self::assertTrue($pictures->has($cover));
            $size = getimagesizefromstring((string) $pictures->bytes($cover));
            self::assertNotFalse($size);
            self::assertSame([1200, 630, 'image/png'], [$size[0], $size[1], $size['mime']]);
            self::assertTrue($pictures->make($cover), 'Drawn already: nothing to do.');
            self::assertCount(1, glob($folder . '/*.png') ?: []);
            self::assertFileExists($folder . '/' . hash('sha256', "share-1200x630-v1\n" . $cover) . '.png', 'Named by the cover and the drawing, as cattolms stored them.');
            self::assertFalse($pictures->make('<svg'), 'A broken cover draws nothing and never throws.');
        } finally {
            foreach (glob($folder . '/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($folder);
        }
    }

    public function testWithoutTheToolNothingIsDrawn(): void
    {
        $pictures = new SharePictures(sys_get_temp_dir() . '/never-made-' . bin2hex(random_bytes(4)), '/nowhere/rsvg-convert');
        self::assertFalse($pictures->available());
        self::assertNull($pictures->tool());
        self::assertFalse($pictures->make('<svg xmlns="http://www.w3.org/2000/svg"/>'));
        self::assertNull(SvgRaster::tool('/nowhere/rsvg-convert'));
    }
}
