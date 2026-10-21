<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Language;

use Janalis\Viz\PlantUML\Draw\PlantUmlSprite;
use Janalis\Viz\Source\Unsupported;

/**
 * PlantUML's standard library, as its `Stdlib` reads it: a library by name, whatever its case,
 * followed where it links to another, and its files and sprites. Of the libraries the jar
 * bundles, those licensed under MIT are exported into resources/plantuml/stdlib by
 * tools/import-plantuml.php; another is left to PlantUML.
 */
final class PlantUmlStdlib
{
    private const string DIRECTORY = __DIR__.'/../../../resources/plantuml';

    /** @var array<string, array{info: string, puml: array<string, string>, sprites: array<string, list<string>>}> */
    private static array $loaded = [];

    /**
     * A file's lines, as `!include <library/file>` names it; null for none PlantUML has.
     *
     * @return ?list<string>
     * @throws Unsupported for a library not exported
     */
    public static function lines(string $path): ?array
    {
        $path = \str_replace('.puml', replace: '', subject: \strtolower($path));
        $slash = \strpos($path, needle: '/');
        if (false === $slash) {
            return null;
        }
        $library = self::library(\substr($path, offset: 0, length: $slash));
        $file = \substr($path, $slash + 1);
        foreach ($library['puml'] ?? [] as $name => $text) {
            if (\strtolower($name) !== $file) {
                continue;
            }
            $text = \str_replace("\r\n", replace: "\n", subject: $text);

            // As a reader reads lines: none after the last new line.
            return \explode("\n", \str_ends_with($text, needle: "\n") ? \substr($text, offset: 0, length: -1) : $text);
        }

        return null;
    }

    /**
     * A sprite of a library, as `sprite $name #library#sprite` names it; null for none.
     *
     * @throws Unsupported for a library not exported
     */
    public static function sprite(string $library, string $name): ?PlantUmlSprite
    {
        $rows = self::library($library)['sprites'][$name] ?? null;

        return null === $rows ? null : PlantUmlSprite::gray16($rows);
    }

    /**
     * @return ?array{info: string, puml: array<string, string>, sprites: array<string, list<string>>} null for a library PlantUML does not have
     * @throws Unsupported for one not exported
     */
    private static function library(string $name): ?array
    {
        /** @var array{libraries: array<string, array{mit: bool, link: ?string}>} $index */
        $index = \json_decode((string) \file_get_contents(self::DIRECTORY.'/stdlib.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        $name = \strtolower($name);
        $entry = $index['libraries'][$name] ?? null;
        if (null === $entry) {
            return null;
        }
        if (null !== $entry['link']) {
            return self::library($entry['link']);
        }
        if (!$entry['mit']) {
            throw new Unsupported('The native PlantUML renderer leaves the standard library '.$name.' to PlantUML');
        }
        if (!\array_key_exists($name, self::$loaded)) {
            /** @var array{info: string, puml: array<string, string>, sprites: array<string, list<string>>} $library */
            $library = \json_decode((string) \file_get_contents(self::DIRECTORY.'/stdlib/'.$name.'.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
            self::$loaded[$name] = $library;
        }

        return self::$loaded[$name];
    }
}
