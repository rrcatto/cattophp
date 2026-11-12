<?php

declare(strict_types=1);

namespace CattoPHP\Inbox;

use CattoPHP\Activity\ActivityLog;
use CattoPHP\Activity\Actor;
use CattoPHP\Installation\Installation;
use CattoPHP\Settings\EnvironmentValues;
use CattoPHP\Settings\Settings;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Replies from the Inbox. A reply is written in plain text or HTML, starts with the signature for its format, may carry
 * files and the account's own documents (InboxDocuments), and is saved as a draft or sent. It is sent from the Inbox's own
 * sender where the settings name one, with its reply-to, a Message-ID the answer will name and, where an answer came, the
 * Message-ID it answers, so answers thread into the conversation; its subject carries the conversation's reference too.
 * An HTML reply always has a plain text copy. Sent, it marks the message answered when ticked.
 */
final class InboxReplies
{
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;
    public const MAX_ATTACHMENTS = 10;
    public const MAX_BODY = 100000;

    /**
     * @param string $filesFolder where a reply's files are kept, each under inbox/<the reply's public id>/
     */
    public function __construct(
        private readonly InboxStore $inbox,
        private readonly InboxSettings $settings,
        private readonly Settings $site,
        private readonly Installation $installation,
        private readonly ReplySender $sender,
        private readonly string $filesFolder,
        private readonly ?InboxDocuments $documents = null,
        private readonly ?ActivityLog $activity = null,
        private readonly EnvironmentValues $environment = new EnvironmentValues(),
    ) {
    }

    /**
     * What the reply form shows for a conversation: to whom, from what, the subject, the format and the words (the draft
     * being written, else the signature for each format), its attachments and the person's documents to attach.
     *
     * @param array<string,mixed> $conversation the conversation as its page shows it: id, reference, reason_label, sender_name, sender_email and account
     * @return array<string,mixed>
     */
    public function compose(array $conversation): array
    {
        $draft = $this->inbox->draftOf((int) $conversation['id']);
        $format = $draft !== null ? (string) $draft['format'] : $this->settings->replyFormat();
        $signatureText = $this->settings->signature('plain');
        $signatureHtml = InboxHtml::clean($this->settings->signature('html'));
        $attachments = $draft !== null ? (json_decode((string) $draft['attachments'], true) ?: []) : [];
        $chosen = array_column(array_filter($attachments, static fn(array $a): bool => ($a['type'] ?? '') === 'document'), 'id');
        return [
            'draft_id' => $draft !== null ? (int) $draft['id'] : null,
            'draft_public_id' => $draft !== null ? (string) $draft['public_id'] : '',
            'to' => ['name' => (string) $conversation['sender_name'], 'email' => (string) $conversation['sender_email']],
            'from' => $this->fromLine(),
            'reply_to' => $this->replyTo(),
            'subject' => $draft !== null ? (string) $draft['subject'] : self::subject((string) $conversation['reference'], (string) $conversation['reason_label']),
            'format' => $format,
            'body_text' => $draft !== null && $format === 'plain' ? (string) $draft['body_text'] : ($signatureText !== '' ? "\n\n" . $signatureText : ''),
            'body_html' => $draft !== null && $format === 'html' ? InboxHtml::clean((string) ($draft['body_html'] ?? '')) : ($signatureHtml !== '' ? '<p>&nbsp;</p>' . $signatureHtml : ''),
            'attachments' => array_map(static fn(array $a, int|string $i): array => ['index' => (int) $i, 'name' => (string) ($a['name'] ?? ''), 'type' => (string) ($a['type'] ?? '')] + (isset($a['size']) ? ['size' => self::size((int) $a['size'])] : []), $attachments, array_keys($attachments)),
            'documents' => array_map(static fn(array $d): array => $d + ['ticked' => in_array((int) $d['id'], array_map('intval', $chosen), true)], $this->documentsOf(self::account($conversation))),
            'mark_answered' => $draft !== null ? (bool) $draft['mark_answered'] : true,
            'saved' => $draft !== null ? (new DateTimeImmutable((string) $draft['updated_at']))->format('j M Y H:i') : '',
            'max_size' => self::size(self::MAX_FILE_BYTES),
        ];
    }

