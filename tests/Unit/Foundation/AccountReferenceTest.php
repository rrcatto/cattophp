<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Foundation;

use CattoPHP\Foundation\AccountReference;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AccountReferenceTest extends TestCase
{
    public function testAnAccountReferenceCarriesACheckCharacterThatCatchesTypingMistakes(): void
    {
        // The same characters a database's own check gives (the first three references made on 17 October 2026).
        self::assertSame(['U', 'S', 'Q'], [AccountReference::checkCharacter('CL202610170001'), AccountReference::checkCharacter('CL202610170002'), AccountReference::checkCharacter('CL202610170003')]);
        self::assertSame('CL-20261017-0001-U', AccountReference::format('CL202610170001U'));
        self::assertSame('not a reference', AccountReference::format('not a reference'), 'Anything else is shown as it is.');
        $long = 'CL2026111912345' . AccountReference::checkCharacter('CL2026111912345');
        self::assertSame('CL-20261119-12345-' . substr($long, -1), AccountReference::format($long), 'The number keeps rising past 9999.');
        self::assertSame($long, AccountReference::parse(AccountReference::format($long)));
        self::assertSame('CL202610170001U', AccountReference::parse('cl-20261017-0001-u'));
        self::assertSame('CL202610170001U', AccountReference::parse(' CL 2026IOI7 OOO1 U '), 'O and I typed for 0 and 1 in the digits are read as digits.');
        foreach (['CL-20261017-0001-S' => 'a wrong check character', 'CL-20261017-0010-U' => 'two digits swapped', 'CL-20261017-0002-U' => 'one digit wrong', 'CL-20261071-0001-U' => 'two date digits swapped'] as $typed => $mistake) {
            self::assertFalse(AccountReference::isValid($typed), 'The check character catches ' . $mistake . '.');
        }
        try {
            AccountReference::parse('CL-20261017-0001-S');
            self::fail('A mistake is refused.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('That account reference has a mistake in it. Check each character.', $exception->getMessage());
        }
        $this->expectExceptionMessage('An account reference looks like CL-20261016-0001-K.');
        AccountReference::parse('CL-2026-1');
    }

    public function testAnAccountReferencePrefixIsOneToThreeCapitalLetters(): void
    {
        foreach (['C' => true, 'CL' => true, 'CLM' => true, 'cl' => false, 'ABCD' => false, '' => false, 'C1' => false] as $prefix => $valid) {
            self::assertSame($valid, AccountReference::validPrefix((string) $prefix), '"' . $prefix . '"');
        }
    }
}
