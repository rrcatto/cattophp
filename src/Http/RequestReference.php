<?php

declare(strict_types=1);

namespace CattoPHP\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A short reference for each request, such as K7QX-M2PA: in small print on an error page, beside the fault in the
 * log and on what the activity log records, so a person who reports a fault can be matched to it. Letters and digits
 * that cannot be mistaken for each other (no 0, O, 1 or I), so it can be read out.
 */
final class RequestReference
{
    public const ATTRIBUTE = '_cattophp_reference';
    public const HEADER = 'X-Request-Reference';
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly RequestStack $requests)
    {
    }

    /** The current request's reference; null outside a request (the console). */
    public function current(): ?string
    {
        $request = $this->requests->getMainRequest();

        return $request === null ? null : self::of($request);
    }

    public static function of(Request $request): string
    {
        $reference = $request->attributes->get(self::ATTRIBUTE);
        if (!is_string($reference)) {
            $reference = self::make();
            $request->attributes->set(self::ATTRIBUTE, $reference);
        }

        return $reference;
    }

    public static function make(): string
    {
        $characters = '';
        for ($i = 0; $i < 8; $i++) {
            $characters .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return substr($characters, 0, 4) . '-' . substr($characters, 4);
    }
}
