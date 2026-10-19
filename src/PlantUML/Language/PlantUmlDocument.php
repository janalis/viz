<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Language;

use Janalis\Viz\PlantUML\Style\StyleSheet;
use Janalis\Viz\Source\Unsupported;

/**
 * A PlantUML block with the commands every diagram shares taken out, as PlantUML's common
 * commands read them: `skinparam`s, single and in blocks; `<style>` sheets; `skin`; `title`,
 * `caption`, `header`, `footer` and `legend`, on one line or several; and `scale`. What is left
 * is the diagram's own lines. Sprites, `mainframe`, `newpage`, `rotate` and the like are left
 * to PlantUML.
 */
final readonly class PlantUmlDocument
{
    private const string ANY_TEXT = '/^(?:[\s\x{a0}]*:[\s\x{a0}]*|[\s\x{a0}]+)(?:["\x{201c}\x{201d}](.*)["\x{201c}\x{201d}]|(.*[\p{L}\p{N}_.].*))$/iu';

    /**
     * @param list<string> $lines the diagram's own lines, untrimmed
     * @param array<string, string> $params each `skinparam` by lower-case name, as written
     * @param array<string, PlantUmlCaption> $captions `title`, `caption`, `header`, `footer` and `legend`, where given
     * @param array<string, string> $pragmas
     */
    public function __construct(
        public array $lines,
        public StyleSheet $styles,
        public array $params = [],
        public array $captions = [],
        public float $scale = 1.0,
        public array $pragmas = [],
    ) {}

    /** @throws Unsupported on a shared command left to PlantUML */
    public static function read(PlantUmlSource $source): self
    {
        $reader = new PlantUmlDocumentReader($source->lines);

        return $reader->read($source->pragmas);
    }

    public function param(string $name, string $default = ''): string
    {
        return $this->params[\strtolower($name)] ?? $default;
    }

    /** The text after a keyword such as `title`, quoted or not; null when the line holds none. */
    public static function text(string $rest): ?string
    {
        $match = [];
        if (1 !== \preg_match(self::ANY_TEXT, $rest, $match)) {
            return null;
        }

        return '' !== ($match[2] ?? '') ? $match[2] : $match[1];
    }
}
