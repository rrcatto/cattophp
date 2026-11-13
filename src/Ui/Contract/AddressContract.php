<?php

declare(strict_types=1);

namespace CattoPHP\Ui\Contract;

use Symfony\Component\Routing\RouteCollection;

/**
 * No row number in an address: a check of a project's routes, run by its tests over every route the router holds (its
 * own and those it imports, CattoPHP's pages among them). An address names a record by something a person recognises
 * and nobody can count through: a code (`/admin/roles/EDITOR`), a reference (`/admin/accounts/k7qxm2pa`, a
 * `Foundation\ReadableCode` kept with the record), a slug, a number the record is known by (an order number), or a random
 * public id; never the database's row number, which tells how many there are and invites the next one.
 *
 * A parameter is taken for a row number when it is named `id` or ends in `_id`, or when its requirement accepts digits
 * and nothing else (`\d+`, `[0-9]{1,9}`). Parameters starting with `_` (`_format`, `_locale`) and Symfony's own routes
 * (named from `_`, such as its error preview) are not the project's records and are left alone.
 *
 * Where a project must keep one for now, it names it in a short allow-list with the reason, by route name and parameter:
 * `['admin_note parameter_id' => 'Why it may.']`. An allowance must give a reason, and one that no longer matches a route
 * is reported too, so the list only shrinks.
 *
 *     self::assertSame([], AddressContract::rowNumbers(static::getContainer()->get('router')->getRouteCollection()));
 */
final class AddressContract
{
    private function __construct()
    {
    }

    /**
     * Every route parameter that takes a row number and is not allowed, and every allowance that is wrong or no longer
     * needed, each in words; none means the addresses keep the rule.
     *
     * @param array<string,string> $allowed "<route name> <parameter>" => why it may, for now
     * @return list<string>
     */
    public static function rowNumbers(RouteCollection $routes, array $allowed = []): array
    {
        $problems = [];
        $used = [];
        foreach ($routes->all() as $name => $route) {
            if (str_starts_with($name, '_')) {
                continue;
            }
            preg_match_all('/\{!?([A-Za-z_][A-Za-z0-9_]*)(?:<[^>]*>)?(?:\?[^}]*)?\}/', $route->getPath(), $found);
            foreach ($found[1] as $parameter) {
                $requirement = (string) ($route->getRequirement($parameter) ?? '');
                if (str_starts_with($parameter, '_') || !self::isRowNumber($parameter, $requirement)) {
                    continue;
                }
                $key = $name . ' ' . $parameter;
                if (array_key_exists($key, $allowed)) {
                    $used[$key] = true;
                    continue;
                }
                $methods = $route->getMethods() === [] ? 'ANY' : implode(',', $route->getMethods());
                $problems[] = sprintf('%s %s (route %s) takes {%s}%s, a row number: name the record by a code, a reference, a slug or a random public id, or allow "%s" with the reason.',
                    $methods, $route->getPath(), $name, $parameter, $requirement === '' ? '' : ' (' . $requirement . ')', $key);
            }
        }
        foreach ($allowed as $key => $why) {
            if (trim($why) === '') {
                $problems[] = sprintf('The allowance "%s" gives no reason: say why the address may keep a row number for now.', $key);
            }
            if (!isset($used[$key])) {
                $problems[] = sprintf('The allowance "%s" matches no route parameter that takes a row number: take it off the list.', $key);
            }
        }

        return $problems;
    }

    /** Whether a route parameter takes a row number: named id or *_id, or held to digits alone. */
    public static function isRowNumber(string $parameter, string $requirement = ''): bool
    {
        return $parameter === 'id' || str_ends_with($parameter, '_id') || self::onlyDigits($requirement);
    }

    /** Whether a route requirement accepts digits and nothing else: \d+, [0-9]+, [1-9][0-9]*, \d{1,9}. */
    public static function onlyDigits(string $requirement): bool
    {
        if ($requirement === '') {
            return false;
        }
        $left = (string) preg_replace(['/\\\\d/', '/\[(?:[0-9]-[0-9]|[0-9])+\]/', '/\{\d+(?:,\d*)?\}/', '/[+*?()|^$]/', '/[0-9]/'], '', $requirement);

        return $left === '';
    }
}
