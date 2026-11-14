<?php

declare(strict_types=1);

namespace CattoPHP\Tests\Support;

/** Tiny pictures of each kind, two pixels square, for the checks of an uploaded picture. */
final class SamplePictures
{
    public const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWM8ESDCwMDAxMDAwMDAAAAO7gEwrJixdQAAAABJRU5ErkJggg==';
    public const JPEG = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA+Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2NjIpLCBkZWZhdWx0IHF1YWxpdHkK/9sAQwAIBgYHBgUIBwcHCQkICgwUDQwLCwwZEhMPFB0aHx4dGhwcICQuJyAiLCMcHCg3KSwwMTQ0NB8nOT04MjwuMzQy/9sAQwEJCQkMCwwYDQ0YMiEcITIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy/8AAEQgAAgACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8AzKKKK+TPuj//2Q==';
    public const WEBP = 'UklGRjwAAABXRUJQVlA4IDAAAADwAQCdASoCAAIAAUAmJaACdLoB+AAETAAA/upl//z8zu3F/Vb/7B39Kx9Kx+vQAAA=';
    public const GIF = 'R0lGODdhAgACAIAAAMxSFAAAACwAAAAAAgACAAACAoRRADs=';

    private function __construct()
    {
    }

    /** One of the pictures written to a file in a folder, as an upload's temporary file is: its path. */
    public static function file(string $folder, string $base64, string $name = 'upload.tmp'): string
    {
        $path = $folder . '/' . $name;
        file_put_contents($path, (string) base64_decode($base64, true));

        return $path;
    }
}
