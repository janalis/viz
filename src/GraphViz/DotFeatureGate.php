<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz;

use Janalis\Viz\GraphViz\Model\DotGraph;
use Janalis\Viz\Source\Unsupported;

/**
 * What the native dot drawing leaves to the `dot` command: other layout engines, HTML in
 * attributes that are no labels, and images.
 */
final class DotFeatureGate
{
    /** @throws Unsupported naming the first feature found */
    public static function check(DotGraph $graph): void
    {
        $reason = self::graph($graph) ?? self::objects($graph);
        if (null !== $reason) {
            throw new Unsupported('The native DOT renderer does not draw '.$reason);
        }
    }

    private static function graph(DotGraph $graph): ?string
    {
        $layout = \strtolower($graph->attribute('layout', 'dot'));

        return \in_array($layout, ['', 'dot', 'neato', 'twopi', 'circo', 'fdp', 'sfdp', 'osage', 'patchwork', 'nop', 'nop1', 'nop2'], strict: true) ? null : 'the '.$layout.' layout';
    }

    private static function objects(DotGraph $graph): ?string
    {
        $objects = [[$graph->attributes, $graph->html], ...\array_map(static fn ($subgraph): array => [$subgraph->attributes, $subgraph->html], $graph->allSubgraphs())];
        foreach ($graph->nodes as $node) {
            $objects[] = [$node->attributes, $node->html];
        }
        foreach ($graph->edges as $edge) {
            $objects[] = [$edge->attributes, $edge->html];
        }
        foreach ($objects as [$attributes, $html]) {
            $reason = self::object($attributes, $html);
            if (null !== $reason) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $attributes
     * @param array<string, true> $html
     */
    private static function object(array $attributes, array $html): ?string
    {
        $outside = \array_diff(\array_keys($html), ['label', 'xlabel', 'headlabel', 'taillabel']);
        if ([] !== $outside) {
            return 'HTML in '.\implode(', ', $outside);
        }

        return '' !== ($attributes['image'] ?? '') || '' !== ($attributes['shapefile'] ?? '') ? 'images' : null;
    }
}
