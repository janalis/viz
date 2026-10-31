<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Drawing;

use Janalis\Viz\GraphViz\Model\DotGraph;
use Janalis\Viz\GraphViz\Model\DotNode;

/**
 * Where an edge's port puts its end on a node: a record field's or an HTML cell's centre, or a
 * compass point (`n`, `ne`, `e`, `se`, `s`, `sw`, `w`, `nw`) on the side of the field, or of
 * the node's box; `c` or nothing, its centre.
 */
final class DotPorts
{
    /**
     * @param array{float, float, float, float} $box the node's box: left, top, right, bottom
     * @return array{?array{float, float}, ?array{float, float, float, float}, string} the end, null where the port names nothing; the field's box, null for none; and the compass point it names, lower-cased, empty for none or the centre
     */
    public static function anchor(DotGraph $graph, DotNode $node, ?DotRecord $record, string $port, array $box): array
    {
        if ('' === $port) {
            return [null, null, ''];
        }
        $parts = \explode(':', $port);
        $field = $record?->port($parts[0], $box) ?? DotNodes::text($graph, $node)->ports(($box[0] + $box[2]) / 2, ($box[1] + $box[3]) / 2)[$parts[0]] ?? null;
        $compass = null === $field ? $parts[0] : $parts[1] ?? 'c';
        [$left, $top, $right, $bottom] = $field ?? $box;
        [$x, $y] = [($left + $right) / 2, ($top + $bottom) / 2];
        $compass = \strtolower($compass);
        $point = match ($compass) {
            'n' => [$x, $top],
            'ne' => [$right, $top],
            'e' => [$right, $y],
            'se' => [$right, $bottom],
            's' => [$x, $bottom],
            'sw' => [$left, $bottom],
            'w' => [$left, $y],
            'nw' => [$left, $top],
            default => null === $field ? null : [$x, $y],
        };

        return [$point, $field, null === $point || 'c' === $compass || [$x, $y] === $point ? '' : $compass];
    }
}
