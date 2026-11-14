<?php

declare(strict_types=1);

namespace CattoPHP\Schema\Steps;

use CattoPHP\Schema\SqlStep;

/**
 * A forgotten customer is still one customer: when an account is forgotten, its sales stop naming it and are given one
 * random mark together instead (Sales::forgetAccount), used only to count them as one paying customer. The mark is made
 * at that moment, from nothing about the person, so it names nobody and cannot be matched to an account.
 */
final class Step202611141500MarketingForgottenCustomers extends SqlStep
{
    public function id(): string
    {
        return '202611141500-marketing-forgotten-customers';
    }

    public function describe(): string
    {
        return 'The Campaign Marketing Manager: a forgotten customer\'s sales counted as one customer, naming nobody';
    }

    protected function statements(): array
    {
        return [
            'ALTER TABLE cattophp_marketing_sales ADD COLUMN forgotten_customer varchar(32) NULL CHECK (forgotten_customer IS NULL OR forgotten_customer ~ \'^[0-9a-f]{32}$\')',
        ];
    }
}
