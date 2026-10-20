<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz;

use Janalis\Viz\GraphViz\Model\DotGraph;
use Janalis\Viz\Source\Unsupported;

/**
 * What the native dot drawing leaves to the `dot` command: other layout engines, HTML
 * outside labels, images, orthogonal splines, concentrated edges, pinned positions,
 * rotation, compression, colour lists on node and cluster lines and weighted or sloped fills, and radial
 * styles.
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
            // An edge's colour list is drawn natively, as parallel or successive lines.
            $objects[] = [\array_diff_key($edge->attributes, ['color' => true]), $edge->html];
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
        // A list fills with a gradient across, which is drawn natively, unless weighted or sloped.
        $gradients = \array_filter([$attributes['fillcolor'] ?? '', $attributes['bgcolor'] ?? ''], static fn (string $color): bool => \str_contains($color, ':'));
        $angle = $attributes['gradientangle'] ?? '';
        $angle = \is_numeric($angle) ? \fmod((float) $angle, num2: 180.0) : 0.0;

        return match (true) {
            '' !== ($attributes['image'] ?? '') || '' !== ($attributes['shapefile'] ?? '') => 'images',
            '' !== ($attributes['pos'] ?? '') || 'true' === \strtolower($attributes['pin'] ?? '') => 'pinned positions',
            \in_array('radial', $styles, strict: true) => 'radial styles',
            [] !== \array_diff($colors, [$attributes['fillcolor'] ?? '']) || ([] === \array_intersect($styles, ['striped', 'wedged']) && \str_contains(\implode('', $gradients), ';'))
                => 'colour lists',
            [] !== $gradients && 0.0 !== $angle => 'gradients at an angle',
            default => null,
        };
    }
}
