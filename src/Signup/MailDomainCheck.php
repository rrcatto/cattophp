<?php

declare(strict_types=1);

namespace CattoPHP\Signup;

/**
 * Whether an email address's domain can receive mail: a mail server (MX) or an address (A or AAAA) in the DNS. Null when
 * the server's own DNS does not answer for a domain everyone knows exists, so a broken resolver never marks a genuine
 * address.
 */
class MailDomainCheck
{
    private ?bool $resolverWorks = null;

    public function receivesMail(string $domain): ?bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || !str_contains($domain, '.')) {
            return false;
        }
        if (checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA')) {
            return true;
        }
        $this->resolverWorks ??= checkdnsrr('gmail.com', 'MX');

        return $this->resolverWorks ? false : null;
    }
}
