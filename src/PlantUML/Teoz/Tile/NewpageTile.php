<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Tile;

use Janalis\Viz\PlantUML\Sequences\SequenceEvent;
use Janalis\Viz\PlantUML\Sequences\SequenceMark;
use Janalis\Viz\PlantUML\Teoz\Real\Real;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;
use Janalis\Viz\PlantUML\Teoz\Rose\Pen;
use Janalis\Viz\PlantUML\Teoz\TeozContext;
use Janalis\Viz\PlantUML\Teoz\YGauge;

/**
 * Where a page ends, as teoz's `NewpageTile` draws it at the foot of the page it ends: a line
 * 1 tall across the diagram in the `newpage` style, 10 clear above and below.
 */
final class NewpageTile extends AbstractTile
{
    private const float MARGIN_Y = 10.0;

    private readonly YGauge $gauge;

    public function __construct(
        TeozContext $context,
        private readonly SequenceMark $newpage,
        YGauge $current,
    ) {
        parent::__construct($context);
        $this->gauge = YGauge::create($current->max, $this->height());
    }

    #[\Override]
    public function event(): SequenceEvent
    {
        return $this->newpage;
    }

    #[\Override]
    public function gauge(): YGauge
    {
        return $this->gauge;
    }

    #[\Override]
    public function height(): float
    {
        return 1 + (2 * self::MARGIN_Y);
    }

    #[\Override]
    public function marks(Layer $layer): array
    {
        if (Layer::Background === $layer) {
            return [];
        }
        $left = $this->context->border1();
        $y = $this->gauge->min->value() + self::MARGIN_Y;

        return [Pen::of($this->context->rose->style('newpage'))->path([[$left, $y], [$this->context->border2() - $this->context->xOrigin->value(), $y]])];
    }

    #[\Override]
    public function minX(): Real
    {
        return $this->context->xOrigin;
    }

    #[\Override]
    public function maxX(): Real
    {
        return $this->context->xOrigin;
    }
}
