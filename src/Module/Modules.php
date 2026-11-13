<?php

declare(strict_types=1);

namespace CattoPHP\Module;

use CattoPHP\Schema\FoundationSteps;
use CattoPHP\Schema\SchemaStep;
use CattoPHP\Schema\Steps\Step202611120900ScheduledJobs;
use CattoPHP\Schema\Steps\Step202611130300ScheduledJobTrials;
use CattoPHP\Schema\Steps\Step202611121400SetupSteps;
use CattoPHP\Schema\Steps\Step202611121600SiteProtection;
use CattoPHP\Schema\Steps\Step202611121800Telegram;
use CattoPHP\Schema\Steps\Step202611122000SearchPages;
use CattoPHP\Schema\Steps\Step202611122100ApiTokens;
use CattoPHP\Schema\Steps\Step202611122200SignupAttempts;
use CattoPHP\Schema\Steps\Step202611122201IdentityChecks;
use CattoPHP\Schema\Steps\Step202611130100IdentityConnection;
use CattoPHP\Schema\Steps\Step202611130200Connections;
use CattoPHP\Schema\Steps\Step202611122300Inbox;
use CattoPHP\Schema\Steps\Step202611122310CattoMail;
use CattoPHP\Schema\Steps\Step202611122320MailingLists;
use CattoPHP\Schema\Steps\Step202611122330MailingCampaigns;
use CattoPHP\Schema\Steps\Step202611122340MailingForwards;

/**
 * Every optional module and what it needs, in one place. A project switches a module on in its configuration
 * (`cattophp.<key>.enabled: true`); a module switched off costs nothing: no services, no settings, no tasks, and, here,
 * no tables. When the project starts, what the modules switched on need is checked (the database, the modules beside them,
 * their packages) and each problem is said plainly. The foundations (settings, the activity log, roles and permissions,
 * sign-in, mail, error pages, security headers, the components) are always there.
 */
final class Modules
{
    private const HTTP_CLIENT = ['Symfony\Component\HttpClient\HttpClient' => 'symfony/http-client'];

    /** @return array<string,Module> every module, by its key, in the order the documentation lists them */
    public static function all(): array
    {
        $modules = [
            new Module('scheduler', 'The scheduler', 'Background work: tasks on a timetable, each run recorded, and the nightly log archive.',
                schemaSteps: [Step202611120900ScheduledJobs::class, Step202611130300ScheduledJobTrials::class]),
            new Module('setup', 'The setup checklist', 'The steps a new installation needs, checked automatically or marked by a person.',
                database: true, schemaSteps: [Step202611121400SetupSteps::class]),
            new Module('protection', 'Site protection', 'The Site Log, flagged addresses, time-outs and lockouts, the form guard and the link to a server firewall.',
                database: true, needs: ['scheduler' => 'its rules run every minute as the site_protection task'], schemaSteps: [Step202611121600SiteProtection::class]),
            new Module('telegram', 'Telegram', 'Messages to the owner and to people through a Telegram bot, and their connected accounts.',
                database: true, usesIfOn: ['scheduler' => 'sends the owner\'s messages every minute'], packages: self::HTTP_CLIENT, schemaSteps: [Step202611121800Telegram::class]),
            new Module('api', 'An API', 'Tokens issued to accounts with scopes, Bearer sign-in and one JSON error document.',
                database: true, schemaSteps: [Step202611122100ApiTokens::class]),
            new Module('documents', 'Documents and PDFs', 'Templates in a controlled language, one renderer, and PDFs through Dompdf.',
                packages: ['Dompdf\Dompdf' => 'dompdf/dompdf']),
            new Module('search', 'Search engines and AI', 'robots.txt, the sitemap, llms.txt, share pictures, structured data and every public page\'s head.',
                database: true, schemaSteps: [Step202611122000SearchPages::class]),
            new Module('signup', 'The sign-up check', 'Each registration judged and recorded, and the countries a registration is allowed from and held from.',
                database: true, usesIfOn: ['scheduler' => 'fetches the Tor, VPN, hosting and throwaway email lists every day'], schemaSteps: [Step202611122200SignupAttempts::class]),
            new Module('identity', 'Identity verification', 'Checks on Didit\'s hosted page, their result by webhook or on the person\'s return.',
                database: true, packages: self::HTTP_CLIENT, schemaSteps: [Step202611122201IdentityChecks::class, Step202611130100IdentityConnection::class, Step202611130200Connections::class]),
            new Module('inbox', 'The Inbox', 'A contact form\'s messages as conversations: the spam check, folders, replies and answers from a mailbox.',
                database: true, usesIfOn: ['scheduler' => 'reads answers from the mailbox every minute'], schemaSteps: [Step202611122300Inbox::class]),
            new Module('mailing', 'Mailing', 'Lists and who agreed to them, campaigns and each person\'s copy, the sender, the addresses never emailed, and a mail server or catto-mail.',
                database: true, packages: self::HTTP_CLIENT, schemaSteps: [Step202611122310CattoMail::class, Step202611122320MailingLists::class, Step202611122330MailingCampaigns::class, Step202611122340MailingForwards::class]),
            new Module('payments', 'Payments', 'Payment gateways through Omnipay, each one\'s keys in Settings, and each one\'s connection from its last check.',
                packages: ['Omnipay\Common\GatewayFactory' => 'omnipay/common'], schemaSteps: [Step202611130200Connections::class]),
            new Module('theme', 'Themes', 'Theme packages checked, installed, chosen and previewed, and the page renderer.'),
        ];
        $byKey = [];
        foreach ($modules as $module) {
            $byKey[$module->key] = $module;
        }

        return $byKey;
    }

