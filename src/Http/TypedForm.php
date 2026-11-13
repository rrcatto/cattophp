<?php

declare(strict_types=1);

namespace CattoPHP\Http;

use CattoPHP\Security\Redactor;
use Symfony\Component\HttpFoundation\Request;

/**
 * A refused form comes back as it was typed and chosen, once. Before sending the person back to the page, the
 * controller keeps what the form sent (`TypedForm::keep($request)`), under the address the form was sent to. The next
 * page drawn takes it: its form reads it by that address (`typed_for(path('…'))` in a template), so it shows only in
 * the form that sent it, and whatever that page did not use is forgotten once the page is sent (TypedFormListener), so
 * nothing typed comes back later. Never the form's token, a password, a secret or a sign-in code; a long value is cut
 * short. A field is judged a secret by its name, as Security\Redactor judges one (a token, an API key or a DSN among
 * them), and the controller names any other secret field the form holds
 * (`TypedForm::keep($request, ['STITCH_CLIENT_ID'])`, as a Settings form does with each setting that is a secret
 * whatever it is called), which is then never kept either.
 *
 * Why each field was refused can wait with them (`TypedForm::keep($request, errors: ['name' => 'A name needs at least one
 * letter.'])`, or the FieldsRefused a service threw, `errors: $refused`, for a form refused for several fields at once),
 * for the form to show beside its field: `typed_errors_for(path('…'))` in a template, or `errors()`, gives them by the
 * field's name, and form.field's `error` (or form.picture-choice's) draws one under its field (with aria-describedby and
 * aria-invalid on the control through `ui_field_attrs(props)`). They are taken and forgotten with the values.
 *
 * The values wait in Symfony's session, or in the PHP session a project runs itself when Symfony's is off.
 */
final class TypedForm
{
    public const SESSION = '_cattophp_typed_form';

    /** Where the values taken by the page being drawn are held for the rest of its request. */
    private const TAKEN = '_cattophp_typed_form_taken';

    /**
     * Fields never kept beside those whose names Security\Redactor takes for a secret's (a password, a passphrase, a
     * secret, a token or an API key, a private or signing key, a DSN, a cookie, an authorisation, a one-time or recovery
     * code): form tokens. A field called code (a course's, a promotion's) is kept.
     */
    private const NEVER = '/^_token$|^csrf$/i';

    /** The longest value kept, in characters. */
    private const LONGEST = 200_000;

    /** The most values kept in one list. */
    private const MOST = 1000;

    /**
     * @param list<string> $secret the form's other secret fields, by name, never kept whatever they are called
     * @param array<string,string>|FieldsRefused $errors why each field was refused, by its name, for the form to show
     *     beside it: a map, or the FieldsRefused a service threw (its errors(); one about the whole form is not kept)
     */
    public static function keep(Request $request, array $secret = [], array|FieldsRefused $errors = []): void
    {
        self::store($request, self::kept($request->getPathInfo(), $request->request->all(), $secret, $errors));
    }

    /**
     * What the kept values hold for a form sent to $action, without taking them: for a page that works something out
     * from them before it is drawn.
     *
     * @return array<string,mixed>
     */
    public static function peek(Request $request, string $action): array
    {
        $kept = $request->attributes->has(self::TAKEN) ? $request->attributes->get(self::TAKEN) : self::stored($request);

        return self::forAction($kept, $action);
    }

    /**
     * What the kept values hold for a form sent to $action, taken for the page being drawn: the first call takes them
     * all, and every form on the page reads its own.
     *
     * @return array<string,mixed>
     */
    public static function take(Request $request, string $action): array
    {
        if (!$request->attributes->has(self::TAKEN)) {
            $request->attributes->set(self::TAKEN, self::stored($request));
            self::store($request, null);
        }

        return self::forAction($request->attributes->get(self::TAKEN), $action);
    }

