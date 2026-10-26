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

    /**
     * @param list<array{float, float}> $clusterLabels each cluster's label width and height
     * @param list<float> $bands each cluster's label band on its left, for a layout to be turned left to right
     * @param list<float> $lengths each cluster's least length down the ranks: its label's width, for a layout to be turned
     */
    public function __construct(
        private readonly DotLayers $layers,
        private readonly array $clusterLabels,
        public readonly float $nodeSeparation,
        public readonly float $rankSeparation,
        private readonly float $padding = 4.0,
        private readonly array $bands = [],
        private readonly array $lengths = [],
    ) {}

    /** @return \Generator<int, null, mixed, void> */
    public function place(): \Generator
    {
        $vertical = new DotVertical($this->layers, \array_values(\array_column($this->clusterLabels, 1)), $this->rankSeparation, $this->padding, $this->lengths);
        yield null;
        $horizontal = new DotHorizontal($this->layers, \array_values(\array_column($this->clusterLabels, 0)), $this->nodeSeparation, $this->padding, $this->bands);
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

    /**
     * Stretches the positions down and across from inside the padding, the nodes keeping
     * their size: by a factor down and one across, each at least one.
     */
    public function stretch(float $across, float $down): void
    {
        $across = \max(1.0, $across);
        $down = \max(1.0, $down);
        $this->x = \array_map(fn (float $x): float => $this->padding + (($x - $this->padding) * $across), $this->x);
        $this->y = \array_map(fn (float $y): float => $this->padding + (($y - $this->padding) * $down), $this->y);
        $this->boxes = \array_map(fn (array $box): array => [
            $this->padding + (($box[0] - $this->padding) * $across),
            $this->padding + (($box[1] - $this->padding) * $down),
            $this->padding + (($box[2] - $this->padding) * $across),
            $this->padding + (($box[3] - $this->padding) * $down),
        ], $this->boxes);
        $this->width = (2 * $this->padding) + (($this->width - (2 * $this->padding)) * $across);
        $this->height = (2 * $this->padding) + (($this->height - (2 * $this->padding)) * $down);
    }
}
