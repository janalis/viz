<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Json;

use Janalis\Viz\PlantUML\Language\PlantUmlCaption;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlSource;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\PlantUML\Style\StyleSheet;
use Janalis\Viz\Source\Unsupported;

/**
 * A `@startjson` or `@startyaml` block read as PlantUML's `JsonDiagramFactory` and
 * `YamlDiagramFactory` read one, its lines split as its `StyleExtractor` splits them:
 * `<style>` sheets anywhere; before the data, `title`, `scale`, `skin` and `skinparam` —
 * all but `handwritten` ignored — and `hide`, `!assume` and `!pragma`, ignored too; then
 * `#highlight` lines, and the data, `#` lines left out of JSON.
 */
final class JsonReader
{
    /** The kinds of block read so, and the style of their diagram. */
    public const array KINDS = ['json' => 'jsonDiagram', 'yaml' => 'yamlDiagram'];

    /** @throws Unsupported when PlantUML fails on the data, or on what the native renderer leaves to it */
    public static function read(PlantUmlSource $source): PlantUmlJson
    {
        $styles = StyleSheet::skin();
        $captions = [];
        $scale = 1.0;
        $data = [];
        $lines = $source->lines;
        for ($at = 0, $count = \count($lines); $at < $count; ++$at) {
            $line = $lines[$at];
            $trimmed = \trim($line);
            if ('' === $trimmed) {
                continue;
            }
            if ('<style>' === $trimmed) {
                $sheet = [];
                while (++$at < $count && '</style>' !== \trim($lines[$at])) {
                    $sheet[] = $lines[$at];
                }
                $styles = $styles->with(\implode("\n", $sheet));
                continue;
            }
            $before = [] === $data;
            $match = [];
            match (true) {
                $before && 1 === \preg_match('/^(?:!assume|!pragma|hide) /', $trimmed) => null,
                $before && \str_starts_with($trimmed, 'title ') => $captions['title'] = new PlantUmlCaption(\explode('\n', \trim(\substr($trimmed, offset: 6)))),
                $before && 1 === \preg_match('/^scale\s+([0-9.]+)(?:\s*\/\s*([0-9.]+))?$/', $trimmed, $match) => $scale = self::scale($match),
                $before && \str_starts_with($trimmed, 'scale ') => throw new Unsupported('The native PlantUML renderer leaves scaling to a size to PlantUML'),
                $before && 1 === \preg_match('/^skin\s+(\S+)$/', $trimmed, $match) => $styles = self::skin($match[1]),
                $before && \str_starts_with($trimmed, 'skinparam ') => $at = self::skinparam($lines, $at),
                default => $data[] = $line,
            };
        }
        $highlights = [];
        $kept = [];
        foreach ($data as $line) {
            $highlight = JsonHighlight::of($line);
            if (null !== $highlight) {
                $highlights[] = $highlight;
                continue;
            }
            // Other `#` lines PlantUML leaves out of JSON; YAML reads them as comments.
            if ('json' === $source->kind && \str_starts_with($line, '#')) {
                continue;
            }
            $kept[] = $line;
        }
        $document = new PlantUmlDocument($kept, $styles, [], $captions, $scale, $source->pragmas, $source->kind);
        try {
            $root = 'json' === $source->kind ? JsonParser::parse(\implode("\n", $kept)) : YamlParser::parse($kept);
        } catch (PlantUmlRefusal $refused) {
            throw new Unsupported('PlantUML does not read this '.\strtoupper($source->kind).' data: '.$refused->getMessage(), previous: $refused);
        }

        return new PlantUmlJson($document, self::rooted($root), $highlights);
    }

    /** As PlantUML's `JsonDiagram`: a lone value in an array of its own, and an empty object or array as an array holding an empty string. */
    private static function rooted(JsonNode $root): JsonNode
    {
        return match (true) {
            !$root->container() => JsonNode::array([$root]),
            [] === $root->members => JsonNode::array([JsonNode::string('')]),
            default => $root,
        };
    }

    /** @param array<array-key, string> $match */
    private static function scale(array $match): float
    {
        $scale = \is_numeric($match[1]) ? (float) $match[1] : 0.0;
        $written = '' === ($match[2] ?? '') ? '1' : $match[2];
        $divisor = \is_numeric($written) ? (float) $written : 0.0;
        if ($scale <= 0 || $divisor <= 0) {
            throw new Unsupported('The PlantUML scale '.$match[0].' is not a number');
        }

        return $scale / $divisor;
    }

    private static function skin(string $name): StyleSheet
    {
        if (!\in_array(\strtolower($name), ['rose', 'plantuml'], strict: true)) {
            throw new Unsupported('The native PlantUML renderer does not have the '.$name.' skin');
        }

        return StyleSheet::skin($name);
    }

    /**
     * A `skinparam` line, which PlantUML ignores here but for `handwritten`, left to PlantUML; a
     * `{` block of them up to its `}`.
     *
     * @param list<string> $lines
     * @return int the last line it takes
     */
    private static function skinparam(array $lines, int $at): int
    {
        $line = \trim($lines[$at]);
        if (\str_contains($line, 'handwritten') && \str_contains($line, 'true')) {
            throw new Unsupported('The native PlantUML renderer leaves handwritten JSON and YAML to PlantUML');
        }
        if (!\str_contains($line, '{')) {
            return $at;
        }
        $count = \count($lines);
        while ($at < ($count - 1) && '}' !== \trim($lines[$at])) {
            ++$at;
        }

        return $at;
    }
}
