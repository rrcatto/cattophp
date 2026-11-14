<?php

declare(strict_types=1);

namespace CattoPHP\Marketing\Landing\Kinds;

use CattoPHP\Marketing\Adverts;
use CattoPHP\Marketing\Destination;
use CattoPHP\Marketing\Landing\CopyRefused;
use CattoPHP\Marketing\Landing\LandingContext;
use CattoPHP\Marketing\Landing\LandingCopy;
use CattoPHP\Marketing\Landing\LandingVisit;

/**
 * A video: one of the campaign's video adverts (by its reference) or an address typed, with a title and its length in
 * words. The page embeds a YouTube or Vimeo address (youtube-nocookie.com, player.vimeo.com) when the site's
 * Content-Security-Policy lets that host be framed; otherwise, and without frames, the title is a link "Watch the video".
 * The view gives both, and the page decides.
 */
final class VideoKind extends BaseKind
{
    /** The hosts a video may be embedded from, by the address it is embedded at. */
    public const EMBED_HOSTS = ['www.youtube-nocookie.com', 'player.vimeo.com'];

    private const ADVERT = '/^[a-z0-9]{4,12}$/';

    public function __construct(private readonly ?Adverts $adverts = null)
    {
    }

    public function key(): string
    {
        return 'video';
    }

    public function label(): string
    {
        return 'Video';
    }

    public function description(): string
    {
        return 'One of the campaign\'s videos, or a video\'s address.';
    }

    public function icon(): string
    {
        return 'circle-play';
    }

    public function once(): bool
    {
        return false;
    }

    public function defaultHeading(): string
    {
        return '';
    }

    public function defaults(): array
    {
        return ['heading' => '', 'text' => '', 'advert' => '', 'address' => '', 'title' => '', 'length' => ''];
    }

    public function normalise(array $input, LandingContext $page): array
    {
        $advert = is_string($input['advert'] ?? null) ? strtolower(trim($input['advert'])) : '';
        if ($advert !== '' && preg_match(self::ADVERT, $advert) !== 1) {
            throw new CopyRefused('advert', 'Choose one of the campaign\'s video adverts.');
        }
        $address = is_string($input['address'] ?? null) ? trim($input['address']) : '';
        if ($address !== '' && (strlen($address) > Destination::LONGEST || preg_match('/\s/', $address) === 1 || !Destination::isOutside($address) || !str_starts_with(strtolower($address), 'https://'))) {
            throw new CopyRefused('address', 'Type the video\'s whole address, starting with https://.');
        }

        return ['heading' => $this->heading($input), 'text' => $this->text($input, 600), 'advert' => $advert, 'address' => $advert !== '' ? '' : $address,
            'title' => LandingCopy::line($input['title'] ?? '', 120, 'The video\'s title', 'title'), 'length' => LandingCopy::line($input['length'] ?? '', 40, 'The video\'s length', 'length')];
    }

    public function missing(array $data, LandingContext $page): array
    {
        if (($data['advert'] ?? '') === '' && ($data['address'] ?? '') === '') {
            return ['Video has no video chosen.'];
        }
        if (($data['advert'] ?? '') !== '' && $this->address($data, $page) === null) {
            return ['Video: the campaign\'s video advert it plays is no longer there.'];
        }

        return [];
    }

    public function summary(array $data, LandingContext $page): string
    {
        $title = trim(is_string($data['title'] ?? null) ? $data['title'] : '');
        $length = trim(is_string($data['length'] ?? null) ? $data['length'] : '');
        if ($title === '' && ($data['advert'] ?? '') === '' && ($data['address'] ?? '') === '') {
            return 'No video chosen yet';
        }

        return trim(($title !== '' ? $title : 'A video') . ($length !== '' ? ', ' . $length : ''));
    }

    public function form(LandingContext $page): array
    {
        $adverts = [];
        if ($page->campaign !== null && $this->adverts !== null) {
            foreach ($this->adverts->list($page->campaign->id, false) as $advert) {
                if ($advert->kind === 'video' && $advert->mediaAddress !== null) {
                    $adverts[$advert->reference] = $advert->name;
                }
            }
        }

        return ['limits' => ['heading' => 120, 'text' => 600, 'title' => 120, 'length' => 40], 'adverts' => $adverts];
    }

    /**
     * The video's address (the advert's or the one typed), the address it is embedded at and that address's host when it
     * is a YouTube or Vimeo video (the page embeds it only when its Content-Security-Policy allows that host), its title and
     * length. Without a video the section is left out.
     */
    public function view(array $data, LandingContext $page, LandingVisit $visit): ?array
    {
        $address = $this->address($data, $page);
        if ($address === null) {
            return null;
        }
        $embed = self::embed($address);

        return $this->common($data) + ['address' => $address, 'embed' => $embed, 'embed_host' => $embed === null ? null : (string) parse_url($embed, PHP_URL_HOST),
            'title' => is_string($data['title'] ?? null) ? $data['title'] : '', 'length' => is_string($data['length'] ?? null) ? $data['length'] : ''];
    }

    /** The address a YouTube or Vimeo video is embedded at, or null for any other address. */
    public static function embed(string $address): ?string
    {
        $parts = parse_url($address);
        $host = strtolower(is_array($parts) ? ($parts['host'] ?? '') : '');
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        parse_str(is_array($parts) ? ($parts['query'] ?? '') : '', $query);
        $youtube = match (true) {
            in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true) && $path === '/watch' => is_string($query['v'] ?? null) ? $query['v'] : '',
            in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'www.youtube-nocookie.com'], true) && preg_match('#^/(?:shorts|embed|live)/([^/]+)#', $path, $match) === 1 => $match[1],
            $host === 'youtu.be' => ltrim($path, '/'),
            default => null,
        };
        if ($youtube !== null) {
            return preg_match('/^[A-Za-z0-9_-]{6,20}$/', $youtube) === 1 ? 'https://www.youtube-nocookie.com/embed/' . $youtube : null;
        }
        if (in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true) && preg_match('#/(\d{3,12})(?:/|$)#', $path, $match) === 1) {
            return 'https://player.vimeo.com/video/' . $match[1];
        }

        return null;
    }

    /** @param array<string,mixed> $data */
    private function address(array $data, LandingContext $page): ?string
    {
        $advert = is_string($data['advert'] ?? null) ? $data['advert'] : '';
        if ($advert !== '') {
            $found = $page->campaign === null ? null : $this->adverts?->get($advert, $page->campaign);

            return $found !== null && $found->kind === 'video' ? $found->mediaAddress : null;
        }
        $address = is_string($data['address'] ?? null) ? $data['address'] : '';

        return $address === '' ? null : $address;
    }
}
