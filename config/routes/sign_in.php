<?php

declare(strict_types=1);

use CattoPHP\SignIn\SignInController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/** The sign-in pages. A project imports them: `cattophp_sign_in: { resource: '@CattoPHPBundle/config/routes/sign_in.php' }`. */
return static function (RoutingConfigurator $routes): void {
    $routes->add('cattophp_sign_in', '/sign-in')->controller([SignInController::class, 'start'])->methods(['GET']);
    $routes->add('cattophp_sign_in_send', '/sign-in')->controller([SignInController::class, 'send'])->methods(['POST']);
    $routes->add('cattophp_sign_in_sent', '/sign-in/sent')->controller([SignInController::class, 'sent'])->methods(['GET']);
    $routes->add('cattophp_sign_in_link', '/sign-in/link/{token}')->controller([SignInController::class, 'link'])->methods(['GET'])->requirements(['token' => '[0-9a-f]{64}']);
    $routes->add('cattophp_sign_in_link_use', '/sign-in/link/{token}')->controller([SignInController::class, 'useLink'])->methods(['POST'])->requirements(['token' => '[0-9a-f]{64}']);
    $routes->add('cattophp_sign_in_code', '/sign-in/code')->controller([SignInController::class, 'code'])->methods(['GET']);
    $routes->add('cattophp_sign_in_code_check', '/sign-in/code')->controller([SignInController::class, 'checkCode'])->methods(['POST']);
    $routes->add('cattophp_sign_in_recovery_codes', '/sign-in/recovery-codes')->controller([SignInController::class, 'recoveryCodes'])->methods(['GET']);
    $routes->add('cattophp_sign_out', '/sign-out')->controller([SignInController::class, 'signOut'])->methods(['POST']);
};
