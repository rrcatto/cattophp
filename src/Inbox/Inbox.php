<?php

declare(strict_types=1);

namespace CattoPHP\Inbox;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use CattoPHP\Foundation\Duration;
use CattoPHP\Http\FormStamp;
use CattoPHP\Http\VisitorAgent;
use CattoPHP\Network\NetworkLookup;
use CattoPHP\Network\NoNetworkLookup;
use CattoPHP\Network\ReputationLists;
use CattoPHP\Signup\MailDomainCheck;
use CattoPHP\Signup\SignupCountries;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The Inbox. A message from the contact form is a conversation: it is kept with its reference, judged by the spam check
 * when it arrives (the fixed signs and the Bayesian word filter, whose say grows with what it has learnt) and goes to the
 * Inbox or to Spam, where the sender is never told; Mark as spam and Not spam move a conversation and teach the filter;
 * Delete moves it to the Trash, Restore puts it back where it was, and Delete forever and emptying remove it for good; the
 * daily clean-up empties the Trash and Spam after their settings and removes conversations older than the keeping
 * setting. Every decision goes into the activity log. A project's contact form says what a message is about and shows
 * the pages; replies are InboxReplies, answers from the mailbox InboxMailbox.
 */
final class Inbox
{
    /** The contact form's hidden stamp of when it was opened, signed as the sign-up check's form is. */
    public const STAMP_FIELD = 'contact_opened';

    /** The filter's say at most, in points either way, once it has learnt enough. */
    public const FILTER_POINTS = 50;

    public function __construct(
        private readonly InboxStore $inbox,
        private readonly InboxSettings $settings,
        private readonly FormStamp $stamps,
        private readonly NetworkLookup $network = new NoNetworkLookup(),
        private readonly ?ReputationLists $lists = null,
        private readonly MailDomainCheck $mail = new MailDomainCheck(),
        private readonly SignupCountries $countries = new SignupCountries(),
        private readonly ?ActivityLog $activity = null,
        private readonly ?RequestStack $requests = null,
    ) {
    }

    /** When the contact form was opened, signed: its hidden field's value. */
    public function stamp(): string
    {
        return $this->stamps->stamp();
    }

    /**
     * Keeps a message from the contact form as a conversation with its reference. Judge it with arrived() next.
     *
     * @param array{reason:string,sender_name:string,sender_email:string,account?:?string,account_reference?:?string,details?:array<string,string>,message:string} $message
     * @return array{id:int,reference:string}
     */
    public function keep(array $message): array
    {
        return $this->inbox->transaction(fn(): array => $this->inbox->createConversation($message));
    }

