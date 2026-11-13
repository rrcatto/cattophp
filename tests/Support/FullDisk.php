<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

/**
 * A folder whose writes fail once it is full, as a full disk's do, for the file stores' tests: a stream wrapper whose every
 * path (faulty://a/b) is a real file under the folder given to register(), and whose writes store nothing while $full is
 * true. Reading, locking, renaming and deleting work as on the real folder.
 */
final class FullDisk
{
    public const SCHEME = 'faulty';

    public static bool $full = false;

    private static string $root = '';

    /** @var resource|null set by PHP for each stream */
    public $context;

    /** @var resource|null */
    private $handle;

    public static function register(string $root): void
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
        self::$root = rtrim($root, '/');
        self::$full = false;
    }

    public static function unregister(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
        self::$full = false;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $handle = @fopen(self::real($path), $mode);
        if ($handle === false) {
            return false;
        }
        $this->handle = $handle;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->open(), max(1, $count));
    }

    public function stream_write(string $data): int
    {
        return self::$full ? 0 : (int) fwrite($this->open(), $data);
    }

    public function stream_eof(): bool
    {
        return feof($this->open());
    }

    public function stream_tell(): int
    {
        return (int) ftell($this->open());
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return fseek($this->open(), $offset, $whence) === 0;
    }

    public function stream_truncate(int $size): bool
    {
        return ftruncate($this->open(), max(0, $size));
    }

    public function stream_lock(int $operation): bool
    {
        // PHP first asks with no operation at all whether the stream can be locked (file_put_contents with LOCK_EX).
        if (($operation & (LOCK_SH | LOCK_EX | LOCK_UN)) === 0) {
            return true;
        }

        return flock($this->open(), $operation & 7);
    }

    public function stream_flush(): bool
    {
        return fflush($this->open());
    }

    public function stream_close(): void
    {
        fclose($this->open());
        $this->handle = null;
    }

    /** @return array<int|string,int>|false */
    public function stream_stat(): array|false
    {
        return fstat($this->open());
    }

    public function stream_set_option(int $option, int $first, ?int $second): bool
    {
        return false;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return match ($option) {
            STREAM_META_ACCESS => is_int($value) && chmod(self::real($path), $value),
            STREAM_META_TOUCH => touch(self::real($path)),
            default => false,
        };
    }

    /** @return array<int|string,int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        $real = self::real($path);

        return file_exists($real) ? stat($real) : false;
    }

    public function unlink(string $path): bool
    {
        return unlink(self::real($path));
    }

    public function rename(string $from, string $to): bool
    {
        return rename(self::real($from), self::real($to));
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return mkdir(self::real($path), $mode, ($options & STREAM_MKDIR_RECURSIVE) !== 0);
    }

    private static function real(string $path): string
    {
        return self::$root . '/' . ltrim(substr($path, strlen(self::SCHEME . '://')), '/');
    }

    /** @return resource */
    private function open()
    {
        return $this->handle ?? throw new \LogicException('The stream is not open.');
    }
}
