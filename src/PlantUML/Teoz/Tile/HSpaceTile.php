<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Tile;

use Janalis\Viz\PlantUML\Sequences\SequenceEvent;
use Janalis\Viz\PlantUML\Sequences\SequenceMark;
use Janalis\Viz\PlantUML\Teoz\Real\Real;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;
use Janalis\Viz\PlantUML\Teoz\TeozContext;
use Janalis\Viz\PlantUML\Teoz\YGauge;

/** Space, `|||` or `||n||`: n down, 25 when not given, and nothing drawn. */
final class HSpaceTile extends AbstractTile
{
    private readonly YGauge $gauge;

    public function __construct(
        TeozContext $context,
        private readonly SequenceMark $space,
        YGauge $current,
    ) {
        parent::__construct($context);
        $this->gauge = YGauge::propagating($current, $this->height());
    }

    #[\Override]
    public function event(): SequenceEvent
    {
        return $this->space;
    }

    #[\Override]
    public function gauge(): YGauge
    {
        return $this->gauge;
    }

    #[\Override]
    public function height(): float
    {
        return (float) ($this->space->size ?? 25);
    }

    #[\Override]
    public function marks(Layer $layer): array
    {
        return [];
    }

    #[\Override]
    public function minX(): Real
    {
        return $this->context->xOrigin;
    }

    #[\Override]
    public function maxX(): Real
    {
        return $this->context->xOrigin->plus(10);
    }
}