    /**
     * Judges a message that has just come through the contact form, records what was found and files it: in the Inbox, or
     * in Spam at or above the line (never told to the sender), and opens its conversation with it.
     *
     * @param array<string,mixed> $form what the form sent
     * @return array{folder:string,score:int}
     */
    public function arrived(int $id, string $name, string $email, string $message, bool $signedIn, array $form): array
    {
        $ip = $this->address();
        $agent = $this->userAgent();
        $network = $this->network->facts($ip);
        $email = mb_strtolower(trim($email));
        $domain = (string) substr((string) strrchr($email, '@'), 1);
        $now = new DateTimeImmutable();
        $seconds = $this->stamps->secondsOpen(self::text($form[self::STAMP_FIELD] ?? ''));
        $judged = SpamSigns::judge([
            'text' => $message, 'name' => $name,
            'blocked_sender' => $this->inbox->isBlocked($email),
            'country_blocked' => $this->settings->holdsBlockedCountries() && $network->countryCode !== '' && in_array($network->countryCode, $this->countries->blocked(), true),
            'tor' => $network->tor, 'vpn' => $network->vpn, 'datacenter' => $network->hosting,
            'disposable' => $domain !== '' && ($this->lists?->isDisposable($domain) ?? false),
            'mail_domain_receives' => !$signedIn && $domain !== '' ? $this->mail->receivesMail($domain) : null,
            'seconds_on_page' => $seconds,
            'agent' => VisitorAgent::read($agent)->kind,
            'repeated' => $this->inbox->sameWordsSince($message, $now->modify('-30 days')->format(DATE_ATOM), $id),
            'from_address_today' => $this->inbox->fromAddressSince($ip, $now->modify('-1 day')->format(DATE_ATOM)),
            'signed_in' => $signedIn,
            'known_sender' => $this->inbox->knownSender($email, $id),
        ]);
        $signs = $judged['signs'];
        $filter = $this->filterSign(self::wordsOf($name, $email, $message));
        if ($filter !== null) $signs[] = $filter;
        $score = max(0, min(100, array_sum(array_column($signs, 'points'))));
        $never = in_array($email, $this->settings->neverSpam(), true) || ($domain !== '' && in_array($domain, $this->settings->neverSpam(), true));
        if ($never) $signs[] = ['key' => 'never_spam', 'points' => 0, 'words' => 'on your list of senders never sent to Spam'];
        $folder = !$never && $score >= $this->settings->spamLine() ? 'spam' : 'inbox';
        $this->inbox->transaction(function () use ($id, $score, $signs, $folder, $ip, $agent, $seconds, $network, $name, $email, $message): void {
            $this->inbox->recordCheck($id, $score, $signs, $folder, ['ip' => $ip, 'agent' => $agent, 'seconds' => $seconds, 'country' => $network->countryCode]);
            $this->inbox->addItem($id, ['direction' => 'received', 'kind' => 'message', 'status' => 'received', 'from_name' => $name, 'from_email' => $email, 'body_text' => $message, 'received_at' => (new DateTimeImmutable())->format(DATE_ATOM)]);
        });

        return ['folder' => $folder, 'score' => $score];
    }

    /** The acknowledgement sent to the sender, kept in the conversation and noted on it. */
    public function acknowledged(int $id, string $email, string $reference): void
    {
        $this->inbox->markAcknowledged($id);
        $this->inbox->addItem($id, ['direction' => 'sent', 'kind' => 'acknowledgement', 'status' => 'sent', 'to_email' => $email,
            'subject' => 'We received your message ' . $reference, 'body_text' => 'Our acknowledgement, with your reference ' . $reference . '.', 'sent_at' => (new DateTimeImmutable())->format(DATE_ATOM)]);
    }

    /** The message was emailed to the site's own address. */
    public function emailed(int $id): void
    {
        $this->inbox->markEmailed($id);
    }

    /**
     * The words the filter reads: the message, the sender's name and their email domain.
     *
     * @return list<string>
     */
    public static function wordsOf(string $name, string $email, string $message): array
    {
        $domain = (string) substr((string) strrchr(mb_strtolower($email), '@'), 1);
        $words = SpamFilter::words($name . "\n" . $message);
        if ($domain !== '') $words[] = 'from:' . $domain;

        return array_values(array_unique($words));
    }

    /**
     * Moves conversations to Spam and, unless switched off, teaches the filter that they are spam.
     *
     * @param list<int> $ids
     */
    public function markSpam(array $ids, string $actor): int
    {
        return $this->inbox->transaction(function () use ($ids, $actor): int {
            $moved = 0;
            foreach ($this->existing($ids) as $conversation) {
                $moved += $this->inbox->setFolder([(int) $conversation['id']], 'spam', 'spam');
                $this->learn($conversation, 'spam', $actor);
                $this->record('inbox.marked_spam', ['message_id' => (int) $conversation['id'], 'reference' => $conversation['reference']], $actor);
            }

            return $moved;
        });
    }

    /**
     * Moves conversations from Spam back to the Inbox and teaches the filter that they are genuine.
     *
     * @param list<int> $ids
     */
    public function notSpam(array $ids, string $actor): int
    {
        return $this->inbox->transaction(function () use ($ids, $actor): int {
            $moved = 0;
            foreach ($this->existing($ids) as $conversation) {
                $moved += $this->inbox->setFolder([(int) $conversation['id']], 'inbox', 'ham');
                $this->learn($conversation, 'ham', $actor);
                $this->record('inbox.marked_not_spam', ['message_id' => (int) $conversation['id'], 'reference' => $conversation['reference']], $actor);
            }

            return $moved;
        });
    }

