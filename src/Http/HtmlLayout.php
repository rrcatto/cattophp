<?php

declare(strict_types=1);

namespace CattoPHP\Http;

use JsonException;

/**
 * Lays out a served HTML page so that a person can read its source: every element that holds a block of content on lines
 * of its own, one tab for each level, and nothing that a browser draws changed.
 *
 * Whitespace is changed only where a browser does not draw it differently: a run of whitespace becomes another run (a
 * line break and tabs draw as the one space they replace); whitespace is added only at the start of an element before a
 * block, where the start of a line is never drawn, and between the children of a container whose whitespace is never
 * drawn (a table's rows, a select's options, a drawing's shapes, the head). Anywhere else, an element whose parts have no
 * whitespace between them stays on one line, as it came: a template that wants it laid out writes the whitespace. An inline element (a link, a button, a label) is spread over lines only where
 * whitespace already sits at each of its edges and between each of its children; otherwise it stays on one line. The
 * contents of pre, textarea, script and style, and of a region the project marks as written by an author (asWritten(),
 * or an element of one of the verbatim classes), are served byte for byte. An element with nothing in it stays empty,
 * because `:empty` styles it. A page whose markup does not nest cleanly is served as it came, without the marks.
 *
 * The layout knows HTML's defaults, not a page's CSS: an element that CSS draws inline although HTML makes it a block, set
 * between words, would gain a space. A project's browser checks look for that (and for `white-space: pre` outside the
 * places above).
 */
final class HtmlLayout
{
    /** Blocks by HTML's default display. */
    private const BLOCK = ['address' => 1, 'article' => 1, 'aside' => 1, 'blockquote' => 1, 'body' => 1, 'caption' => 1, 'col' => 1, 'colgroup' => 1, 'dd' => 1, 'details' => 1, 'dialog' => 1, 'div' => 1, 'dl' => 1, 'dt' => 1, 'fieldset' => 1, 'figcaption' => 1, 'figure' => 1, 'footer' => 1, 'form' => 1, 'h1' => 1, 'h2' => 1, 'h3' => 1, 'h4' => 1, 'h5' => 1, 'h6' => 1, 'head' => 1, 'header' => 1, 'hgroup' => 1, 'hr' => 1, 'html' => 1, 'legend' => 1, 'li' => 1, 'main' => 1, 'menu' => 1, 'nav' => 1, 'noscript' => 1, 'ol' => 1, 'optgroup' => 1, 'option' => 1, 'p' => 1, 'pre' => 1, 'search' => 1, 'section' => 1, 'summary' => 1, 'table' => 1, 'tbody' => 1, 'td' => 1, 'template' => 1, 'tfoot' => 1, 'th' => 1, 'thead' => 1, 'tr' => 1, 'ul' => 1];

    /** Containers whose whitespace between children is never drawn, so each child may go on a line of its own. */
    private const QUIET = ['html' => 1, 'head' => 1, 'table' => 1, 'thead' => 1, 'tbody' => 1, 'tfoot' => 1, 'tr' => 1, 'colgroup' => 1, 'select' => 1, 'optgroup' => 1, 'datalist' => 1, 'svg' => 1, 'g' => 1, 'defs' => 1, 'lineargradient' => 1, 'radialgradient' => 1, 'clippath' => 1, 'mask' => 1, 'pattern' => 1, 'symbol' => 1, 'marker' => 1, 'filter' => 1, 'video' => 1, 'audio' => 1, 'picture' => 1];

    /** The parts of a drawing whose whitespace is drawn, which stay on one line. */
    private const SVG_TEXT = ['text' => 1, 'tspan' => 1, 'textpath' => 1, 'title' => 1, 'desc' => 1, 'foreignobject' => 1, 'style' => 1, 'script' => 1];

    private const VOID = ['area' => 1, 'base' => 1, 'br' => 1, 'col' => 1, 'embed' => 1, 'hr' => 1, 'img' => 1, 'input' => 1, 'link' => 1, 'meta' => 1, 'param' => 1, 'source' => 1, 'track' => 1, 'wbr' => 1];

