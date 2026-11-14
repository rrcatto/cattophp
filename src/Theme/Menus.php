<?php

declare(strict_types=1);

namespace CattoPHP\Theme;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The menu sections the modules and the project add (each `MenuProvider`), as the person signed in may see them: each
 * section that still has an entry they may open, its entries' addresses worked out and the page being read marked, as
 * plain words a project's `PageProject::model()` adds to its navbar as drop-downs. Sections come in their providers'
 * tag priority order (highest first); a section given again under the same key gains the later entries. With no provider
 * it does nothing at all.
 */
final class Menus
{
    /**
     * @param iterable<MenuProvider> $providers
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly UrlGeneratorInterface $urls,
        private readonly ?RequestStack $requests = null,
        private readonly ?AuthorizationCheckerInterface $authorization = null,
    ) {
    }

    /**
     * Each section the reader may open something in, with the entries they may open. An entry is current when the page
     * being read is its address or under it (/marketing/campaigns/spring under /marketing/campaigns), the closest one
     * winning, and its section is current with it.
     *
     * @return list<array{key:string,label:string,icon:string,current:bool,entries:list<array{label:string,href:string,icon:string,current:bool}>}>
     */
    public function sections(): array
    {
        /** @var array<string,array{key:string,label:string,icon:string,current:bool,entries:list<array{label:string,href:string,icon:string,current:bool}>}> $sections */
        $sections = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->menuSections() as $section) {
                foreach ($section->entries as $entry) {
                    if ($entry->permission !== null && !$this->may($entry->permission)) {
                        continue;
                    }
                    $sections[$section->key] ??= ['key' => $section->key, 'label' => $section->label, 'icon' => $section->icon, 'current' => false, 'entries' => []];
                    $sections[$section->key]['entries'][] = [
                        'label' => $entry->label,
                        'href' => $entry->route !== null ? $this->urls->generate($entry->route, $entry->parameters) : (string) $entry->address,
                        'icon' => $entry->icon,
                        'current' => false,
                    ];
                }
            }
        }
        if ($sections === []) {
            return [];
        }

        return $this->markCurrent(array_values($sections));
    }

    /**
     * The sections with the entry closest to the page being read marked current, and its section.
     *
     * @param list<array{key:string,label:string,icon:string,current:bool,entries:list<array{label:string,href:string,icon:string,current:bool}>}> $sections
     * @return list<array{key:string,label:string,icon:string,current:bool,entries:list<array{label:string,href:string,icon:string,current:bool}>}>
     */
    private function markCurrent(array $sections): array
    {
        $request = $this->requests?->getMainRequest();
        if ($request === null) {
            return $sections;
        }
        $reading = $request->getBaseUrl() . $request->getPathInfo();
        $best = null;
        $closest = -1;
        foreach ($sections as $s => $section) {
            foreach ($section['entries'] as $e => $entry) {
                $length = self::depth($entry['href'], $reading);
                if ($length > $closest) {
                    [$best, $closest] = [[$s, $e], $length];
                }
            }
        }

        return array_map(static fn (array $section, int $s): array => [
            'key' => $section['key'],
            'label' => $section['label'],
            'icon' => $section['icon'],
            'current' => $best !== null && $best[0] === $s,
            'entries' => array_map(static fn (array $entry, int $e): array => [
                'label' => $entry['label'],
                'href' => $entry['href'],
                'icon' => $entry['icon'],
                'current' => $best === [$s, $e],
            ], $section['entries'], array_keys($section['entries'])),
        ], $sections, array_keys($sections));
    }

    /** How closely an entry's address holds the page being read: its path's length, or -1 when it does not hold it. */
    private static function depth(string $href, string $reading): int
    {
        if (!str_starts_with($href, '/') || str_starts_with($href, '//')) {
            return -1;
        }
        $path = (string) parse_url($href, PHP_URL_PATH);
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $reading = $reading === '/' ? '/' : rtrim($reading, '/');
        if ($path === $reading || ($path !== '/' && str_starts_with($reading, $path . '/'))) {
            return strlen($path);
        }

        return -1;
    }

    private function may(string $permission): bool
    {
        return $this->authorization !== null && $this->authorization->isGranted($permission);
    }
}
