<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Gives SignInLength framework.session's options (`session.storage.options`), so the session cookie it sends again at a
 * visit has the flags Symfony's own session listener gives it. Only Symfony knows them once every extension is loaded,
 * and only where the project has Symfony's session on.
 */
final class SessionCookieOptionsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(SignInLength::class) && $container->hasParameter('session.storage.options')) {
            $container->getDefinition(SignInLength::class)->setArgument('$sessionOptions', '%session.storage.options%');
        }
    }
}
