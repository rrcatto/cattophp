<?php

declare(strict_types=1);

namespace CattoPHP\Inbox;

use DateTimeImmutable;
use Throwable;

/**
 * Answers from the mailbox: with a mailbox set in the Inbox settings, the new messages are read (ImapMailbox), each matched
 * to its conversation by the Message-IDs it answers, else the reference in its subject, and filed as an answer, once; the
 * conversation comes to the top with "Answer received", not answered until replied to, and back in the Inbox if it was in
 * the Trash. Automatic replies (out of office) and mail matching no conversation are left alone in the mailbox.
 */
final class InboxMailbox
{
    public function __construct(
        private readonly InboxSettings $settings,
        private readonly InboxStore $inbox,
        private readonly ImapMailbox $mailbox = new ImapMailbox(),
    ) {
    }

    public function run(): string
    {
        if (!$this->settings->mailboxSet()) return 'No mailbox is set in Inbox settings.';
        // Only a connection Check the connection established is read: details never tried, or a mailbox that refused,
        // are not tried again every minute (a mail server's own protection bans an address that keeps failing to sign
        // in), until a check works: a connection exists only once a real try made it.
        if ($this->status()['state'] !== 'connected') return 'The mailbox is not connected: Check the connection in Inbox settings connects it.';
        $state = $this->inbox->mailboxState();
        try {
            $found = $this->mailbox->newMessages($this->settings->mailbox(), $state);
        } catch (Throwable $failed) {
            $this->inbox->saveMailboxState(['checked_at' => (new DateTimeImmutable())->format(DATE_ATOM), 'error' => mb_substr($failed->getMessage(), 0, 300), 'connected' => false] + $state);

            return 'The mailbox could not be read: ' . $failed->getMessage();
        }
        $filed = $skipped = 0;
        foreach ($found['messages'] as $message) {
            $this->file(MimeMessage::parse($message['raw'])) ? $filed++ : $skipped++;
        }
        $this->inbox->saveMailboxState($found['state'] + ['checked_at' => (new DateTimeImmutable())->format(DATE_ATOM), 'error' => '', 'connected' => true]);
        if (!isset($state['validity'])) return 'The mailbox is connected; answers arriving from now on come into their conversations.';

        return ($filed === 1 ? '1 answer' : $filed . ' answers') . ' filed' . ($skipped > 0 ? ', ' . $skipped . ' other ' . ($skipped === 1 ? 'email' : 'emails') . ' left alone' : '') . '.';
    }

    /** Files one email as an answer, once: false when it answers no conversation or is an automatic reply. */
    public function file(MimeMessage $email): bool
    {
        if ($email->automatic) return false;
        $id = null;
        foreach ($email->answers as $answered) {
            $id = $this->inbox->conversationFor($answered, '');
            if ($id !== null) break;
        }
        $id ??= $this->inbox->conversationFor(null, $email->subject);
        if ($id === null) return false;
        $messageId = $email->messageId !== '' ? $email->messageId : 'inbox.received.' . hash('sha256', $email->fromEmail . $email->subject . $email->text) . '@local';
        if ($this->inbox->hasItemWithMessageId($messageId)) return false;
        $this->inbox->addItem($id, ['direction' => 'received', 'kind' => 'answer', 'status' => 'received', 'subject' => mb_substr($email->subject, 0, 300),
            'from_name' => mb_substr($email->fromName, 0, 200), 'from_email' => mb_substr($email->fromEmail, 0, 254), 'body_text' => mb_substr($email->text, 0, 100000),
            'attachments' => array_map(static fn(string $name): array => ['type' => 'named', 'name' => mb_substr($name, 0, 200)], $email->attachments),
            'message_id' => $messageId, 'in_reply_to' => $email->answers[0] ?? null, 'received_at' => $email->date ?? (new DateTimeImmutable())->format(DATE_ATOM)]);
        $this->inbox->answerArrived($id);

        return true;
    }

    /**
     * Check the connection: signs in and opens the mailbox, and records what happened, as a read does, so the settings
     * page shows the outcome of the last try. Answers the words to show; throws with what went wrong, recorded too.
     */
    public function check(): string
    {
        $state = $this->inbox->mailboxState();
        $now = (new DateTimeImmutable())->format(DATE_ATOM);
        try {
            $words = $this->mailbox->check($this->settings->mailbox());
        } catch (Throwable $failed) {
            $this->inbox->saveMailboxState(['checked_at' => $now, 'error' => mb_substr($failed->getMessage(), 0, 300), 'connected' => false] + $state);
            throw $failed;
        }
        $this->inbox->saveMailboxState(['checked_at' => $now, 'error' => '', 'connected' => true] + $state);

        return $words;
    }

    /**
     * After the settings are saved: when the mailbox's details differ from $before, what was found with the old ones no
     * longer says anything, so the state goes back to not checked (where the old mailbox stood is kept: a different
     * mailbox has a different UIDVALIDITY, which starts it afresh).
     *
     * @param array{host:string,port:int,security:string,user:string,password:string} $before
     */
    public function detailsSaved(array $before): void
    {
        if ($before === $this->settings->mailbox()) {
            return;
        }
        $state = $this->inbox->mailboxState();
        unset($state['checked_at'], $state['error'], $state['connected']);
        $this->inbox->saveMailboxState($state);
    }

    /**
     * Where the mailbox stands, from the last try (Check the connection, or the minute's read of a connected mailbox):
     * 'none' with no mailbox set, 'unchecked' before any check with these details, 'connected' when the last try worked,
     * 'failed' when it did not; with when it was last tried and what went wrong. Only 'connected' is read.
     *
     * @return array{state:string,checked_at:string,error:string}
     */
    public function status(): array
    {
        $state = $this->inbox->mailboxState();
        $checked = is_scalar($state['checked_at'] ?? null) ? (string) $state['checked_at'] : '';
        $error = is_scalar($state['error'] ?? null) ? (string) $state['error'] : '';
        // A state recorded before 'connected' was kept says so by a read that worked (its validity).
        $connected = ($state['connected'] ?? null) === true
            || (!array_key_exists('connected', $state) && $checked !== '' && $error === '' && isset($state['validity']));

        return [
            'state' => match (true) {
                !$this->settings->mailboxSet() => 'none',
                $error !== '' => 'failed',
                $connected => 'connected',
                default => 'unchecked',
            },
            'checked_at' => $checked,
            'error' => $error,
        ];
    }

    /** Drop connection: the mailbox forgotten, and where it stood, so a mailbox set later starts afresh. */
    public function forget(): void
    {
        $this->inbox->forgetMailboxState();
    }
}
