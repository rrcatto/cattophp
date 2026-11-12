<?php

declare(strict_types=1);

namespace CattoPHP\Mailing;

/**
 * What a transport did with copies, by each copy's id: those handed on (with the job and message ids the transport gave,
 * when it gave them) and those it refused (with why).
 */
final class SendResult
{
    /**
     * @param array<int|string,array{job_id:?string,message_id:?string}> $handed
     * @param array<int|string,string> $failed
     */
    public function __construct(public readonly array $handed = [], public readonly array $failed = [])
    {
    }
}
