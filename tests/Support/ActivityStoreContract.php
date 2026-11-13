<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use CattoPHP\Activity\ActivityEntry;
use CattoPHP\Activity\ActivityStore;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;

/**
 * What every activity store does, the database's, a file's and memory's alike: a page at a time, counted, and its
 * actions; and forgetting an account wherever an entry names it.
 */
final class ActivityStoreContract
{
    /**
     * Review finding 6: an account forgotten is named nowhere in the log, not only where it did something or was the
     * subject: its id as a detail's value (under a key that names an account, at any depth, in a list), and each word that
     * names it (its address, its name, an identity check's session, its Telegram username with or without its @) wherever
     * it stands in what an entry says, whatever its case, never inside a longer word or address. Those entries keep no
     * network address; an entry naming someone else, and a number that is no account's, stay as they were.
     */
    public static function forgets(ActivityStore $store, string $kind): void
    {
        $at = new DateTimeImmutable('2026-11-09 10:00:00');
        $add = static function (string $action, ?string $actor, array $details, ?string $subjectType = null, ?string $subjectId = null) use ($store, $at): void {
            $store->add(new ActivityEntry($at, $action, $actor, $actor === null ? 'The system' : 'Olive Owner', $subjectType, $subjectId, $details, '203.0.113.9', 'reference'));
        };
        $add('identity.verification_declined', null, ['account' => 2, 'session_id' => 'session-2', 'reason' => 'The photo of Anna Example was blurred.']);
        $add('telegram.connected', '1', ['account_id' => '2', 'username' => 'anna_example', 'note' => 'Connected as @anna_example.']);
        $add('settings.changed', '1', ['setting' => 'AUTH_SIGNIN_ALLOWED_EMAILS', 'old' => 'owner@example.com, Anna@Example.com, hanna@example.com', 'new' => 'owner@example.com'], 'setting', 'AUTH_SIGNIN_ALLOWED_EMAILS');
        $add('example.nested', '1', ['people' => [['account' => '2', 'emails' => ['anna@example.com', 'anna@example.com.au']], ['account' => '1']]]);
        $add('example.olive_only', '1', ['count' => 2, 'account_id' => '1', 'email' => 'owner@example.com']);

        $changed = $store->forgetAccount('2', 'A deleted account', ['anna@example.com', 'Anna Example', 'session-2', 'anna_example', '@anna_example']);

        Assert::assertSame(4, $changed, $kind . ': four entries named her');
        $by = [];
        foreach ($store->recent(10) as $entry) {
            $by[$entry->action] = $entry;
        }
        $forgotten = 'A deleted account';
        Assert::assertSame(self::sorted(['account' => $forgotten, 'session_id' => $forgotten, 'reason' => 'The photo of ' . $forgotten . ' was blurred.']), self::sorted($by['identity.verification_declined']->details), $kind . ': by the system');
        Assert::assertSame(self::sorted(['account_id' => $forgotten, 'username' => $forgotten, 'note' => 'Connected as ' . $forgotten . '.']), self::sorted($by['telegram.connected']->details), $kind . ': her id and her username, with or without its @');
        Assert::assertSame(self::sorted(['setting' => 'AUTH_SIGNIN_ALLOWED_EMAILS', 'old' => 'owner@example.com, ' . $forgotten . ', hanna@example.com', 'new' => 'owner@example.com']), self::sorted($by['settings.changed']->details), $kind . ': inside a list, another address that holds hers left alone');
        Assert::assertSame(self::sorted(['people' => [['account' => $forgotten, 'emails' => [$forgotten, 'anna@example.com.au']], ['account' => '1']]]), self::sorted($by['example.nested']->details), $kind . ': at any depth');
        foreach (['identity.verification_declined', 'telegram.connected', 'settings.changed', 'example.nested'] as $action) {
            Assert::assertNull($by[$action]->ipAddress, $kind . ': ' . $action . ' keeps no network address');
            Assert::assertSame('reference', $by[$action]->requestReference, $kind);
        }
        Assert::assertSame(['1', 'Olive Owner'], [$by['telegram.connected']->actorId, $by['telegram.connected']->actorName], $kind . ': still Olive\'s doing');
        Assert::assertSame(self::sorted(['count' => 2, 'account_id' => '1', 'email' => 'owner@example.com']), self::sorted($by['example.olive_only']->details), $kind . ': Olive\'s, and a number that is no account, as they were');
        Assert::assertSame('203.0.113.9', $by['example.olive_only']->ipAddress, $kind);
    }

    /**
     * Details with every level's keys in order, the database keeping a record's keys in its own order.
     *
     * @param array<mixed> $details
     * @return array<mixed>
     */
    private static function sorted(array $details): array
    {
        if (!array_is_list($details)) {
            ksort($details);
        }

        return array_map(static fn (mixed $value): mixed => is_array($value) ? self::sorted($value) : $value, $details);
    }

    public static function pages(ActivityStore $store, string $kind): void
    {
        $site = new Foundations(activityStore: $store);
        foreach (range(1, 7) as $number) {
            $site->activity->record($number % 3 === 0 ? 'settings.changed' : 'sign_in.signed_in', ['number' => $number], 'account', $number % 2 === 0 ? 'even' : 'odd');
            $site->clock->modify('+1 second');
        }
        $site->activity->record('access.role_given');
        $numbers = static fn (array $entries): array => array_map(static fn (ActivityEntry $entry): mixed => $entry->details['number'] ?? null, $entries);

        Assert::assertSame([7, 6, 5], $numbers($store->recent(3, offset: 1)), $kind . ': the second entry on, three of them');
        Assert::assertSame([5, 4, 2], $numbers($store->recent(3, 'sign_in.signed_in', offset: 1)), $kind . ': one action, from its second entry on');
        Assert::assertSame([], $store->recent(3, 'sign_in.signed_in', offset: 50), $kind . ': past the end, nothing');
        Assert::assertSame(8, $store->count(), $kind);
        Assert::assertSame(5, $store->count('sign_in.signed_in'), $kind);
        Assert::assertSame(3, $store->count('sign_in.signed_in', 'account', 'odd'), $kind . ': narrowed as recent() narrows');
        Assert::assertSame(0, $store->count('no.such_action'), $kind);
        Assert::assertSame(['access.role_given', 'settings.changed', 'sign_in.signed_in'], $store->actions(), $kind . ': each action present once, in order');
    }
}
