<?php

declare(strict_types=1);

namespace CattoPHP\Foundation;

use RuntimeException;

/**
 * SVG drawn as PNG by rsvg-convert (librsvg, LGPL-2.1-or-later), run as its own process: PHP's GD draws no SVG and no PHP
 * package draws gradients, paths, opacity and clipping faithfully. The SVG is given on standard input, so it can load no
 * file, and the drawing is stopped when it takes longer than it may. A server without rsvg-convert draws nothing; Debian
 * and Ubuntu install it as librsvg2-bin.
 */
final class SvgRaster
{
    /** The full path of rsvg-convert: the one given if it can run, else the first found, else null. */
    public static function tool(?string $given = null): ?string
    {
        if ($given !== null) {
            return is_file($given) && is_executable($given) ? $given : null;
        }
        foreach (['/usr/bin/rsvg-convert', '/usr/local/bin/rsvg-convert', '/opt/homebrew/bin/rsvg-convert'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The SVG drawn as PNG bytes.
     *
     * @param list<string> $options rsvg-convert's options, such as --width=1200
     * @throws RuntimeException when it cannot start, takes too long or draws nothing
     */
    public static function png(string $tool, string $svg, array $options, int $seconds): string
    {
        $process = proc_open([$tool, '--format=png', ...$options], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('rsvg-convert could not be started.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        fwrite($pipes[0], $svg);
        fclose($pipes[0]);
        $png = '';
        $deadline = microtime(true) + $seconds;
        while (true) {
            $png .= (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $png .= (string) stream_get_contents($pipes[1]);
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new RuntimeException('rsvg-convert took too long.');
            }
            usleep(20000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        if ($png === '' || !str_starts_with($png, "\x89PNG")) {
            throw new RuntimeException('rsvg-convert drew nothing usable.');
        }

        return $png;
    }
}
