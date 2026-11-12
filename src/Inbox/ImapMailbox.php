<?php

declare(strict_types=1);

namespace CattoPHP\Inbox;

use RuntimeException;

/**
 * The mailbox answers come to, read over IMAP (RFC 9051 and 3501). PHP has had no IMAP extension since 8.4, and the IMAP
 * libraries bring large dependencies, so this is the small part the Inbox needs and no more: sign in (SSL, STARTTLS or
 * neither), open INBOX read-only (EXAMINE), find the messages newer than the last one read by their UIDs, and fetch each
 * whole without marking it read. Nothing in the mailbox is changed. The first time, it only notes where the mailbox
 * stands, so old mail is never brought in.
 */
class ImapMailbox
{
    public const BATCH = 25;
    private const TIMEOUT = 20;

    /** @var resource|null */
    private $stream = null;
    private int $tag = 0;

    /**
     * Signs in and opens INBOX, then signs out: whether the mailbox can be read, in words.
     *
     * @param array{host:string,port:int,security:string,user:string,password:string} $mailbox
     */
    public function check(array $mailbox): string
    {
        try {
            $status = $this->open($mailbox);
            return 'Connected: ' . ($status['exists'] === 1 ? '1 message' : $status['exists'] . ' messages') . ' in the mailbox.';
        } finally {
            $this->close();
        }
    }

    /**
     * The messages that arrived since the state given, at most BATCH of them, and the state to keep for next time. With no
     * state (or a mailbox whose UIDs started again), nothing is fetched: the state only notes where the mailbox stands.
     *
     * @param array{host:string,port:int,security:string,user:string,password:string} $mailbox
     * @param array{validity?:int,last?:int} $state
     * @return array{messages:list<array{uid:int,raw:string}>,state:array{validity:int,last:int}}
     */
    public function newMessages(array $mailbox, array $state): array
    {
        try {
            $status = $this->open($mailbox);
            $validity = $status['validity'];
            if (!isset($state['validity'], $state['last']) || (int) $state['validity'] !== $validity) {
                return ['messages' => [], 'state' => ['validity' => $validity, 'last' => max(0, $status['next'] - 1)]];
            }
            $last = (int) $state['last'];
            $uids = [];
            foreach ($this->command('UID SEARCH UID ' . ($last + 1) . ':*') as $line) {
                if (is_string($line) && preg_match('/^\* SEARCH(.*)$/i', $line, $found) === 1) {
                    foreach (preg_split('/\s+/', trim($found[1])) ?: [] as $uid) if (ctype_digit($uid) && (int) $uid > $last) $uids[] = (int) $uid;
                }
            }
            sort($uids);
            $messages = [];
            foreach (array_slice($uids, 0, self::BATCH) as $uid) {
                $raw = $this->fetch($uid);
                if ($raw !== null) $messages[] = ['uid' => $uid, 'raw' => $raw];
                $last = max($last, $uid);
            }
            return ['messages' => $messages, 'state' => ['validity' => $validity, 'last' => $last]];
        } finally {
            $this->close();
        }
    }

    /**
     * @param array{host:string,port:int,security:string,user:string,password:string} $mailbox
     * @return array{exists:int,validity:int,next:int}
     */
    private function open(array $mailbox): array
    {
        if ($mailbox['host'] === '' || $mailbox['user'] === '' || $mailbox['password'] === '') throw new RuntimeException('Set the mail server, the mailbox user and its password first.');
        $this->stream = $this->connect($mailbox['host'], $mailbox['port'], $mailbox['security']);
        $greeting = $this->line();
        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) throw new RuntimeException('The mail server did not greet as an IMAP server does.');
        if ($mailbox['security'] === 'tls') {
            $this->command('STARTTLS');
            if (stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) throw new RuntimeException('The mail server refused STARTTLS.');
        }
        $this->command('LOGIN ' . self::quoted($mailbox['user']) . ' ' . self::quoted($mailbox['password']), 'The mail server did not accept the mailbox user and password.');
        $status = ['exists' => 0, 'validity' => 0, 'next' => 1];
        foreach ($this->command('EXAMINE INBOX', 'The mailbox has no INBOX to read.') as $line) {
            if (!is_string($line)) continue;
            if (preg_match('/^\* (\d+) EXISTS/i', $line, $m) === 1) $status['exists'] = (int) $m[1];
            if (preg_match('/UIDVALIDITY (\d+)/i', $line, $m) === 1) $status['validity'] = (int) $m[1];
            if (preg_match('/UIDNEXT (\d+)/i', $line, $m) === 1) $status['next'] = (int) $m[1];
        }
        return $status;
    }

    /**
     * Opens the connection; a test gives a stream of its own.
     *
     * @return resource
     */
    protected function connect(string $host, int $port, string $security)
    {
        $context = stream_context_create(['ssl' => ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $stream = @stream_socket_client(($security === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $code, $message, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) throw new RuntimeException('The mail server could not be reached at ' . $host . ':' . $port . ($message !== '' ? ' (' . $message . ')' : '') . '.');
        stream_set_timeout($stream, self::TIMEOUT);
        return $stream;
    }

    private function fetch(int $uid): ?string
    {
        $raw = null;
        foreach ($this->command('UID FETCH ' . $uid . ' (BODY.PEEK[])', null, true) as $line) {
            if (is_array($line)) $raw = $line[1];
        }
        return $raw;
    }

    /**
     * Sends a command and reads its answer to the tagged line: the untagged lines, and each literal as [line, bytes].
     *
     * @return list<string|array{0:string,1:string}>
     */
    private function command(string $command, ?string $refused = null, bool $literals = false): array
    {
        $tag = 'C' . (++$this->tag);
        $this->write($tag . ' ' . $command . "\r\n");
        $lines = [];
        while (true) {
            $line = $this->line();
            if (preg_match('/\{(\d+)\}$/', $line, $size) === 1) {
                $bytes = $this->read((int) $size[1]);
                $rest = $this->line();
                $lines[] = $literals ? [$line, $bytes] : $line . $rest;
                continue;
            }
            if (str_starts_with($line, $tag . ' ')) {
                if (preg_match('/^' . $tag . ' OK/i', $line) !== 1) throw new RuntimeException($refused ?? 'The mail server refused: ' . mb_substr(substr($line, strlen($tag) + 1), 0, 200));
                return $lines;
            }
            $lines[] = $line;
        }
    }

    private function line(): string
    {
        $line = $this->stream !== null ? fgets($this->stream) : false;
        if ($line === false) throw new RuntimeException('The mail server stopped answering.');
        return rtrim($line, "\r\n");
    }

    private function read(int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = $this->stream !== null ? fread($this->stream, max(1, min(65536, $bytes - strlen($data)))) : false;
            if ($chunk === false || $chunk === '') throw new RuntimeException('The mail server stopped part way through a message.');
            $data .= $chunk;
        }
        return $data;
    }

    private function write(string $data): void
    {
        if ($this->stream === null || fwrite($this->stream, $data) === false) throw new RuntimeException('The mail server could not be written to.');
    }

    private function close(): void
    {
        $stream = $this->stream;
        if ($stream === null) return;
        try {
            $this->write('C' . (++$this->tag) . " LOGOUT\r\n");
        } catch (RuntimeException) {
        }
        @fclose($stream);
        $this->stream = null;
        $this->tag = 0;
    }

    private static function quoted(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