    public static function get(string $key): ?Module
    {
        return self::all()[$key] ?? null;
    }

    /**
     * What stops the modules switched on from working, each in plain words with what to do; nothing when all is well.
     *
     * @param list<string> $on the keys of the modules switched on
     * @param callable(string):bool|null $exists whether a class or interface can be loaded (class_exists by default)
     * @return list<string>
     */
    public static function problems(array $on, bool $database, ?callable $exists = null): array
    {
        $exists ??= static fn(string $class): bool => class_exists($class) || interface_exists($class);
        $problems = [];
        foreach ($on as $key) {
            $module = self::get($key);
            if ($module === null) {
                continue;
            }
            $name = $module->label . ' (cattophp.' . $module->key . ')';
            if ($module->database && !$database) {
                $problems[] = $name . ' keeps its records in the database: set cattophp.storage.type to database.';
            }
            foreach ($module->needs as $needed => $why) {
                if (!in_array($needed, $on, true)) {
                    $problems[] = $name . ' needs ' . self::named($needed) . ' switched on, since ' . $why . ': set cattophp.' . $needed . '.enabled to true.';
                }
            }
            foreach ($module->packages as $class => $package) {
                if (!$exists($class)) {
                    $problems[] = $name . ' needs ' . $package . ': run composer require ' . $package . '.';
                }
            }
        }

        return $problems;
    }

    /**
     * What the modules switched on would do with another module that is off, for the list of modules.
     *
     * @param list<string> $on
     * @return list<string>
     */
    public static function notes(array $on): array
    {
        $notes = [];
        foreach ($on as $key) {
            foreach (self::get($key)->usesIfOn ?? [] as $other => $what) {
                if (!in_array($other, $on, true)) {
                    $notes[] = (self::get($key)->label ?? $key) . ' ' . $what . ' with ' . self::named($other) . ' switched on (cattophp.' . $other . ').';
                }
            }
        }

        return $notes;
    }

    /** A module's name in the middle of a sentence: "the scheduler", "Telegram". */
    public static function named(string $key): string
    {
        $label = self::get($key)->label ?? $key;

        return str_starts_with($label, 'The ') ? 'the ' . substr($label, 4) : $label;
    }

    /**
     * The schema steps a project's database is given: the foundations', then each module's that is switched on, in the
     * order they were written. A module switched on later is given its steps at the next migrate; one switched off keeps
     * the tables it made, and its data.
     *
     * @param list<string> $on
     * @return list<class-string<SchemaStep>>
     */
    public static function schemaSteps(array $on): array
    {
        $steps = FoundationSteps::foundationClasses();
        foreach (self::all() as $module) {
            if (in_array($module->key, $on, true)) {
                $steps = [...$steps, ...$module->schemaSteps];
            }
        }
        // A step two modules share (the connections' table) is given once.
        $steps = array_values(array_unique($steps));
        usort($steps, static fn(string $a, string $b): int => strcmp((string) preg_replace('/^.*Step(\d+).*$/', '$1', $a), (string) preg_replace('/^.*Step(\d+).*$/', '$1', $b)));

        return $steps;
    }
}
