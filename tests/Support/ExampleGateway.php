<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use Omnipay\Common\AbstractGateway;
use Symfony\Component\HttpFoundation\Request;

/** An Omnipay gateway for the payments module's tests: a key, a secret, and the request it was given. */
final class ExampleGateway extends AbstractGateway
{
    public function getName(): string
    {
        return 'Example';
    }

    /** @return array<string,mixed> */
    public function getDefaultParameters(): array
    {
        return ['apiKey' => '', 'apiSecret' => '', 'webhookSecret' => ''];
    }

    public function getApiKey(): string
    {
        return (string) $this->getParameter('apiKey');
    }

    public function setApiKey(string $value): static
    {
        return $this->setParameter('apiKey', $value);
    }

    public function getApiSecret(): string
    {
        return (string) $this->getParameter('apiSecret');
    }

    public function setApiSecret(string $value): static
    {
        return $this->setParameter('apiSecret', $value);
    }

    public function getWebhookSecret(): string
    {
        return (string) $this->getParameter('webhookSecret');
    }

    public function setWebhookSecret(string $value): static
    {
        return $this->setParameter('webhookSecret', $value);
    }

    public function request(): Request
    {
        return $this->httpRequest;
    }
}
