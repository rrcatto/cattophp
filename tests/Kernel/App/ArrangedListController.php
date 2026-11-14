<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Kernel\App;

use CattoPHP\Http\FormToken;
use CattoPHP\Ui\HalfRows;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * A page of the test application using the shared parts a landing page's editor and public page are built from: a
 * rating, a countdown and a list of sections put in order by hand, with a small drawing of the page in rows of halves.
 * Its moves are answered as a project answers them: a move whose order shown is not the order kept is refused and the
 * kept list shown with the reason; with htmx the list alone comes back (409 when refused), without it a redirect to
 * the page.
 */
final class ArrangedListController
{
    /** Each section's name and width, by its key. */
    private const SECTIONS = ['welcome' => ['Welcome', 'full'], 'why' => ['Why join', 'half'], 'answers' => ['Answers', 'half'], 'join' => ['Join now', 'full']];

    public function __construct(private readonly Environment $twig, private readonly FormToken $tokens, private readonly string $folder)
    {
    }

    public function page(Request $request): Response
    {
        $refused = $request->query->get('refused') === '1' ? 'The list had changed since the page was drawn, so that move was not made. The list as it is now is shown.' : '';

        return new Response($this->draw($this->order(), $refused, false));
    }

    public function move(Request $request, string $key): Response
    {
        if (!$this->tokens->isValid($request)) {
            return new Response('Not done: the form had expired. Reload the page and try again.', Response::HTTP_FORBIDDEN);
        }
        $order = $this->order();
        if (!in_array($key, $order, true)) {
            return new Response('Not found', Response::HTTP_NOT_FOUND);
        }
        $shown = array_values(array_filter(explode(',', (string) $request->request->get('order', '')), static fn (string $one): bool => $one !== ''));
        $index = filter_var($request->request->get('index'), FILTER_VALIDATE_INT);
        $error = match (true) {
            $shown !== $order => 'The list had changed since the page was drawn, so that move was not made. The list as it is now is shown.',
            !is_int($index) || $index < 0 || $index >= count($order) => 'That place is not in the list.',
            default => '',
        };
        if ($error === '') {
            $others = array_values(array_diff($order, [$key]));
            array_splice($others, $index, 0, [$key]);
            $order = $others;
            file_put_contents($this->file(), json_encode($order, JSON_THROW_ON_ERROR));
        }
        if ($request->headers->has('HX-Request')) {
            return new Response($this->draw($order, $error, true), $error === '' ? Response::HTTP_OK : Response::HTTP_CONFLICT);
        }

        return new RedirectResponse('/open/arranged' . ($error === '' ? '' : '?refused=1'), Response::HTTP_SEE_OTHER);
    }

    /** @return list<string> */
    private function order(): array
    {
        $kept = is_file($this->file()) ? json_decode((string) file_get_contents($this->file()), true) : null;

        return is_array($kept) ? array_values(array_filter($kept, 'is_string')) : array_keys(self::SECTIONS);
    }

    private function file(): string
    {
        return $this->folder . '/arranged-list.json';
    }

    /** @param list<string> $order */
    private function draw(array $order, string $error, bool $listAlone): string
    {
        $items = array_map(static fn (string $key): array => ['key' => $key, 'label' => self::SECTIONS[$key][0], 'width' => self::SECTIONS[$key][1]], $order);
        $context = ['items' => $items, 'order' => $order, 'error' => $error, 'rows' => HalfRows::rows($items)];
        $list = $this->twig->createTemplate((string) file_get_contents(__DIR__ . '/arranged-list-rows.html.twig'))->render($context);
        if ($listAlone) {
            return $list;
        }
        // The special ends two days, three hours and four minutes from now by the server's clock.
        $now = time();

        return $this->twig->createTemplate((string) file_get_contents(__DIR__ . '/arranged-list.html.twig'))->render($context + ['list' => $list, 'now' => $now, 'ends_at' => $now + 2 * 86400 + 3 * 3600 + 4 * 60]);
    }
}
