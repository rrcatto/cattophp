<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

use CattoPHP\Inbox\ImapMailbox;
use RuntimeException;

/** An IMAP server that answers from a script, over a socket pair: what the mailbox reader sends is heard, never a network. */
final class ScriptedMailbox extends ImapMailbox
{
    /** @var resource */
    private $server;

    public function __construct(private readonly string $script) {}

    protected function connect(string $host, int $port, string $security)
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) throw new RuntimeException('No socket pair.');
        [$client, $this->server] = $pair;
        fwrite($this->server, $this->script);
        stream_set_blocking($this->server, false);
        return $client;
    }

    private string $heard = '';

    /** What the mailbox sent the server, all of it. */
    public function sent(): string
    {
        $this->heard .= (string) stream_get_contents($this->server);
        return $this->heard;
    }
}
