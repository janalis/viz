<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Layout\GraphNode;

/**
 * Pinned `drawFace`: a 15-pixel face with two eyes, smiling above a score of 3, frowning
 * below it and level at it. A face's shape carries its score: `journey-face:SCORE`.
 */
final class JourneyFaces
{
    public static function shape(float $score): string
    {
        return 'journey-face:'.$score;
    }

    /** @return list<Mark> */
    public static function marks(GraphNode $node, string $shape): array
    {
        $text = \substr($shape, offset: 13);
        $score = \is_numeric($text) ? (float) $text : 3.0;
        $x = $node->x;
        $y = $node->y;
        $face = [new Mark(MarkKind::Polygon, self::arc($x, $y, 1.5, 0.0, 2 * \M_PI), stroke: $node->color ?? '#999999', fill: $node->fillColor ?? '#ffffff', strokeWidth: 0.2)];
        foreach ([-0.5, 0.5] as $side) {
            $face[] = new Mark(MarkKind::Polygon, self::arc($x + $side, $y - 0.5, 0.15, 0.0, 2 * \M_PI), stroke: '#666666', fill: '#666666', strokeWidth: 0.1);
        }
        // d3 arcs turn clockwise from twelve o'clock: the smile runs under its center, the frown over it.
        $face[] = match (true) {
            $score > 3 => new Mark(MarkKind::Polyline, self::arc($x, $y + 0.2, 0.72, \M_PI / 2, (3 * \M_PI) / 2), stroke: '#666666', strokeWidth: 0.1),
            $score < 3 => new Mark(MarkKind::Polyline, self::arc($x, $y + 0.7, 0.72, (3 * \M_PI) / 2, (5 * \M_PI) / 2), stroke: '#666666', strokeWidth: 0.1),
            default => new Mark(MarkKind::Polyline, [[$x - 0.5, $y + 0.7], [$x + 0.5, $y + 0.7]], stroke: '#666666', strokeWidth: 0.1),
        };

        return $face;
    }

    /** @return list<array{float, float}> */
    private static function arc(float $x, float $y, float $radius, float $from, float $to): array
    {
        $points = [];
        for ($step = 0; $step <= 24; ++$step) {
            $angle = $from + ((($to - $from) * $step) / 24);
            $points[] = [$x + ($radius * \sin($angle)), $y - ($radius * \cos($angle))];
        }

        return $points;
    }
}
