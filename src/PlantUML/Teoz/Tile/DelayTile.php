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

/** A delay, `...`, its text centred between the first lifeline and the last, every lifeline dotted along it. */
final class DelayTile extends AbstractTile
{
    private readonly Component $component;
    private readonly YGauge $gauge;

    public function __construct(
        TeozContext $context,
        private readonly SequenceMark $delay,
        YGauge $current,
    ) {
        parent::__construct($context);
        $this->component = $context->rose->delay($delay->text);
        $this->gauge = YGauge::create($current->max, $this->height());
    }

    #[\Override]
    public function event(): SequenceEvent
    {
        return $this->delay;
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
    public function resolved(): void
    {
        $this->context->spaces->delayOn($this->gauge->min->value(), $this->height());
    }

    #[\Override]
    public function marks(Layer $layer): array
    {
        return $this->component->marks($this->minX()->value(), $this->gauge->min->value(), new Area($this->component->size()[0], $this->height()), $layer);
    }

    #[\Override]
    public function minX(): Real
    {
        return $this->middle()->plus(-$this->component->size()[0] / 2);
    }

    #[\Override]
    public function maxX(): Real
    {
        return $this->middle()->plus($this->component->size()[0] / 2);
    }

    private function middle(): Real
    {
        return NoteTile::middle($this->context->spaces->first()->posC(), $this->context->spaces->last()->posC());
    }
}
