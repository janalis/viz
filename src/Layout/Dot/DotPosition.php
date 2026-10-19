<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/**
 * Coordinates for ordered ranks, as Gansner et al. place them: {@see DotVertical} down the
 * ranks, {@see DotHorizontal} across them.
 */
final class DotPosition
{
    /** The space a cluster keeps around what it holds, and from its neighbours. */
    public const float MARGIN = 8.0;

    /** @var list<float> each node's x */
    public array $x = [];
    /** @var list<float> each rank's y */
    public array $y = [];
    /** @var list<array{float, float, float, float}> each cluster's box: left, top, right, bottom */
    public array $boxes = [];
    public float $width = 0.0;
    public float $height = 0.0;

    /** @param list<array{float, float}> $clusterLabels each cluster's label width and height */
    public function __construct(
        private readonly DotLayers $layers,
        private readonly array $clusterLabels,
        private readonly float $nodeSeparation,
        private readonly float $rankSeparation,
        private readonly float $padding = 4.0,
    ) {}

    /** @return \Generator<int, null, mixed, void> */
    public function place(): \Generator
    {
        $vertical = new DotVertical($this->layers, \array_values(\array_column($this->clusterLabels, 1)), $this->rankSeparation, $this->padding);
        yield null;
        $horizontal = new DotHorizontal($this->layers, \array_values(\array_column($this->clusterLabels, 0)), $this->nodeSeparation, $this->padding);
        $work = $horizontal->place();
        yield from $work;
        $this->x = $horizontal->x;
        $this->y = $vertical->y;
        $this->width = $horizontal->width;
        $this->height = $vertical->height;
        foreach ($horizontal->boxes as $cluster => [$left, $right]) {
            [$top, $bottom] = $vertical->boxes[$cluster] ?? [0.0, 0.0];
            $this->boxes[] = [$left, $top, $right, $bottom];
        }
    }
}
