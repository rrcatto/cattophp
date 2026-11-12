<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Unit\Module;

use CattoPHP\Module\Modules;
use CattoPHP\Schema\FoundationSteps;
use CattoPHP\Schema\Steps\Step202611122300Inbox;
use PHPUnit\Framework\TestCase;

/** Each module declares what it needs, and a project is told plainly what stops one it switched on. */
final class ModulesTest extends TestCase
{
    public function testWhatStopsAModuleIsSaidPlainlyWithWhatToDo(): void
    {
        self::assertSame([], Modules::problems([], false), 'No module, nothing needed.');
        self::assertSame([], Modules::problems(['scheduler', 'protection', 'inbox', 'theme'], true));
        self::assertSame(['The Inbox (cattophp.inbox) keeps its records in the database: set cattophp.storage.type to database.'], Modules::problems(['inbox'], false));
        self::assertSame(['Site protection (cattophp.protection) needs the scheduler switched on, since its rules run every minute as the site_protection task: set cattophp.scheduler.enabled to true.'],
            Modules::problems(['protection'], true));
        $missing = static fn(string $class): bool => false;
        self::assertSame(['Documents and PDFs (cattophp.documents) needs dompdf/dompdf: run composer require dompdf/dompdf.'], Modules::problems(['documents'], true, $missing));
        self::assertSame(['Telegram (cattophp.telegram) keeps its records in the database: set cattophp.storage.type to database.', 'Telegram (cattophp.telegram) needs symfony/http-client: run composer require symfony/http-client.'],
            Modules::problems(['telegram'], false, $missing), 'Every problem at once.');
        self::assertSame([], Modules::problems(['no-such-module'], false));
    }

    public function testWhatAModuleWouldDoWithAnotherIsNoted(): void
    {
        self::assertSame(['The Inbox reads answers from the mailbox every minute with the scheduler switched on (cattophp.scheduler).'], Modules::notes(['inbox']));
        self::assertSame([], Modules::notes(['inbox', 'scheduler']));
    }

    public function testADatabaseIsGivenTheFoundationsTablesAndThoseOfTheModulesSwitchedOnInOrder(): void
    {
        self::assertSame(FoundationSteps::foundationClasses(), Modules::schemaSteps([]));
        $steps = Modules::schemaSteps(['inbox', 'scheduler']);
        self::assertSame([...FoundationSteps::foundationClasses(), 'CattoPHP\Schema\Steps\Step202611120900ScheduledJobs', Step202611122300Inbox::class], $steps, 'In the order they were written.');
        self::assertSame(['cattophp_inbox_conversations', 'cattophp_inbox_items', 'cattophp_inbox_spam_tokens', 'cattophp_inbox_spam_training', 'cattophp_inbox_blocked_senders', 'cattophp_inbox_mailbox'],
            Modules::get('inbox')?->tables());
        self::assertSame([], Modules::get('documents')?->tables());
    }

    public function testEveryStepBelongsToTheFoundationsOrToExactlyOneModule(): void
    {
        $owned = FoundationSteps::foundationClasses();
        foreach (Modules::all() as $key => $module) {
            self::assertSame($key, $module->key);
            foreach ($module->schemaSteps as $step) {
                self::assertNotContains($step, $owned, $step . ' belongs to one place only.');
                $owned[] = $step;
            }
        }
        $all = FoundationSteps::classes();
        sort($all);
        sort($owned);
        self::assertSame($all, $owned, 'Every step CattoPHP has is the foundations\' or a module\'s.');
    }
}
