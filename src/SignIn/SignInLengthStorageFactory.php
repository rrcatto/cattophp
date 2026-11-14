<?php

declare(strict_types=1);

namespace CattoPHP\SignIn;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageFactoryInterface;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;

/**
 * Symfony's session storage made as the project configures it, then given the sign-in's length (SignInLength) as the
 * stored session's lifetime, so the session handler (files, a database, Redis) keeps a session as long as a sign-in
 * lasts and its garbage collection, run with the same lifetime on every request, never clears one sooner. PHP's file
 * handler collects with one lifetime for every session, so a session nobody is signed in to is kept as long; it has
 * nothing to sign anyone in with. The cookie is given no lifetime here (it ends when the browser is closed): SignInLength
 * gives a signed-in session's cookie its end. It decorates `session.storage.factory`, so it wins over framework.yaml's
 * gc_maxlifetime and cookie_lifetime. Storage
 * that is not PHP's own (the tests' mock storage) is left as it is, and so is a PHP session the project started itself
 * before Symfony (the PHP bridge), which PHP no longer lets anyone change: such a project gives its own session_start()
 * these values (SignInLength::seconds()).
 */
final class SignInLengthStorageFactory implements SessionStorageFactoryInterface
{
    public function __construct(private readonly SessionStorageFactoryInterface $inner, private readonly SignInLength $length)
    {
    }

    public function createStorage(?Request $request): SessionStorageInterface
    {
        $storage = $this->inner->createStorage($request);
        if ($storage instanceof NativeSessionStorage) {
            $seconds = $this->length->seconds();
            // Ignored by Symfony when the session is already going or headers have gone, as PHP would refuse them.
            $storage->setOptions(['gc_maxlifetime' => $seconds, 'cookie_lifetime' => 0]);
        }

        return $storage;
    }
}
