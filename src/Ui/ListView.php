<?php

declare(strict_types=1);

namespace CattoPHP\Ui;

/**
 * A list shown as its table or as a grid of cards, as its reader chooses: the choice in the address (<list>_view), else
 * the one remembered for a year in a cookie (cl_view_<list>), else the list's default. The project says which lists it has,
 * each with its default view, its cards to a row and a grid's page sizes in whole rows; the view switch
 * (@CattoPHP/ui/data/view-switch.html.twig) is plain links, so it works without JavaScript.
 */
final class ListView
{
    public const LIST = 'list';
    public const GRID = 'grid';

    private const COOKIE_PREFIX = 'cl_view_';

    private function __construct()
    {
    }

    /**
     * The view of a list for this request: the address's choice, else the remembered one, else the default.
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed> $cookies
     */
    public static function chosen(string $list, array $query, array $cookies, string $default = self::LIST): string
    {
        $asked = $query[$list . '_view'] ?? null;
        if (is_string($asked) && in_array($asked, [self::LIST, self::GRID], true)) {
            return $asked;
        }
        $remembered = $cookies[self::COOKIE_PREFIX . $list] ?? null;
        if (is_string($remembered) && in_array($remembered, [self::LIST, self::GRID], true)) {
            return $remembered;
        }

        return $default;
    }

    /**
     * Remembers the address's choice for a year when it named one (the response carries the cookie).
     *
     * @param array<string,mixed> $query
     */
    public static function rememberAsked(string $list, array $query): void
    {
        $asked = $query[$list . '_view'] ?? null;
        if (!is_string($asked) || !in_array($asked, [self::LIST, self::GRID], true) || headers_sent()) {
            return;
        }
        setcookie(self::COOKIE_PREFIX . $list, $asked, [
            'expires' => time() + 365 * 86400,
            'path' => '/',
            'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * The page sizes in a view: a grid's own whole rows when it has them, else the tables' sizes.
     *
     * @param list<int>|null $gridSizes
     * @return list<int>
     */
    public static function pageSizes(string $view, ?array $gridSizes): array
    {
        return $view === self::GRID && $gridSizes !== null ? $gridSizes : Pagination::PAGE_SIZES;
    }

    /**
     * What the view switch needs: the choice, the two addresses, and the grid's cards to a row.
     *
     * @param array<string,mixed> $filters The list's current filters, kept on the switch's links.
     * @return array{name:string,view:string,columns:int,list_href:string,grid_href:string}
     */
    public static function switcher(string $list, string $view, string $base, array $filters = [], int $columns = 4): array
    {
        $filters = array_filter($filters, static fn (mixed $value): bool => $value !== '' && $value !== null && $value !== 0);
        $href = static fn (string $to): string => $base . '?' . http_build_query($filters + [$list . '_view' => $to]);

        return [
            'name' => $list,
            'view' => $view,
            'columns' => $columns,
            'list_href' => $href(self::LIST),
            'grid_href' => $href(self::GRID),
        ];
    }
}
