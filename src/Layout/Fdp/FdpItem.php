<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Fdp;

/** One thing a level of an fdp layout places: a node of its own, or a child cluster's box with all it holds. */
final readonly class FdpItem
{
    /**
     * @param array<int, array{float, float}> $inside each node within, by index, its centre from the item's
     * @param array<int, array{float, float, float, float}> $boxes each cluster within, the item's own too, its box from the item's centre
     */
    public function __construct(
        public bool $cluster,
        public float $width,
        public float $height,
        public array $inside,
        public array $boxes = [],
    ) {}

    /** How far it reaches from its centre: half a node's larger side, half a cluster's diagonal. */
    public function half(): float
    {
        return $this->cluster ? \hypot($this->width, $this->height) / 2 : \max($this->width, $this->height) / 2;
    }
}
