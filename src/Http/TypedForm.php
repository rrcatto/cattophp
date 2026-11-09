<?php

declare(strict_types=1);

namespace CattoPHP\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * A refused form comes back as it was typed and chosen. Before sending the person back to the page, the controller
 * keeps what the form sent (`TypedForm::keep($request)`), under the address the form was sent to; the page drawn next
 * takes it, and its form reads it by that address (`typed_for(path('…'))` in a template), so it shows once and only
 * in the form that sent it. Never the form's token or a password; a long value is cut short.
 */
final class TypedForm
{
    public const SESSION = '_cattophp_typed_form';

    /** Fields never kept. */
    private const NEVER = '/^_token$|password|passphrase|secret|^code$|one_time_code|recovery_code/i';

    /** The longest value kept, in characters. */
    private const LONGEST = 200_000;

    /** The most values kept in one list. */
    private const MOST = 1000;

    public static function keep(Request $request): void
    {
        if (!$request->hasSession()) {
            return;
        }
        $request->getSession()->set(self::SESSION, self::kept($request->getPathInfo(), $request->request->all()));
    }

    /**
     * What the kept values hold for a form sent to $action, taken once: nothing when they came from another form.
     *
     * @return array<string,mixed>
     */
    public static function take(Request $request, string $action): array
    {
        if (!$request->hasSession()) {
            return [];
        }
        $session = $request->getSession();
        $kept = self::valid($session->get(self::SESSION));
        if ($kept === null || $kept['action'] !== self::path($action)) {
            return [];
        }
        $session->remove(self::SESSION);

        return $kept['values'];
    }

    /**
     * @param array<array-key,mixed> $posted
     * @return array{action:string,values:array<string,mixed>}
     */
    public static function kept(string $action, array $posted): array
    {
        $values = [];
        foreach ($posted as $name => $value) {
            if (!is_string($name) || preg_match(self::NEVER, $name) === 1) {
                continue;
            }
            $kept = self::value($value, 0);
            if ($kept !== null) {
                $values[$name] = $kept;
            }
        }

        return ['action' => self::path($action), 'values' => $values];
    }

    /** @return array{action:string,values:array<string,mixed>}|null */
    private static function valid(mixed $kept): ?array
    {
        if (!is_array($kept) || !is_string($kept['action'] ?? null) || !is_array($kept['values'] ?? null)) {
            return null;
        }
        $values = [];
        foreach ($kept['values'] as $name => $value) {
            if (is_string($name)) {
                $values[$name] = $value;
            }
        }

        return ['action' => $kept['action'], 'values' => $values];
    }

    /** A form's action as it is compared: its path, without a query or a place on the page. */
    private static function path(string $action): string
    {
        $path = (string) parse_url($action, PHP_URL_PATH);

        return $path === '' ? '/' : $path;
    }

    /** @return string|array<array-key,mixed>|null */
    private static function value(mixed $value, int $depth): string|array|null
    {
        if (is_scalar($value)) {
            return mb_substr((string) $value, 0, self::LONGEST);
        }
        if (!is_array($value) || $depth >= 2) {
            return null;
        }
        $kept = [];
        foreach (array_slice($value, 0, self::MOST, true) as $key => $item) {
            $item = self::value($item, $depth + 1);
            if ($item !== null) {
                $kept[$key] = $item;
            }
        }

        return $kept;
    }
}