    /**
     * Why each field of the form sent to $action was refused, by the field's name, taken for the page being drawn as
     * take() takes the values: empty when the form was not refused, or was refused without a field to blame.
     *
     * @return array<string,string>
     */
    public static function errors(Request $request, string $action): array
    {
        if (!$request->attributes->has(self::TAKEN)) {
            $request->attributes->set(self::TAKEN, self::stored($request));
            self::store($request, null);
        }
        $kept = self::valid($request->attributes->get(self::TAKEN));

        return $kept !== null && $kept['action'] === self::path($action) ? ($kept['errors'] ?? []) : [];
    }

    /** Forgets whatever is kept: done once a page has been drawn after the refusal. */
    public static function forget(Request $request): void
    {
        if (self::stored($request) !== null) {
            self::store($request, null);
        }
    }

    /**
     * @param array<array-key,mixed> $posted
     * @param list<string> $secret the form's other secret fields, by name
     * @param array<string,string>|FieldsRefused $errors why each field was refused, by its name
     * @return array{action:string,values:array<string,mixed>,errors?:array<string,string>}
     */
    public static function kept(string $action, array $posted, array $secret = [], array|FieldsRefused $errors = []): array
    {
        if ($errors instanceof FieldsRefused) {
            $errors = $errors->errors();
        }
        $values = [];
        foreach ($posted as $name => $value) {
            if (!is_string($name) || preg_match(self::NEVER, $name) === 1 || preg_match(Redactor::SECRET_NAMES, $name) === 1 || in_array($name, $secret, true)) {
                continue;
            }
            $kept = self::value($value, 0);
            if ($kept !== null) {
                $values[$name] = $kept;
            }
        }

        $kept = ['action' => self::path($action), 'values' => $values];
        $errors = self::errorList($errors);

        return $errors === [] ? $kept : $kept + ['errors' => $errors];
    }

    /** @return array<string,mixed> */
    private static function forAction(mixed $kept, string $action): array
    {
        $kept = self::valid($kept);

        return $kept !== null && $kept['action'] === self::path($action) ? $kept['values'] : [];
    }

    /** @return array{action:string,values:array<string,mixed>,errors?:array<string,string>}|null */
    private static function stored(Request $request): ?array
    {
        if ($request->hasSession()) {
            return self::valid($request->getSession()->get(self::SESSION));
        }

        return self::phpSession() ? self::valid($_SESSION[self::SESSION] ?? null) : null;
    }

    /** @param array{action:string,values:array<string,mixed>,errors?:array<string,string>}|null $kept null removes */
    private static function store(Request $request, ?array $kept): void
    {
        if ($request->hasSession()) {
            $kept === null ? $request->getSession()->remove(self::SESSION) : $request->getSession()->set(self::SESSION, $kept);
        } elseif (self::phpSession()) {
            if ($kept === null) {
                unset($_SESSION[self::SESSION]);
            } else {
                $_SESSION[self::SESSION] = $kept;
            }
        }
    }

    /** Whether the project runs a PHP session of its own: $_SESSION is there exactly when it does. */
    private static function phpSession(): bool
    {
        return isset($_SESSION);
    }

    /** @return array{action:string,values:array<string,mixed>,errors?:array<string,string>}|null */
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
        $valid = ['action' => $kept['action'], 'values' => $values];
        $errors = self::errorList(is_array($kept['errors'] ?? null) ? $kept['errors'] : []);

        return $errors === [] ? $valid : $valid + ['errors' => $errors];
    }

    /**
     * Each field's error as words, by the field's name; an error with no words is none.
     *
     * @param array<array-key,mixed> $errors
     * @return array<string,string>
     */
    private static function errorList(array $errors): array
    {
        $list = [];
        foreach ($errors as $name => $why) {
            if (is_string($name) && $name !== '' && is_scalar($why) && trim((string) $why) !== '') {
                $list[$name] = mb_substr(trim((string) $why), 0, 1000);
            }
        }

        return $list;
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
