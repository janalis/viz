<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz;

use Janalis\Viz\GraphViz\Model\DotGraph;
use Janalis\Viz\Source\Unsupported;

/**
 * What the native dot drawing leaves to the `dot` command: other layout engines, HTML
 * outside labels, images, orthogonal splines, compression, colour lists on node and cluster
 * lines, and radial gradients at an angle.
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
            'compress' === \strtolower($graph->attribute('ratio')) => 'ratio=compress',
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
        // A list fills with a gradient, drawn natively at any angle, unless radial: its angle moves its focus.
        $gradients = \array_filter([$attributes['fillcolor'] ?? '', $attributes['bgcolor'] ?? ''], static fn (string $color): bool => \str_contains($color, ':'));
        $angle = $attributes['gradientangle'] ?? '';
        $angle = \is_numeric($angle) ? \fmod((float) $angle, num2: 360.0) : 0.0;

        return match (true) {
            '' !== ($attributes['image'] ?? '') || '' !== ($attributes['shapefile'] ?? '') => 'images',
            [] !== \array_diff($colors, [$attributes['fillcolor'] ?? '']) => 'colour lists',
            [] !== $gradients && 0.0 !== $angle && \in_array('radial', $styles, strict: true) => 'radial gradients at an angle',
            default => null,
        };
    }
}