    /** Blocks a conversation's sender (their address, or their whole domain) and sends it to Spam: what was blocked. */
    public function blockSender(int $id, string $kind, string $actor): string
    {
        $conversation = $this->inbox->conversation($id) ?? throw new InvalidArgumentException('That message does not exist.');
        $email = mb_strtolower((string) $conversation['sender_email']);
        $value = $kind === 'domain' ? (string) substr((string) strrchr($email, '@'), 1) : $email;
        if ($value === '') throw new InvalidArgumentException('That message has no address to block.');
        $this->inbox->transaction(function () use ($value, $kind, $actor): void {
            $this->inbox->block($value, $kind === 'domain' ? 'domain' : 'email', $actor);
            $this->record('inbox.sender_blocked', ['value' => $value, 'kind' => $kind], $actor);
        });
        if ($conversation['folder'] !== 'spam') $this->markSpam([$id], $actor);

        return $value;
    }

    /** @param list<int> $ids */
    public function trash(array $ids, string $actor): int
    {
        $moved = $this->inbox->moveToTrash(array_values(array_unique($ids)));
        if ($moved > 0) $this->record('inbox.trashed', ['count' => $moved], $actor);

        return $moved;
    }

    /** @param list<int> $ids */
    public function restore(array $ids, string $actor): int
    {
        $moved = $this->inbox->restore(array_values(array_unique($ids)));
        if ($moved > 0) $this->record('inbox.restored', ['count' => $moved], $actor);

        return $moved;
    }

    /** @param list<int> $ids */
    public function deleteForever(array $ids, string $actor): int
    {
        $gone = $this->inbox->deleteForever(array_values(array_unique($ids)));
        if ($gone > 0) $this->record('inbox.deleted', ['count' => $gone], $actor);

        return $gone;
    }

    public function emptyFolder(string $folder, string $actor): int
    {
        $gone = $this->inbox->emptyFolder($folder);
        if ($gone > 0) $this->record('inbox.emptied', ['folder' => $folder, 'count' => $gone], $actor);

        return $gone;
    }

    public function markAnswered(int $id, string $actor): void
    {
        if ($this->inbox->conversation($id) === null) throw new InvalidArgumentException('That message does not exist.');
        if ($this->inbox->markAnswered($id, $actor)) $this->record('contact.message_answered', ['message_id' => $id], $actor);
    }

    public function markUnanswered(int $id, string $actor): void
    {
        if ($this->inbox->conversation($id) === null) throw new InvalidArgumentException('That message does not exist.');
        if ($this->inbox->markUnanswered($id)) $this->record('contact.message_reopened', ['message_id' => $id], $actor);
    }

    /** Forgets everything the word filter learnt. */
    public function forget(string $actor): void
    {
        $this->inbox->forget();
        $this->record('inbox.filter_forgotten', [], $actor);
    }

    /**
     * The daily clean-up: the Trash and Spam emptied of what has been there longer than their settings, and conversations
     * older than the keeping setting removed.
     *
     * @return array{trash:int,spam:int,old:int}
     */
    public function cleanUp(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();

        return [
            'trash' => $this->inbox->emptyFolder('trash', $this->settings->trashKeptFor()->before($now)->format(DATE_ATOM)),
            'spam' => $this->inbox->emptyFolder('spam', $this->settings->spamKeptFor()->before($now)->format(DATE_ATOM)),
            'old' => $this->inbox->deleteOlderThan($this->settings->keptFor()->before($now)->format(DATE_ATOM)),
        ];
    }

    /**
     * Drafts deleted from Drafts: to the Trash.
     *
     * @param list<int> $ids
     */
    public function trashDrafts(array $ids, string $actor): int
    {
        $moved = 0;
        foreach (array_values(array_unique($ids)) as $id) $moved += $this->inbox->trashDraft($id) ? 1 : 0;
        if ($moved > 0) $this->record('inbox.drafts_trashed', ['count' => $moved], $actor);

        return $moved;
    }

    /** @param list<int> $ids */
    public function restoreDrafts(array $ids, string $actor): int
    {
        $moved = $this->inbox->restoreDrafts(array_values(array_unique($ids)));
        if ($moved > 0) $this->record('inbox.drafts_restored', ['count' => $moved], $actor);

        return $moved;
    }