    /** A page larger than this is served as it came: laying it out would cost more than it is worth. */
    private const LARGEST = 4_000_000;

    /** What laying out a page may take in memory for each byte of it, with room to spare (measured: about 28). */
    private const MEMORY_PER_BYTE = 40;

    private string $token;
    private bool $marking = false;

    /**
     * @param list<string> $verbatimClasses classes whose element's contents are served byte for byte (a project's authored HTML)
     * @param list<string> $flexClasses classes the project's CSS always lays out as flex or grid, whose children each go on a
     *                                  line of their own: whitespace between and around a flex or grid container's children is
     *                                  never drawn (the project's browser checks prove the CSS says so on every page)
     * @param int|null $memoryLimit the memory, in bytes, a page's layout must fit under; PHP's memory_limit when null
     */
    public function __construct(private readonly array $verbatimClasses = [], private readonly array $flexClasses = [], private readonly ?int $memoryLimit = null)
    {
        $this->token = bin2hex(random_bytes(8));
    }

    /** From now until end(), asWritten() marks what it is given, because a page is being drawn that will be laid out. */
    public function begin(): void
    {
        $this->marking = true;
    }

    public function end(): void
    {
        $this->marking = false;
    }

    /**
     * HTML written by an author, served byte for byte by the layout; anything drawn while no page is being laid out (a
     * fragment, an email, a PDF) is returned as it is, with no mark.
     */
    public function asWritten(?string $html): string
    {
        $html = (string) $html;
        if (!$this->marking || $html === '') {
            return $html;
        }

        return '<!--as-written:' . $this->token . '-->' . $html . '<!--/as-written:' . $this->token . '-->';
    }

    /** A whole document, from its doctype to `</html>`, laid out; as it came (without marks) when it cannot be. */
    public function document(string $html): string
    {
        if (strlen($html) > self::LARGEST || !$this->roomFor(strlen($html))) {
            return $this->unmarked($html);
        }
        if (preg_match('/<html\b/i', $html, $found, PREG_OFFSET_CAPTURE) !== 1) {
            return $this->unmarked($html);
        }
        $before = trim(substr($html, 0, (int) $found[0][1]));
        $root = $this->parse(substr($html, (int) $found[0][1]));
        if ($root === null) {
            return $this->unmarked($html);
        }
        $lines = [];
        foreach (preg_split('/\R/', $before) ?: [] as $line) {
            if (trim($line) !== '') {
                $lines[] = trim($line);
            }
        }
        $this->blockChildren($root['children'], 0, $lines, true);
        unset($root);
        $page = implode("\n", $lines) . "\n";
        unset($lines);

        return $page;
    }

    /**
     * Whether PHP's memory limit leaves room to lay out a page of this size: running out of memory cannot be caught, and the
     * page must never be lost to its layout, so a page without the room is served as it came. The limit applies to the
     * memory PHP has taken from the system, which stays taken after a large piece of work frees what it used, so that is
     * what is measured (memory_get_usage(true)), never only what is in use.
     */
    private function roomFor(int $length): bool
    {
        if ($this->memoryLimit !== null) {
            return memory_get_usage(true) + $length * self::MEMORY_PER_BYTE < $this->memoryLimit;
        }
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return true;
        }
        $bytes = (int) $limit;
        $bytes *= match (strtolower(substr($limit, -1))) {
            'g' => 1 << 30,
            'm' => 1 << 20,
            'k' => 1 << 10,
            default => 1,
        };

