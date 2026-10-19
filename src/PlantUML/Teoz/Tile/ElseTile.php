<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Tile;

use Janalis\Viz\PlantUML\Sequences\SequenceEvent;
use Janalis\Viz\PlantUML\Sequences\SequenceGroup;
use Janalis\Viz\PlantUML\Teoz\Real\Real;
use Janalis\Viz\PlantUML\Teoz\Rose\Area;
use Janalis\Viz\PlantUML\Teoz\Rose\Component;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;
use Janalis\Viz\PlantUML\Teoz\TeozContext;
use Janalis\Viz\PlantUML\Teoz\YGauge;

/** An `else` in a group: a dashed line across the group's frame, its comment under it. */
final class ElseTile extends AbstractTile
{
    private readonly Component $component;
    private readonly YGauge $gauge;

    public function __construct(
        TeozContext $context,
        public readonly SequenceGroup $else,
        private readonly GroupingTile $parent,
        YGauge $current,
    ) {
        parent::__construct($context);
        $this->component = $context->rose->groupElse($else);
        $this->gauge = YGauge::create($current->max, $this->height());
    }

    #[\Override]
    public function event(): SequenceEvent
    {
        return $this->else;
    }

    #[\Override]
    public function gauge(): YGauge
    {
        return $this->gauge;
    }

    #[\Override]
    public function contact(): float
    {
        return 0.0;
    }

    #[\Override]
    public function height(): float
    {
        return $this->component->size()[1];
    }

    #[\Override]
    public function marks(Layer $layer): array
    {
        $left = $this->parent->frameMinX()->value();
        $area = new Area($this->parent->frameMaxX()->value() - $left, $this->height());

        return $this->component->marks($left, $this->gauge->min->value(), $area, $layer);
    }

    #[\Override]
    public function minX(): Real
    {
        return $this->parent->minX();
    }

    #[\Override]
    public function maxX(): Real
    {
        return $this->minX()->plus($this->component->size()[0]);
    }
}