    /** @param list<int> $ids */
    public function deleteDraftsForever(array $ids, string $actor): int
    {
        $gone = $this->inbox->deleteDraftsForever(array_values(array_unique($ids)));
        if ($gone > 0) $this->record('inbox.drafts_deleted', ['count' => $gone], $actor);

        return $gone;
    }

    /** When something put away at a time is deleted for good by the daily clean-up: "tonight", "in 4 days". */
    public function goneFor(string $since, Duration $kept): string
    {
        $due = (new DateTimeImmutable($since))->modify('+' . $kept->lengthInSeconds() . ' seconds');
        $days = (int) floor(($due->getTimestamp() - time()) / 86400);

        return $days < 1 ? 'tonight' : ($days === 1 ? 'in 1 day' : 'in ' . $days . ' days');
    }

    /** @return array{trash:string,spam:string,line:int} the keeping settings in words, for the folders' leads */
    public function keeping(): array
    {
        return ['trash' => $this->settings->trashKeptFor()->words(), 'spam' => $this->settings->spamKeptFor()->words(), 'line' => $this->settings->spamLine()];
    }

    /** @return array{new:int,drafts:int,spam:int,trash:int} */
    public function counts(): array
    {
        return $this->inbox->counts();
    }

    /** @return array{spam:int,ham:int} */
    public function trained(): array
    {
        return $this->inbox->trained();
    }

    public function markRead(int $id): void
    {
        $this->inbox->markRead($id);
    }

    public function idByReference(string $reference): ?int
    {
        return $this->inbox->idByReference($reference);
    }

    public function settings(): InboxSettings
    {
        return $this->settings;
    }

    /**
     * What the word filter says of these words, as a sign: points either way, up to FILTER_POINTS once it has learnt from
     * enough of each kind; nothing while it knows nothing.
     *
     * @param list<string> $words
     * @return array{key:string,points:int,words:string}|null
     */
    private function filterSign(array $words): ?array
    {
        $trained = $this->inbox->trained();
        $confidence = SpamFilter::confidence($trained['spam'], $trained['ham']);
        if ($confidence <= 0.0) return null;
        $chance = SpamFilter::probability($words, $this->inbox->wordCounts($words), $trained['spam'], $trained['ham']);
        $points = (int) round(($chance - 0.5) * 2 * self::FILTER_POINTS * $confidence);
        if ($points === 0) return null;

        return ['key' => 'filter', 'points' => $points, 'words' => 'the word filter: ' . (int) round($chance * 100) . '% likely spam'];
    }

    /** @param array<string,mixed> $conversation */
    private function learn(array $conversation, string $verdict, string $actor): void
    {
        if (!$this->settings->learns()) return;
        $this->inbox->train((int) $conversation['id'], $verdict, self::wordsOf((string) $conversation['sender_name'], (string) $conversation['sender_email'], (string) $conversation['message']), $actor);
    }

    /**
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    private function existing(array $ids): array
    {
        $found = [];
        foreach (array_values(array_unique($ids)) as $id) {
            $conversation = $this->inbox->conversation($id);
            if ($conversation !== null) $found[] = $conversation;
        }

        return $found;
    }

    /** @param array<string,mixed> $details */
    private function record(string $action, array $details, string $actor): void
    {
        $this->activity?->record($action, $details, actor: $actor !== '' && $actor !== '0' ? new Actor($actor, $actor) : Actor::system());
    }

    /** The web server's REMOTE_ADDR, never a forwarded header. */
    private function address(): string
    {
        $address = trim(self::text($this->server()['REMOTE_ADDR'] ?? ''));

        return filter_var($address, FILTER_VALIDATE_IP) !== false ? $address : '';
    }

    private function userAgent(): string
    {
        return mb_substr(trim(self::text($this->server()['HTTP_USER_AGENT'] ?? 'Unknown browser')), 0, 500);
    }

    /** @return array<string,mixed> the request's server values, or the process's outside a request */
    private function server(): array
    {
        $request = $this->requests?->getCurrentRequest();

        return $request !== null ? $request->server->all() : $_SERVER;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
