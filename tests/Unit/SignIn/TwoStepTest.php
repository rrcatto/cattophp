<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\SignIn;

use CattoPHP\Security\TwoStep\FileTwoStepStore;
use CattoPHP\Security\TwoStep\OneTimeCode;
use CattoPHP\Tests\Support\Foundations;
use PHPUnit\Framework\TestCase;

final class TwoStepTest extends TestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    public function testTheFirstRightCodeKeepsTheKeyAndEachCodeWorksOnce(): void
    {
        $site = new Foundations();
        $twoStep = $site->twoStep();
        $now = $site->clock->now()->getTimestamp();

        self::assertNull($twoStep->setUp('7', self::SECRET, '000000') === null ? null : 'kept', 'a wrong code keeps nothing');
        self::assertFalse($twoStep->isSetUp('7'));
        $codes = $twoStep->setUp('7', self::SECRET, OneTimeCode::codeAt(self::SECRET, $now));
        self::assertCount(8, (array) $codes);
        self::assertTrue($twoStep->isSetUp('7'));

        self::assertFalse($twoStep->check('7', OneTimeCode::codeAt(self::SECRET, $now)), 'the code used to set it up is used');
        $site->clock->modify('+30 seconds');
        $next = OneTimeCode::codeAt(self::SECRET, $site->clock->now()->getTimestamp());
        self::assertTrue($twoStep->check('7', $next));
        self::assertFalse($twoStep->check('7', $next), 'a code read over a shoulder is no use');
    }

    public function testEachRecoveryCodeSignsInOnce(): void
    {
        $site = new Foundations();
        $twoStep = $site->twoStep();
        $codes = (array) $twoStep->setUp('7', self::SECRET, OneTimeCode::codeAt(self::SECRET, $site->clock->now()->getTimestamp()));

        self::assertTrue($twoStep->check('7', strtolower(str_replace('-', ' ', $codes[3]))), 'typed as a person types it');
        self::assertFalse($twoStep->check('7', $codes[3]));
        self::assertSame(7, $twoStep->recoveryCodesLeft('7'));
        self::assertFalse($twoStep->check('8', $codes[4]), 'another account\'s code is no use');
        self::assertSame(['left' => 7], $site->activity->recent(1, 'two_step.recovery_code_used')[0]->details);
    }

    public function testResettingStartsAgain(): void
    {
        $site = new Foundations();
        $twoStep = $site->twoStep();
        $twoStep->setUp('7', self::SECRET, OneTimeCode::codeAt(self::SECRET, $site->clock->now()->getTimestamp()));
        $twoStep->reset('7');

        self::assertFalse($twoStep->isSetUp('7'));
        self::assertSame('two_step.reset', $site->activity->recent(1)[0]->action);
    }

    public function testTheFileStoreReadsAKeyAlreadyKeptInOneFile(): void
    {
        $folder = Foundations::folder();
        try {
            $site = new Foundations();
            $now = $site->clock->now()->getTimestamp();
            $counter = intdiv($now, 30) - 5;
            file_put_contents($folder . '/one-time-code.json', json_encode(['secret' => self::SECRET, 'last_counter' => $counter]));
            $twoStep = $site->twoStep(new FileTwoStepStore($folder . '/one-time-code.json'), false);

            self::assertTrue($twoStep->isSetUp('owner'));
            self::assertTrue($twoStep->check('owner', OneTimeCode::codeAt(self::SECRET, $now)));
            $kept = json_decode((string) file_get_contents($folder . '/one-time-code.json'), true);
            self::assertSame(['secret' => self::SECRET, 'last_counter' => intdiv($now, 30)], $kept, 'kept in the same form');
            self::assertSame(0600, fileperms($folder . '/one-time-code.json') & 0777);
        } finally {
            Foundations::remove($folder);
        }
    }

    public function testTheFileStoreKeepsOneFilePerAccount(): void
    {
        $folder = Foundations::folder();
        try {
            $site = new Foundations();
            $twoStep = $site->twoStep(new FileTwoStepStore($folder . '/two-step/{account}.json'));
            $twoStep->setUp('7', self::SECRET, OneTimeCode::codeAt(self::SECRET, $site->clock->now()->getTimestamp()));

            self::assertTrue($twoStep->isSetUp('7'));
            self::assertFalse($twoStep->isSetUp('8'));
            self::assertFileExists($folder . '/two-step/' . hash('sha256', '7') . '.json');
        } finally {
            Foundations::remove($folder);
        }
    }
}