    /** A reply's subject: what the message was about, with its reference so an answer finds its conversation. */
    public static function subject(string $reference, string $reason): string
    {
        return 'Re: ' . $reason . ' [' . $reference . ']';
    }

    /**
     * Saves the reply being written as the conversation's draft and, when asked, sends it.
     *
     * @param array<string,mixed> $input the form: format, subject, body_text, body_html, documents[], remove[], mark_answered
     * @param list<UploadedFile> $files files added
     * @return array{sent:bool,item_id:int,error:string}
     */
    public function save(int $conversationId, array $input, array $files, string $actor, bool $send): array
    {
        $conversation = $this->inbox->conversation($conversationId) ?? throw new InvalidArgumentException('That message does not exist.');
        $format = ($input['format'] ?? '') === 'plain' ? 'plain' : 'html';
        $subject = trim(str_replace(["\r", "\n"], ' ', (string) ($input['subject'] ?? '')));
        if ($subject === '') throw new InvalidArgumentException('Give the reply a subject.');
        if (mb_strlen($subject) > 200) throw new InvalidArgumentException('The subject is longer than 200 characters.');
        $html = $format === 'html' ? InboxHtml::clean((string) ($input['body_html'] ?? '')) : null;
        $text = $format === 'html' ? InboxHtml::plain((string) $html) : rtrim(str_replace("\r\n", "\n", (string) ($input['body_text'] ?? '')));
        if (mb_strlen($text) > self::MAX_BODY || ($html !== null && strlen($html) > self::MAX_BODY * 2)) throw new InvalidArgumentException('The reply is too long.');
        if ($send && trim($text) === '') throw new InvalidArgumentException('The reply is empty: write it before sending.');

        $draft = $this->inbox->draftOf($conversationId);
        $itemId = $draft !== null ? (int) $draft['id'] : $this->inbox->addItem($conversationId, ['direction' => 'sent', 'kind' => 'reply', 'status' => 'draft', 'format' => $format, 'subject' => $subject, 'to_email' => (string) $conversation['sender_email'], 'written_by' => $actor]);
        $item = $this->inbox->item($itemId) ?? throw new \RuntimeException('The draft could not be kept.');
        $attachments = $this->attachments($item, $input, $files, self::account($conversation));
        $this->inbox->updateItem($itemId, ['format' => $format, 'subject' => $subject, 'body_text' => $text, 'body_html' => $html, 'attachments' => $attachments,
            'mark_answered' => ($input['mark_answered'] ?? '') === '1', 'to_email' => (string) $conversation['sender_email']]);
        if (!$send) return ['sent' => false, 'item_id' => $itemId, 'error' => ''];

        $messageId = 'inbox.' . $item['public_id'] . '@' . $this->messageDomain();
        $email = (new Email())->to(new Address((string) $conversation['sender_email'], (string) $conversation['sender_name']))->subject($subject)->text($text);
        if ($html !== null) $email->html(InboxHtml::document($html, $subject));
        $sender = $this->settings->sender();
        if ($sender['address'] !== '') $email->from(new Address($sender['address'], $sender['name'] !== '' ? $sender['name'] : $this->installation->name()));
        if ($this->replyTo() !== '') $email->replyTo($this->replyTo());
        $email->getHeaders()->addIdHeader('Message-ID', $messageId);
        $answers = $this->inbox->answerMessageIds($conversationId);
        if ($answers !== []) {
            $email->getHeaders()->addIdHeader('In-Reply-To', (string) $answers[0]);
            $email->getHeaders()->addIdHeader('References', array_map('strval', array_reverse($answers)));
        }
        foreach ($attachments as $attachment) {
            [$body, $name, $type] = $this->attachmentBody($attachment);
            if ($body !== null) $email->attach($body, $name, $type);
        }
        try {
            $this->sender->send($email);
        } catch (Throwable $failed) {
            $this->inbox->updateItem($itemId, ['error' => mb_substr($failed->getMessage(), 0, 500)]);
            return ['sent' => false, 'item_id' => $itemId, 'error' => 'The reply could not be sent: ' . $failed->getMessage() . ' It is kept as a draft.'];
        }
        $now = (new DateTimeImmutable())->format(DATE_ATOM);
        $this->inbox->updateItem($itemId, ['status' => 'sent', 'sent_at' => $now, 'message_id' => $messageId, 'error' => null,
            'from_name' => $sender['name'] !== '' ? $sender['name'] : $this->installation->name(), 'from_email' => $sender['address']]);
        $this->inbox->touched($conversationId);
        if (($input['mark_answered'] ?? '') === '1' && $this->inbox->markAnswered($conversationId, $actor)) {
            $this->record('contact.message_answered', ['message_id' => $conversationId], $actor);
        }
        $this->record('inbox.reply_sent', ['message_id' => $conversationId, 'reference' => $conversation['reference'], 'format' => $format, 'attachments' => count($attachments)], $actor);
        return ['sent' => true, 'item_id' => $itemId, 'error' => ''];
    }

