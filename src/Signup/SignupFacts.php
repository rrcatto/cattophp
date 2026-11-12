<?php

declare(strict_types=1);

namespace CattoPHP\Signup;

use CattoPHP\Network\NetworkFacts;

/** What is known of a registration when its form is sent, for SignupRisk to judge. */
final class SignupFacts
{
    public function __construct(
        public readonly string $email,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $userAgent,
        public readonly NetworkFacts $network,
        public readonly ?int $formSeconds = null,
        public readonly string $browserTimeZone = '',
        public readonly bool $trapFilled = false,
        public readonly bool $disposableEmail = false,
        public readonly ?bool $mailDomainReceives = null,
        public readonly int $otherAttemptsFromIp = 0,
    ) {
    }

    public function emailDomain(): string
    {
        $at = strrpos($this->email, '@');

        return $at === false ? '' : strtolower(substr($this->email, $at + 1));
    }
}
