<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Model\Direction;

/**
 * Positions stretched to the shape dot's `ratio` asks for.
 */
final class DotAspect
{
    /**
     * As dot's `ratio`, positions stretched until the box its nodes and clusters take is as
     * tall for its width as asked: down where it is too flat, else across; or, asked to fill a
     * size bigger both ways, as big as it; laid out to be turned, the other way. Its edges'
     * labels take no part, and a drawing on a single rank is left as it is, as `dot` is seen to
     * stretch it.
     *
     * @return list<array{float, float}> the box stretched, as two corners, to bound the drawing by; none where it is not stretched
     */
    public static function stretch(LayoutGraph $graph, DotLayers $layers, DotPosition $position): array
    {
        // On a single rank, `dot` is seen to stretch nothing, whatever it is asked.
        if (null === $graph->aspect || $graph->aspect <= 0 || \count($layers->ranks) < 2) {
            return [];
        }
        $box = [\INF, \INF, -\INF, -\INF];
        foreach ($layers->kind as $node => $kind) {
            if (DotLayers::REAL !== $kind) {
                continue;
            }
            $y = $position->y[$layers->rank[$node]];
            $half = $layers->height[$node] / 2;
            $box = [\min($box[0], $position->x[$node] - $layers->left[$node]), \min($box[1], $y - $half), \max($box[2], $position->x[$node] + $layers->right[$node]), \max($box[3], $y + $half)];
        }
        foreach ($position->boxes as [$left, $top, $right, $bottom]) {
            if ($right <= $left || $bottom <= $top) {
                continue;
            }
            $box = [\min($box[0], $left - DotPosition::MARGIN), \min($box[1], $top - DotPosition::MARGIN), \max($box[2], $right + DotPosition::MARGIN), \max($box[3], $bottom + DotPosition::MARGIN)];
        }
        [$width, $height] = [$box[2] - $box[0], $box[3] - $box[1]];
        $turned = \in_array($graph->direction, [Direction::LeftRight, Direction::RightLeft], strict: true);
        [$width, $height] = $turned ? [$height, $width] : [$width, $height];
        // Nodes of no size in a column leave no width to stretch from.
        if ($width <= 0 || $height <= 0) {
            return [];
        }
        $wanted = $graph->aspect * $width;
        [$across, $down] = $height < $wanted ? [1.0, $wanted / $height] : [$height / $wanted, 1.0];
        // Asked to fill a size bigger both ways, it fills it, as `dot` stretches it.
        if (null !== $graph->fill && $width <= $graph->fill[0] && $height <= $graph->fill[1]) {
            [$across, $down] = [$graph->fill[0] / $width, $graph->fill[1] / $height];
        }
        [$across, $down] = $turned ? [$down, $across] : [$across, $down];
        $position->stretch($across, $down, $box[0], $box[1]);

        return [[$box[0], $box[1]], [$box[0] + (($box[2] - $box[0]) * $across), $box[1] + (($box[3] - $box[1]) * $down)]];
    }
}