    /** Discard: the conversation's draft goes to the Trash, where Restore brings it back. */
    public function discard(int $conversationId, string $actor): bool
    {
        $draft = $this->inbox->draftOf($conversationId);
        if ($draft === null || !$this->inbox->trashDraft((int) $draft['id'])) return false;
        $this->record('inbox.draft_discarded', ['message_id' => $conversationId], $actor);
        return true;
    }

    /**
     * An attachment of a reply, to download from the conversation page.
     *
     * @return array{0:string,1:string,2:string}|null [body, name, type]
     */
    public function download(int $conversationId, string $itemPublicId, int $index): ?array
    {
        foreach ($this->inbox->itemsOf($conversationId) as $item) {
            if ((string) $item['public_id'] !== $itemPublicId) continue;
            $attachments = json_decode((string) $item['attachments'], true) ?: [];
            if (!isset($attachments[$index]) || !is_array($attachments[$index])) return null;
            [$body, $name, $type] = $this->attachmentBody($attachments[$index]);
            return $body === null ? null : [$body, $name, $type];
        }
        $draft = $this->inbox->draftOf($conversationId);
        if ($draft !== null && (string) $draft['public_id'] === $itemPublicId) {
            $attachments = json_decode((string) $draft['attachments'], true) ?: [];
            if (isset($attachments[$index]) && is_array($attachments[$index])) {
                [$body, $name, $type] = $this->attachmentBody($attachments[$index]);
                return $body === null ? null : [$body, $name, $type];
            }
        }
        return null;
    }

    /**
     * The draft's attachments after this save: those kept, the files added and the person's documents ticked.
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $input
     * @param list<UploadedFile> $files
     * @return list<array<string,mixed>>
     */
    private function attachments(array $item, array $input, array $files, ?string $account): array
    {
        $kept = [];
        $remove = array_map('intval', is_array($input['remove'] ?? null) ? $input['remove'] : []);
        foreach ((json_decode((string) $item['attachments'], true) ?: []) as $index => $attachment) {
            if (!is_array($attachment) || ($attachment['type'] ?? '') !== 'file') continue;
            if (in_array((int) $index, $remove, true)) { @unlink($this->path((string) $attachment['path'])); continue; }
            $kept[] = $attachment;
        }
        foreach ($files as $file) {
            if (!$file->isValid()) throw new InvalidArgumentException('A file could not be uploaded: ' . $file->getErrorMessage());
            if ($file->getSize() > self::MAX_FILE_BYTES) throw new InvalidArgumentException($file->getClientOriginalName() . ' is larger than ' . self::size(self::MAX_FILE_BYTES) . '.');
            $name = self::safeName($file->getClientOriginalName());
            $folder = 'inbox/' . $item['public_id'];
            if (!is_dir($this->path($folder)) && !mkdir($this->path($folder), 0770, true) && !is_dir($this->path($folder))) throw new \RuntimeException('The file could not be kept.');
            $stored = $folder . '/' . bin2hex(random_bytes(6)) . '-' . $name;
            $size = (int) $file->getSize();
            $type = $file->getMimeType() ?: 'application/octet-stream';
            $file->move(dirname($this->path($stored)), basename($stored));
            $kept[] = ['type' => 'file', 'name' => $name, 'path' => $stored, 'size' => $size, 'mime' => $type];
        }
        $mine = [];
        foreach ($this->documentsOf($account) as $document) $mine[(int) $document['id']] = $document;
        foreach (array_map('intval', is_array($input['documents'] ?? null) ? $input['documents'] : []) as $id) {
            if (isset($mine[$id])) $kept[] = ['type' => 'document', 'id' => $id, 'name' => $mine[$id]['number'] . '.pdf'];
        }
        if (count($kept) > self::MAX_ATTACHMENTS) throw new InvalidArgumentException('A reply carries at most ' . self::MAX_ATTACHMENTS . ' attachments.');
        return $kept;
    }

