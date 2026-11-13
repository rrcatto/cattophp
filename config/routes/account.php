<?php

declare(strict_types=1);

use CattoPHP\Account\SecurityController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A person's Sign-in and security page: two-step sign-in by their own choice and, where the project keeps them
 * (`sign_in.sessions`), where they are signed in. For a project whose people sign in through CattoPHP's sign-in pages. A
 * project imports it: `cattophp_account: { resource: '@CattoPHPBundle/config/routes/account.php' }`.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('cattophp_account_security', '/account/security')->controller([SecurityController::class, 'show'])->methods(['GET']);
    $routes->add('cattophp_account_two_step', '/account/security/two-step')->controller([SecurityController::class, 'setUpPage'])->methods(['GET']);
    $routes->add('cattophp_account_two_step_set_up', '/account/security/two-step')->controller([SecurityController::class, 'setUp'])->methods(['POST']);
    $routes->add('cattophp_account_two_step_off', '/account/security/two-step/off')->controller([SecurityController::class, 'turnOff'])->methods(['POST']);
    $routes->add('cattophp_account_recovery_codes', '/account/security/recovery-codes')->controller([SecurityController::class, 'recoveryCodes'])->methods(['GET']);
    $routes->add('cattophp_account_recovery_codes_renew', '/account/security/recovery-codes')->controller([SecurityController::class, 'renewRecoveryCodes'])->methods(['POST']);
    $routes->add('cattophp_account_sign_in_end', '/account/security/sign-ins/end')->controller([SecurityController::class, 'endSignIn'])->methods(['POST']);
    $routes->add('cattophp_account_sign_ins_end_others', '/account/security/sign-ins/end-others')->controller([SecurityController::class, 'endOtherSignIns'])->methods(['POST']);
};
