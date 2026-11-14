<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Marketing;

use CattoPHP\Marketing\TypedMoney;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Money as people type it, read as minor units, and written back as it would be typed. */
final class TypedMoneyTest extends TestCase
{
    /** @return array<string,array{0:string,1:?int}> */
    public static function amounts(): array
    {
        return [
            'whole' => ['1200', 120000], 'cents' => ['1200.50', 120050], 'one decimal' => ['12.5', 1250], 'a space between thousands' => ['1 200', 120000],
            'thousands with a comma' => ['1,200', 120000], 'both marks' => ['1,200.50', 120050], 'the comma as the decimal mark' => ['1 200,50', 120050],
            'a currency sign' => ['R1 200', 120000], 'a currency code after' => ['250 ZAR', 25000], 'points between thousands' => ['1.200.000', 120000000],
            'zero' => ['0', 0], 'cents alone' => ['.75', 75], 'negative' => ['-5', null], 'words' => ['about a hundred', null], 'three decimals' => ['1.2345', null],
            'empty' => ['', null], 'too large' => ['99999999999999', null],
        ];
    }

    #[DataProvider('amounts')]
    public function testAnAmountIsReadAsTyped(string $typed, ?int $minor): void
    {
        self::assertSame($minor, TypedMoney::minor($typed));
    }

    public function testAnAmountIsWrittenBackAsItWouldBeTyped(): void
    {
        self::assertSame(['', '1200', '1200.50', '0.05'], [TypedMoney::typed(null), TypedMoney::typed(120000), TypedMoney::typed(120050), TypedMoney::typed(5)]);
    }
}
