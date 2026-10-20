<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz;

/**
 * Where the images a graph names may be read from: only the directories the host allows,
 * never a graph's own `imagepath`. A name is a path relative to one of them, or an absolute
 * one inside one; resolved with its links followed, it must stay inside, be a file no larger
 * than {@see self::MOST_BYTES}, and hold a PNG, JPEG, GIF or WebP image no larger than
 * {@see self::MOST_PIXELS} a side. Anything else is not read: a URL, a name escaping the
 * directories, a file of another kind. An image's size is its pixels at 96 to the inch,
 * rounded down to whole points, as dot measures it.
 */
final readonly class DotImages
{
    /** The most bytes an image file may hold. */
    public const int MOST_BYTES = 10_000_000;
    /** The most pixels an image may span either way. */
    public const int MOST_PIXELS = 10_000;
    /** @var array<int, string> the kinds read, by GD's number for them */
    private const array TYPES = [\IMAGETYPE_PNG => 'image/png', \IMAGETYPE_JPEG => 'image/jpeg', \IMAGETYPE_GIF => 'image/gif', \IMAGETYPE_WEBP => 'image/webp'];

    /** @var list<string> the allowed directories, resolved */
    private array $directories;

    /** @param list<string> $directories */
    public function __construct(array $directories = [])
    {
        $resolved = [];
        foreach ($directories as $directory) {
            $real = \realpath($directory);
            if (false === $real || !\is_dir($real)) {
                continue;
            }
            $resolved[] = \rtrim($real, characters: \DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR;
        }
        $this->directories = $resolved;
    }

    /** The image a name stands for, read from an allowed directory; null where it may not be, or is none. */
    public function read(string $name): ?DotImage
    {
        // A URL, any scheme but a drive's letter, is never read.
        $scheme = 1 === \preg_match('~^[a-z][a-z0-9+.-]*:~i', $name) && !self::absolute($name);
        if ('' === $name || \str_contains($name, "\0") || $scheme) {
            return null;
        }
        foreach ($this->directories as $directory) {
            $path = self::absolute($name) ? $name : $directory.$name;
            $real = \realpath($path);
            if (false === $real || !\str_starts_with($real, $directory) || !\is_file($real)) {
                continue;
            }
            $size = \filesize($real);
            if (false === $size || $size > self::MOST_BYTES) {
                return null;
            }

            return self::image((string) \file_get_contents($real));
        }

        return null;
    }

    private static function absolute(string $name): bool
    {
        return \str_starts_with($name, '/') || \str_starts_with($name, '\\') || 1 === \preg_match('~^[a-z]:[\\\\/]~i', $name);
    }

    private static function image(string $bytes): ?DotImage
    {
        // A malformed header warns before returning false; false is the answer.
        \set_error_handler(static fn (): bool => true);
        try {
            $size = \getimagesizefromstring($bytes);
        } finally {
            \restore_error_handler();
        }
        if (false === $size || !\array_key_exists($size[2], self::TYPES) || $size[0] < 1 || $size[1] < 1 || $size[0] > self::MOST_PIXELS || $size[1] > self::MOST_PIXELS) {
            return null;
        }

        return new DotImage($bytes, self::TYPES[$size[2]], \floor($size[0] * 0.75), \floor($size[1] * 0.75));
    }
}
