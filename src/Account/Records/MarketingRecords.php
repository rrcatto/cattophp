<?php

declare(strict_types=1);

namespace CattoPHP\Account\Records;

use CattoPHP\Account\ForgetsAccounts;
use CattoPHP\Marketing\Events;
use CattoPHP\Marketing\Sales\Sales;
use CattoPHP\Marketing\Visitors;
use Doctrine\DBAL\Connection;

/**
 * The Campaign Marketing Manager's part in forgetting an account: its visitors and their journey's events stay, with their
 * touches and the totals, naming nobody; its sales stay, with their money and campaigns, naming nobody; the campaigns, adverts, publications, costs, landing pages, their kept versions,
 * saved templates and connected sites it made stay too, no longer saying who made them.
 */
final class MarketingRecords implements ForgetsAccounts
{
    /** Each table with the columns that name who made or changed a record. */
    private const MADE_BY = ['cattophp_marketing_campaigns' => ['created_by'], 'cattophp_marketing_adverts' => ['created_by'], 'cattophp_marketing_publications' => ['created_by'],
        'cattophp_marketing_spend' => ['created_by'], 'cattophp_marketing_landing_pages' => ['created_by', 'updated_by'], 'cattophp_marketing_landing_versions' => ['made_by'],
        'cattophp_marketing_landing_templates' => ['made_by'], 'cattophp_marketing_sites' => ['created_by']];

    public function __construct(private readonly Visitors $visitors, private readonly Connection $connection, private readonly ?Events $events = null)
    {
    }

    public function forgetAccount(string $account, ?string $email): array
    {
        $made = 0;
        foreach (self::MADE_BY as $table => $columns) {
            foreach ($columns as $column) {
                $made += (int) $this->connection->executeStatement('UPDATE ' . $table . ' SET ' . $column . ' = NULL WHERE ' . $column . ' = :account', ['account' => $account]);
            }
        }
        $unnamed = ['campaign visitors' => $this->visitors->forgetAccount($account), 'campaign records made' => $made];
        if ($this->events !== null) {
            $unnamed['campaign journey events'] = $this->events->forgetAccount($account);
        }
        // Sales stay, with their money and campaigns, as financial records are kept: only their tie to the person goes, and
        // they are still counted as one customer.
        $sales = Sales::unname($this->connection, $account);
        if ($sales > 0) {
            $unnamed['campaign sales'] = $sales;
        }

        return ['removed' => [], 'unnamed' => $unnamed];
    }
}
