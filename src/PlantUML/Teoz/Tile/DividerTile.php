<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Tile;

use Janalis\Viz\PlantUML\Sequences\SequenceEvent;
use Janalis\Viz\PlantUML\Sequences\SequenceMark;
use Janalis\Viz\PlantUML\Teoz\Real\Real;
use Janalis\Viz\PlantUML\Teoz\Rose\Area;
use Janalis\Viz\PlantUML\Teoz\Rose\Component;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;
use Janalis\Viz\PlantUML\Teoz\TeozContext;
use Janalis\Viz\PlantUML\Teoz\YGauge;

/** A divider, `== text ==`, across the whole diagram, below the tile before. */
final class DividerTile extends AbstractTile
{
    private readonly Component $component;
    private readonly YGauge $gauge;

    public function __construct(
        TeozContext $context,
        private readonly SequenceMark $divider,
        YGauge $current,
    ) {
        parent::__construct($context);
        $this->component = $context->rose->divider($divider->text);
        $this->gauge = YGauge::create($current->max, $this->height());
    }

    #[\Override]
    public function event(): SequenceEvent
    {
        return $this->divider;
    }

    #[\Override]
    public function gauge(): YGauge
    {
        return $this->gauge;
    }

    #[\Override]
    public function height(): float
    {
        return $this->component->size()[1];
    }

    #[\Override]
    public function marks(Layer $layer): array
    {
        $left = $this->context->border1();
        $area = new Area($this->context->border2() - $left - $this->context->xOrigin->value(), $this->height());

        return $this->component->marks($left, $this->gauge->min->value(), $area, $layer);
    }

    #[\Override]
    public function minX(): Real
    {
        return $this->context->xOrigin;
    }

    #[\Override]
    public function maxX(): Real
    {
        return $this->context->xOrigin->plus($this->component->size()[0]);
    }
}
