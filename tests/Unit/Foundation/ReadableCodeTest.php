<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Foundation;

use CattoPHP\Foundation\ReadableCode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** A short code to read out and type back: lower-case letters and digits that cannot be mistaken for each other. */
final class ReadableCodeTest extends TestCase
{
    public function testACodeHasNoCharactersThatLookAlikeAndIsAsLongAsAsked(): void
    {
        self::assertSame(31, strlen(ReadableCode::ALPHABET));
        foreach (['0', 'o', '1', 'l', 'i', 'O', 'I', 'L'] as $lookalike) {
            self::assertStringNotContainsString($lookalike, ReadableCode::ALPHABET, $lookalike . ' looks like another character.');
        }
        self::assertSame(1, preg_match('/^' . ReadableCode::CHARACTER . '{31}$/', ReadableCode::ALPHABET), 'CHARACTER matches the alphabet, and nothing more.');
        self::assertSame(0, preg_match('/^' . ReadableCode::CHARACTER . '$/', 'o'));

        $seen = [];
        for ($i = 0; $i < 2000; $i++) {
            $code = ReadableCode::make();
            self::assertMatchesRegularExpression('/^[a-hjkmnp-z2-9]{8}$/', $code);
            $seen += array_flip(str_split($code));
        }
        self::assertCount(31, $seen, 'Every character of the alphabet is used.');
        self::assertSame(4, strlen(ReadableCode::make(4)));
        self::assertSame(64, strlen(ReadableCode::make(64)));
        self::assertNotSame(ReadableCode::make(16), ReadableCode::make(16), 'Random each time.');
        foreach ([3, 65, 0, -1] as $length) {
            try {
                ReadableCode::make($length);
                self::fail('Made a code of ' . $length);
            } catch (InvalidArgumentException $refused) {
                self::assertSame('A readable code has 4 to 64 characters: ' . $length . ' is not that.', $refused->getMessage());
            }
        }
    }

    public function testACodeTypedBackIsReadInLowerCaseWithoutSpacesOrHyphens(): void
    {
        self::assertSame('k7qxm2pa', ReadableCode::read(' K7QX-M2PA '));
        self::assertSame('k7qxm2pa', ReadableCode::read('k7qx m2pa'));
        self::assertNull(ReadableCode::read('k7qxm2p0'), 'A 0 is never in a code.');
        self::assertNull(ReadableCode::read('k7qxm2p'), 'Too short.');
        self::assertSame('h4rw9c', ReadableCode::read('H4RW9C', 6));
        self::assertSame('h4rw9c', ReadableCode::read('h4rw9c', null), 'Any length from 4 to 64.');
        self::assertNull(ReadableCode::read('abc', null));
    }
}
