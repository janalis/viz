<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz;

use Janalis\Viz\GraphViz\Model\DotGraph;
use Janalis\Viz\Source\Unsupported;

/**
 * What the native dot drawing leaves to the `dot` command: other layout engines, HTML
 * outside labels, images, orthogonal splines, merged edges, pinned positions,
 * rotation, compression, and colour lists and gradient styles.
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

        return match (true) {
            !\in_array($layout, ['', 'dot'], strict: true) => 'the '.$layout.' layout',
            \in_array(\strtolower($graph->attribute('splines')), ['ortho'], strict: true) => 'orthogonal splines',
            'true' === \strtolower($graph->attribute('concentrate')) => 'concentrated edges',
            'compress' === \strtolower($graph->attribute('ratio')) => 'ratio=compress',
            \in_array($graph->attribute('rotate'), ['90', '-90', '270'], strict: true)
                || 'true' === \strtolower($graph->attribute('landscape'))
                || \str_starts_with(\strtolower($graph->attribute('orientation')), 'l')
                => 'rotated drawings',
            default => null,
        };
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
        $outside = \array_diff(\array_keys($html), ['label']);
        if ([] !== $outside) {
            return 'HTML in '.\implode(', ', $outside);
        }
        $styles = \array_map(static fn (string $style): string => \strtolower(\trim($style)), \explode(',', $attributes['style'] ?? ''));
        $colors = \array_filter([$attributes['color'] ?? '', $attributes['fillcolor'] ?? ''], static fn (string $color): bool => \str_contains($color, ':'));

        return match (true) {
            '' !== ($attributes['image'] ?? '') || '' !== ($attributes['shapefile'] ?? '') => 'images',
            '' !== ($attributes['pos'] ?? '') || 'true' === \strtolower($attributes['pin'] ?? '') => 'pinned positions',
            '' !== ($attributes['samehead'] ?? '') || '' !== ($attributes['sametail'] ?? '') => 'merged edge ends',
            [] !== \array_intersect($styles, ['striped', 'wedged', 'radial']) => 'striped, wedged or radial styles',
            [] !== $colors => 'colour lists',
            default => null,
        };
    }
}
