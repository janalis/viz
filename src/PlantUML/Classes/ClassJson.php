<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Classes;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Json\JsonKind;
use Janalis\Viz\PlantUML\Json\JsonNode;
use Janalis\Viz\PlantUML\Json\JsonParser;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\PlantUML\Style\Style;

/**
 * A `json` entity's data, as PlantUML's `TextBlockCucaJSon` sets it: an object as a table of
 * its names beside their values, an array as its items one under another, a value as its text
 * 5 in either side and 2 above and below; a line above each row, and one between an object's
 * names and its values, across the room it is given.
 */
final class ClassJson
{
    /**
     * A `json` entity's body, as PlantUML's `CommandCreateJson` reads it: its lines as the
     * members of an object, else as they stand.
     *
     * @param list<string> $lines
     * @throws PlantUmlRefusal where PlantUML reads no JSON in them
     */
    public static function read(array $lines): JsonNode
    {
        try {
            return JsonParser::parse('{'.\implode('', $lines).'}');
        } catch (PlantUmlRefusal) {
            return JsonParser::parse(\implode('', $lines));
        }
    }

    /** @return array{float, float} */
    public static function size(JsonNode $node, Style $style): array
    {
        if (JsonKind::Object === $node->kind) {
            [$names, $values, $height] = [0.0, 0.0, 0.0];
            foreach ($node->members as [$name, $value]) {
                [$nameSize, $valueSize] = [self::text($name, $style), self::size($value, $style)];
                [$names, $values, $height] = [\max($names, $nameSize[0]), \max($values, $valueSize[0]), $height + \max($nameSize[1], $valueSize[1])];
            }

            return [$names + $values, $height];
        }
        if (JsonKind::Array === $node->kind) {
            [$width, $height] = [0.0, 0.0];
            foreach ($node->members as [, $item]) {
                [$itemWidth, $itemHeight] = self::size($item, $style);
                [$width, $height] = [\max($width, $itemWidth), $height + $itemHeight];
            }

            return [$width, $height];
        }

        return self::text(self::value($node), $style);
    }

    /**
     * @param float $across how far its lines run
     * @return list<Mark>
     */
    public static function marks(JsonNode $node, Style $style, float $x, float $y, float $across, string $line): array
    {
        if (!$node->container()) {
            return self::written(self::value($node), $style, $x, $y);
        }
        $object = JsonKind::Object === $node->kind;
        $names = $object ? \max([0.0, ...\array_map(static fn (array $member): float => self::text($member[0], $style)[0], $node->members)]) : 0.0;
        $marks = $object ? [new Mark(MarkKind::Polyline, [[$x + $names, $y], [$x + $names, $y + self::size($node, $style)[1]]], stroke: $line, strokeWidth: 1.0)] : [];
        foreach ($node->members as $index => [$name, $value]) {
            if ($object || $index > 0) {
                $marks[] = new Mark(MarkKind::Polyline, [[$x, $y], [$x + $across, $y]], stroke: $line, strokeWidth: 1.0);
            }
            \array_push($marks, ...$object ? self::written($name, $style, $x, $y) : [], ...self::marks($value, $style, $x + $names, $y, $across - $names, $line));
            $y += $object ? \max(self::text($name, $style)[1], self::size($value, $style)[1]) : self::size($value, $style)[1];
        }

        return $marks;
    }

    /** A value's text: a string's own, any other as written. */
    private static function value(JsonNode $node): string
    {
        return match ($node->kind) {
            JsonKind::True => 'true',
            JsonKind::False => 'false',
            JsonKind::Null => 'null',
            default => $node->text,
        };
    }

    /** @return array{float, float} a text's size, 5 in either side and 2 above and below */
    private static function text(string $text, Style $style): array
    {
        [$width, $height] = PlantUmlText::styled(\explode("\n", $text), $style)->size();

        return [$width + 10, $height + 4];
    }

    /** @return list<Mark> */
    private static function written(string $text, Style $style, float $x, float $y): array
    {
        $written = PlantUmlText::styled(\explode("\n", $text), $style);

        return $written->marks($x + 5, $y + 2, $written->size()[0], 'left');
    }
}
