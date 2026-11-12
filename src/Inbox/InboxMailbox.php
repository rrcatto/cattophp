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
        $state = $this->inbox->mailboxState();
        try {
            $found = $this->mailbox->newMessages($this->settings->mailbox(), $state);
        } catch (Throwable $failed) {
            $this->inbox->saveMailboxState(['checked_at' => (new DateTimeImmutable())->format(DATE_ATOM), 'error' => mb_substr($failed->getMessage(), 0, 300)] + $state);

            return 'The mailbox could not be read: ' . $failed->getMessage();
        }
        $filed = $skipped = 0;
        foreach ($found['messages'] as $message) {
            $this->file(MimeMessage::parse($message['raw'])) ? $filed++ : $skipped++;
        }
        $this->inbox->saveMailboxState($found['state'] + ['checked_at' => (new DateTimeImmutable())->format(DATE_ATOM), 'error' => '']);
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

    /** @return array{checked_at:string,error:string} when the mailbox was last read, and what went wrong if it did */
    public function status(): array
    {
        $state = $this->inbox->mailboxState();

        return ['checked_at' => is_scalar($state['checked_at'] ?? null) ? (string) $state['checked_at'] : '', 'error' => is_scalar($state['error'] ?? null) ? (string) $state['error'] : ''];
    }

    /** Disconnect: the mailbox forgotten, and where it stood, so a mailbox set later starts afresh. */
    public function forget(): void
    {
        $this->inbox->forgetMailboxState();
    }
}