    /**
     * @param array<string,mixed> $attachment
     * @return array{0:?string,1:string,2:string}
     */
    private function attachmentBody(array $attachment): array
    {
        if (($attachment['type'] ?? '') === 'document') {
            $document = $this->documents?->attachment((int) ($attachment['id'] ?? 0));
            return $document === null ? [null, '', ''] : $document;
        }
        $path = $this->path((string) ($attachment['path'] ?? ''));
        $body = is_file($path) ? file_get_contents($path) : false;
        return $body === false ? [null, '', ''] : [$body, (string) $attachment['name'], (string) ($attachment['mime'] ?? 'application/octet-stream')];
    }

    /**
     * The account's own documents a reply may attach, newest first.
     *
     * @return list<array{id:int,number:string,kind:string,label:string}>
     */
    private function documentsOf(?string $account): array
    {
        return $account === null || $account === '' || $this->documents === null ? [] : $this->documents->of($account);
    }

    /** @param array<string,mixed> $conversation */
    private static function account(array $conversation): ?string
    {
        $account = $conversation['account'] ?? $conversation['user_id'] ?? null;

        return is_scalar($account) && (string) $account !== '' ? (string) $account : null;
    }

    /** @param array<string,mixed> $details */
    private function record(string $action, array $details, string $actor): void
    {
        $this->activity?->record($action, $details, actor: $actor !== '' && $actor !== '0' ? new Actor($actor, $actor) : Actor::system());
    }

    /** "Name <address>" as the reply will be sent from. */
    private function fromLine(): string
    {
        $sender = $this->settings->sender();
        $mail = $this->mail();
        $address = $sender['address'] !== '' ? $sender['address'] : $mail['from_address'];
        $name = $sender['name'] !== '' ? $sender['name'] : $mail['from_name'];
        return $address === '' ? $name : $name . ' <' . $address . '>';
    }

    private function replyTo(): string
    {
        $sender = $this->settings->sender();
        return $sender['reply_to'] !== '' ? $sender['reply_to'] : $this->mail()['reply_to'];
    }

    private function messageDomain(): string
    {
        $address = $this->settings->sender()['address'] ?: $this->mail()['from_address'];
        $domain = (string) substr((string) strrchr($address, '@'), 1);
        if ($domain === '') $domain = (string) preg_replace('#^https?://#', '', rtrim($this->environment->get('APP_DOMAIN'), '/'));
        return $domain !== '' ? $domain : 'localhost';
    }

    /** @return array{from_address:string,from_name:string,reply_to:string} the site's own sender, empty where it is not set or not valid */
    private function mail(): array
    {
        $fromAddress = $this->site->get('MAIL_FROM_ADDRESS');
        $replyTo = $this->site->get('MAIL_REPLY_TO');
        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false || ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false)) {
            return ['from_address' => '', 'from_name' => $this->installation->name(), 'reply_to' => ''];
        }
        $fromName = $this->site->get('MAIL_FROM_NAME');

        return ['from_address' => $fromAddress, 'from_name' => $fromName !== '' ? $fromName : $this->installation->name(), 'reply_to' => $replyTo];
    }

    private function path(string $relative): string
    {
        if (str_contains($relative, '..')) throw new InvalidArgumentException('That is not an attachment.');
        return rtrim($this->filesFolder, '/') . '/' . ltrim($relative, '/');
    }

    private static function safeName(string $name): string
    {
        $name = trim((string) preg_replace('/[^\p{L}\p{N}._ -]+/u', '-', basename($name)), ' .-');
        return $name === '' ? 'attachment' : mb_substr($name, 0, 120);
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