        return memory_get_usage(true) + $length * self::MEMORY_PER_BYTE < $bytes;
    }

    private function unmarked(string $html): string
    {
        return str_replace(['<!--as-written:' . $this->token . '-->', '<!--/as-written:' . $this->token . '-->'], '', $html);
    }

    /**
     * The document as a tree: elements, text, comments, raw elements (script, style, pre, textarea) and authored regions,
     * each keeping the bytes it came with. Null when an end tag does not close the element open.
     *
     * @return array<string,mixed>|null
     */
    private function parse(string $html): ?array
    {
        $mark = preg_quote($this->token, '~');
        $attributes = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*';
        preg_match_all(
            '~<!--as-written:' . $mark . '-->(.*?)<!--/as-written:' . $mark . '-->'
            . '|<!--.*?-->'
            . '|<(script|style|textarea|pre)\b' . $attributes . '>.*?</\2\s*>'
            . '|<title\b' . $attributes . '>.*?</title\s*>'
            . '|</[a-zA-Z][a-zA-Z0-9:-]*\s*>'
            . '|<[a-zA-Z][a-zA-Z0-9:-]*' . $attributes . '>'
            . '|<![^>]*>|[^<]+|<~is',
            $html,
            $tokens
        );
        /** @var array<string,mixed> $root */
        $root = ['t' => 'el', 'name' => '#root', 'tag' => '', 'open' => '', 'close' => '', 'children' => [], 'svg' => false, 'verbatim' => false, 'void' => false];
        /** @var list<array<string,mixed>> $stack */
        $stack = [&$root];
        $all = $tokens[0];
        $written = $tokens[1];
        unset($tokens);
        foreach ($all as $index => $token) {
            $top = &$stack[count($stack) - 1];
            if ($top['verbatim']) {
                // Inside an authored element: its bytes as they are, counting its own name to find where it ends.
                if (preg_match('~^<(/?)([a-zA-Z][a-zA-Z0-9:-]*)~', $token, $tag) === 1 && strtolower($tag[2]) === $top['name']) {
                    if ($tag[1] === '/' && (int) $top['depth'] === 0) {
                        $top['close'] = $token;
                        array_pop($stack);
                        unset($top);
                        continue;
                    }
                    $top['depth'] += $tag[1] === '/' ? -1 : (str_ends_with($token, '/>') ? 0 : 1);
                }
                $top['raw'] .= $token;
                unset($top);
                continue;
            }
            if (str_starts_with($token, '<!--as-written:')) {
                $top['children'][] = ['t' => 'written', 's' => $written[$index]];
            } elseif (str_starts_with($token, '<!--') || str_starts_with($token, '<!')) {
                $top['children'][] = ['t' => 'comment', 's' => $token];
            } elseif (preg_match('~^<(script|style|textarea|pre|title)\b~i', $token, $raw) === 1) {
                $top['children'][] = ['t' => 'raw', 's' => self::tidyStart($token), 'name' => strtolower($raw[1])];
            } elseif (preg_match('~^</([a-zA-Z][a-zA-Z0-9:-]*)~', $token, $end) === 1) {
                if (count($stack) === 1 || $top['name'] !== strtolower($end[1])) {
                    unset($top);
                    return null;
                }
                $top['close'] = '</' . $top['tag'] . '>';
                array_pop($stack);
            } elseif (preg_match('~^<([a-zA-Z][a-zA-Z0-9:-]*)~', $token, $start) === 1) {
                $name = strtolower($start[1]);
                $inSvg = $top['svg'] || $name === 'svg';
                $open = self::tidyStart($token);
                $node = ['t' => 'el', 'name' => $name, 'tag' => $start[1], 'open' => $open, 'close' => '', 'children' => [], 'svg' => $inSvg && $name !== 'foreignobject', 'verbatim' => false, 'void' => false];
                if (isset(self::VOID[$name]) && !$top['svg'] || ($inSvg && str_ends_with($open, '/>'))) {
                    $node['void'] = true;
                    $top['children'][] = $node;
                } else {
                    if ($this->isVerbatim($open)) {
                        $node['verbatim'] = true;
                        $node['raw'] = '';
                        $node['depth'] = 0;
                    }
                    $top['children'][] = $node;
                    $stack[] = &$top['children'][count($top['children']) - 1];
                }
            } else {
                $top['children'][] = ['t' => 'text', 's' => $token];
            }
            unset($top);
        }

        return count($stack) === 1 ? $root : null;
    }

    private function isVerbatim(string $open): bool
    {
        return $this->hasClass($open, $this->verbatimClasses);
    }

    /**
     * An element of one of the flex or grid classes that holds elements and no words of its own: its children may each go on
     * a line of their own, because whitespace between and around a flex or grid container's children is never drawn. Words
     * directly inside it stay as they are: a trailing space can still count in a box's width.
     *
     * @param array<string,mixed> $node
     */
    private function isFlex(array $node): bool
    {
        if ($node['t'] !== 'el' || $node['void'] || $node['verbatim'] || !$this->hasClass((string) $node['open'], $this->flexClasses)) {
            return false;
        }
        foreach ($node['children'] as $child) {
            if ($child['t'] === 'text' && !self::isSpace($child)) {
                return false;
            }
        }

        return array_filter($node['children'], static fn (array $child): bool => !self::isSpace($child)) !== [];
    }

    /** @param list<string> $classes */
    private function hasClass(string $open, array $classes): bool
    {
        if ($classes === [] || preg_match('~\sclass\s*=\s*("([^"]*)"|\'([^\']*)\')~i', $open, $class) !== 1) {
            return false;
        }
        $names = preg_split('/\s+/', trim(($class[2] ?? '') !== '' ? $class[2] : ($class[3] ?? ''))) ?: [];

        return array_intersect($names, $classes) !== [];
    }

    /** A start tag on one line: the whitespace between its attributes made one space, its values as they are. */
    private static function tidyStart(string $token): string
    {
        if (preg_match('~^<[a-zA-Z](?:[^>"\']|"[^"]*"|\'[^\']*\')*>~', $token, $start) !== 1) {
            return $token;
        }
        $tidy = (string) preg_replace_callback('~"[^"]*"|\'[^\']*\'|\s+~', static fn (array $part): string => in_array($part[0][0], ['"', "'"], true) ? $part[0] : ' ', $start[0]);

        return (string) preg_replace('~\s+(/?>)$~', '$1', $tidy) . substr($token, strlen($start[0]));
    }

    /** @param array<string,mixed> $node */
    private static function isBlock(array $node): bool
    {
        if ($node['t'] === 'raw') {
            return $node['name'] === 'pre';
        }
        if ($node['t'] !== 'el') {
            return false;
        }
        if ($node['svg']) {
            return $node['name'] !== 'svg' && !isset(self::SVG_TEXT[$node['name']]);
        }
        if (isset(self::BLOCK[$node['name']])) {
            return true;
        }
        // An inline element holding a block (a card that is a link) breaks around its blocks as a block does.
        foreach ($node['children'] as $child) {
            if (self::isBlock($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Inline content between the edges of a block, without the whitespace at those edges, which is never drawn. Only text
     * is trimmed: an authored region or a raw element at an edge keeps its bytes.
     *
     * @param list<array<string,mixed>> $run
     * @return list<array<string,mixed>>
     */
    private static function trimmed(array $run): array
    {
        while ($run !== [] && self::isSpace($run[0])) {
            array_shift($run);
        }
        while ($run !== [] && self::isSpace($run[count($run) - 1])) {
            array_pop($run);
        }
        if ($run !== [] && $run[0]['t'] === 'text') {
            $run[0]['s'] = ltrim((string) $run[0]['s'], " \t\n\r\f");
        }
        $last = count($run) - 1;
        if ($last >= 0 && $run[$last]['t'] === 'text') {
            $run[$last]['s'] = rtrim((string) $run[$last]['s'], " \t\n\r\f");
        }

        return $run;
    }

    /** @param array<string,mixed> $node */
    private static function isSpace(array $node): bool
    {
        return $node['t'] === 'text' && trim($node['s'], " \t\n\r\f") === '';
    }

    /**
     * The children of a block, or of a container whose whitespace is never drawn, each on lines of its own where a block
     * or whitespace allows.
     *
     * @param list<array<string,mixed>> $children
     * @param list<string> $lines
     */
    private function blockChildren(array $children, int $depth, array &$lines, bool $quiet): void
    {
        $run = [];
        foreach ($children as $child) {
            // In a container whose whitespace is never drawn, every child stands alone; anywhere else, a block does.
            if (self::isBlock($child) || ($quiet && !self::isSpace($child))) {
                $this->inlineRun($run, $depth, $lines);
                $run = [];
                if (!self::isSpace($child)) {
                    $this->node($child, $depth, $lines);
                }
            } else {
                $run[] = $child;
            }
        }
        $this->inlineRun($run, $depth, $lines);
    }

    /**
     * Inline content between the edges of blocks: trimmed at those edges, words on one line, and a row of elements split
     * only at the whitespace already between them.
     *
     * @param list<array<string,mixed>> $run
     * @param list<string> $lines
     */
    private function inlineRun(array $run, int $depth, array &$lines): void
    {
        while ($run !== [] && self::isSpace($run[0])) {
            array_shift($run);
        }
        while ($run !== [] && self::isSpace($run[count($run) - 1])) {
            array_pop($run);
        }
        if ($run === []) {
            return;
        }
        $run = self::trimmed($run);
        foreach ($run as $node) {
            if ($node['t'] === 'text' && !self::isSpace($node)) {
                $lines[] = str_repeat("\t", $depth) . $this->inline($run);
                return;
            }
        }
        $group = [];
        foreach ($run as $node) {
            if (self::isSpace($node)) {
                if ($group !== []) {
                    $this->group($group, $depth, $lines);
                }
                $group = [];
            } else {
                $group[] = $node;
            }
        }
        if ($group !== []) {
            $this->group($group, $depth, $lines);
        }
    }

    /**
     * Inline content with no whitespace inside it: one line, unless it is one element that may be laid out inside.
     *
     * @param list<array<string,mixed>> $group
     * @param list<string> $lines
     */
    private function group(array $group, int $depth, array &$lines): void
    {
        if (count($group) === 1 && $group[0]['t'] === 'el' && ($this->spreads($group[0]) || $this->isFlex($group[0]) || ($group[0]['name'] === 'svg' && !$group[0]['void']))) {
            $this->node($group[0], $depth, $lines);
            return;
        }
        $lines[] = str_repeat("\t", $depth) . $this->inline($group);
    }

    /**
     * Whether an inline element may be laid out over lines: it holds two or more elements and no words, with whitespace
     * already at both its inner edges and between each two of them, so nothing drawn changes.
     *
     * @param array<string,mixed> $node
     */
    private function spreads(array $node): bool
    {
        if ($node['t'] !== 'el' || $node['void'] || $node['verbatim'] || $node['svg'] || count($node['children']) < 3) {
            return false;
        }
        $children = $node['children'];
        if (!self::isSpace($children[0]) || !self::isSpace($children[count($children) - 1])) {
            return false;
        }
        $elements = 0;
        $previous = null;
        foreach ($children as $child) {
            if ($child['t'] === 'text' && !self::isSpace($child)) {
                return false;
            }
            if (!self::isSpace($child)) {
                if ($previous !== null && !self::isSpace($previous)) {
                    return false;
                }
                $elements++;
            }
            $previous = $child;
        }

        return $elements >= 2;
    }

    /**
     * One node on lines of its own, at its depth.
     *
     * @param array<string,mixed> $node
     * @param list<string> $lines
     */
    private function node(array $node, int $depth, array &$lines): void
    {
        $indent = str_repeat("\t", $depth);
        if ($node['t'] === 'raw') {
            $lines[] = $indent . $this->raw($node, $depth);
            return;
        }
        if ($node['t'] !== 'el') {
            $lines[] = $indent . $this->inline([$node]);
            return;
        }
        if ($node['void']) {
            $lines[] = $indent . $node['open'];
            return;
        }
        if ($node['verbatim']) {
            $lines[] = $indent . $node['open'] . $node['raw'] . $node['close'];
            return;
        }
        if ($node['children'] === []) {
            $lines[] = $indent . $node['open'] . $node['close'];
            return;
        }
        if ($node['svg'] && isset(self::SVG_TEXT[$node['name']])) {
            // A drawing's text may keep its spaces (xml:space), so it is served exactly as written.
            $lines[] = $indent . $this->exact([$node]);
            return;
        }
        if (array_filter($node['children'], static fn (array $child): bool => !self::isSpace($child)) === []) {
            // Whitespace alone stays one space, so `:empty` matches nothing it did not match before.
            $lines[] = $indent . $node['open'] . ' ' . $node['close'];
            return;
        }
        $quiet = isset(self::QUIET[$node['name']]) || ($node['svg'] && !isset(self::SVG_TEXT[$node['name']])) || $this->isFlex($node);
        if ($quiet) {
            $lines[] = $indent . $node['open'];
            $this->blockChildren($node['children'], $depth + 1, $lines, true);
            $lines[] = $indent . $node['close'];
            return;
        }
        if (!self::isBlock($node)) {
            if ($this->spreads($node)) {
                $lines[] = $indent . $node['open'];
                $this->inlineRun($node['children'], $depth + 1, $lines);
                $lines[] = $indent . $node['close'];
                return;
            }
            $lines[] = $indent . $this->inline([$node]);
            return;
        }
        // A block, or an inline element holding blocks: each of its parts on lines of its own, where every line break replaces
        // whitespace already there (or opens the element before a block). A break added or taken away anywhere else can change
        // what is drawn: a trailing space counts in a table cell's width, and CSS may draw two blocks side by side, where a
        // break is a gap. So where one would be needed the whole element stays on one line, each run of whitespace one space.
        $items = self::items($node['children']);
        if (!self::breaksEverywhere($items)) {
            $lines[] = $indent . $this->inline([$node]);
            return;
        }
        $lines[] = $indent . $node['open'];
        foreach ($items as $item) {
            if ($item['kind'] === 'block') {
                $this->node($item['node'], $depth + 1, $lines);
            } elseif ($item['kind'] === 'run') {
                $this->inlineRun($item['nodes'], $depth + 1, $lines);
            }
        }
        $lines[] = $indent . $node['close'];
    }

    /**
     * An element's children as its parts: each block, each run of inline content between them (whether whitespace is at
     * its start and its end), and the whitespace alone between two blocks.
     *
     * @param list<array<string,mixed>> $children
     * @return list<array{kind:string,node:array<string,mixed>,nodes:list<array<string,mixed>>,lead:bool,trail:bool}>
     */
    private static function items(array $children): array
    {
        $items = [];
        $run = [];
        foreach ($children as $child) {
            if (self::isBlock($child)) {
                if ($run !== []) {
                    $items[] = self::runItem($run);
                    $run = [];
                }
                $items[] = ['kind' => 'block', 'node' => $child, 'nodes' => [], 'lead' => false, 'trail' => false];
            } else {
                $run[] = $child;
            }
        }
        if ($run !== []) {
            $items[] = self::runItem($run);
        }

        return $items;
    }

    /**
     * A run of inline content as a part: whitespace alone between two blocks, or words and inline elements, with whether
     * whitespace is at its start and at its end.
     *
     * @param list<array<string,mixed>> $run
     * @return array{kind:string,node:array<string,mixed>,nodes:list<array<string,mixed>>,lead:bool,trail:bool}
     */
    private static function runItem(array $run): array
    {
        if (array_filter($run, static fn (array $node): bool => !self::isSpace($node)) === []) {
            return ['kind' => 'space', 'node' => [], 'nodes' => [], 'lead' => true, 'trail' => true];
        }
        $first = $run[0];
        $last = $run[count($run) - 1];

        return [
            'kind' => 'run', 'node' => [], 'nodes' => $run,
            'lead' => $first['t'] === 'text' && preg_match('/^[ \t\n\r\f]/', (string) $first['s']) === 1,
            'trail' => $last['t'] === 'text' && preg_match('/[ \t\n\r\f]$/', (string) $last['s']) === 1,
        ];
    }

    /**
     * Whether every part may begin on a line of its own: each break between the element's edges and its parts, and between
     * its parts, replaces whitespace already there, or stands at the element's start before a block.
     *
     * @param list<array{kind:string,node:array<string,mixed>,nodes:list<array<string,mixed>>,lead:bool,trail:bool}> $items
     */
    private static function breaksEverywhere(array $items): bool
    {
        $previous = ['kind' => 'edge'];
        $spaced = false;
        foreach ($items as $item) {
            if ($item['kind'] === 'space') {
                $spaced = true;
                continue;
            }
            if (!self::mayBreak($previous, $item, $spaced)) {
                return false;
            }
            $previous = $item;
            $spaced = false;
        }

        return self::mayBreak($previous, ['kind' => 'edge'], $spaced);
    }

    /**
     * @param array{kind:string,lead?:bool,trail?:bool} $left
     * @param array{kind:string,lead?:bool,trail?:bool} $right
     */
    private static function mayBreak(array $left, array $right, bool $spaced): bool
    {
        if ($spaced) {
            return true;
        }
        // At an element's start, before a block: whitespace at the start of a line is never drawn, whatever CSS makes the block.
        // Between two blocks or before the element's end it can be: CSS may draw both inline (a dropdown beside its row of
        // buttons), so a break there needs whitespace already there.
        if ($left['kind'] === 'edge' && $right['kind'] === 'block') {
            return true;
        }

        return ($left['kind'] === 'run' && ($left['trail'] ?? false)) || ($right['kind'] === 'run' && ($right['lead'] ?? false));
    }

    /**
     * A raw element as it came; structured data (JSON-LD) laid out a level inside its script.
     *
     * @param array<string,mixed> $node
     */
    private function raw(array $node, int $depth): string
    {
        $text = (string) $node['s'];
        if ($node['name'] !== 'script' || preg_match('~^(<script\b[^>]*\btype="application/ld\+json"[^>]*>)(.*)(</script\s*>)$~is', $text, $parts) !== 1) {
            return $text;
        }
        try {
            $data = json_decode(trim($parts[2]), true, 512, JSON_THROW_ON_ERROR);
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        } catch (JsonException) {
            return $text;
        }
        $inside = str_repeat("\t", $depth + 1);
        $json = (string) preg_replace_callback('/^( {4})+/m', static fn (array $spaces): string => str_repeat("\t", intdiv(strlen($spaces[0]), 4)), $json);

        return $parts[1] . "\n" . $inside . str_replace("\n", "\n" . $inside, $json) . "\n" . str_repeat("\t", $depth) . $parts[3];
    }

    /**
     * Content exactly as it came, start tags tidied.
     *
     * @param list<array<string,mixed>> $nodes
     */
    private function exact(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= match ($node['t']) {
                'text', 'raw', 'comment', 'written' => (string) $node['s'],
                default => $node['open'] . ($node['void'] ? '' : ($node['verbatim'] ? $node['raw'] : $this->exact($node['children'])) . $node['close']),
            };
        }

        return $out;
    }

    /**
     * Inline content on one line: each run of whitespace one space, as a browser draws it anyway.
     *
     * @param list<array<string,mixed>> $nodes
     */
    private function inline(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= match ($node['t']) {
                'text' => (string) preg_replace('/[ \t\n\r\f]+/', ' ', (string) $node['s']),
                'raw', 'comment' => (string) $node['s'],
                'written' => (string) $node['s'],
                default => $node['open'] . ($node['void'] ? '' : ($node['verbatim'] ? $node['raw'] : ($node['svg'] && isset(self::SVG_TEXT[$node['name']]) ? $this->exact($node['children']) : $this->inline($node['children']))) . $node['close']),
            };
        }

        return $out;
    }
}
