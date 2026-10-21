<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

use Janalis\Viz\Source\Unsupported;

/**
 * The themes PlantUML bundles, as its `ThemeUtils` loads them: of those, the ones whose own
 * header licenses them under MIT are copied into resources/plantuml/themes, their header —
 * between two `---` lines — read as metadata and left out of their lines.
 */
final readonly class TimThemes
{
    private const string DIRECTORY = __DIR__.'/../../../resources/plantuml';

    /** @param list<string> $lines */
    private function __construct(
        public array $lines,
        public JsonValue $metadata,
    ) {}

    /** @throws Unsupported for a theme not copied, which PlantUML may find bundled or beside the source */
    public static function read(string $name): self
    {
        if (!\in_array($name, self::index()['mit'], strict: true)) {
            throw new Unsupported('The native PlantUML renderer leaves the theme '.$name.' to PlantUML');
        }
        $text = \str_replace("\r\n", replace: "\n", subject: (string) \file_get_contents(self::DIRECTORY.'/themes/puml-theme-'.$name.'.puml'));
        // As a reader reads lines: none after the last new line.
        $lines = \explode("\n", \str_ends_with($text, needle: "\n") ? \substr($text, offset: 0, length: -1) : $text);
        $metadata = [];
        if ('---' === $lines[0]) {
            $end = (int) \array_search('---', \array_slice($lines, offset: 1), strict: true) + 1;
            foreach (\array_slice($lines, offset: 1, length: $end - 1) as $entry) {
                $match = [];
                \preg_match('/^([^:]+):(.*)$/', $entry, $match);
                \array_push($metadata, ...\array_key_exists(2, $match) ? [[\trim($match[1]), JsonValue::string(\trim($match[2]))]] : []);
            }
            $lines = \array_slice($lines, $end + 1);
        }

        return new self($lines, JsonValue::object($metadata));
    }

    /** @return list<string> every theme PlantUML bundles, by name */
    public static function names(): array
    {
        return self::index()['all'];
    }

    /** @return array{all: list<string>, mit: list<string>} */
    private static function index(): array
    {
        /** @var array{all: list<string>, mit: list<string>} */
        return \json_decode((string) \file_get_contents(self::DIRECTORY.'/themes.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
    }
}
