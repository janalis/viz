<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Tile;

use Janalis\Viz\PlantUML\Sequences\SequenceEvent;
use Janalis\Viz\PlantUML\Sequences\SequenceLife;
use Janalis\Viz\PlantUML\Teoz\History;
use Janalis\Viz\PlantUML\Teoz\LivingSpace;
use Janalis\Viz\PlantUML\Teoz\Real\Real;
use Janalis\Viz\PlantUML\Teoz\Rose\Area;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;
use Janalis\Viz\PlantUML\Teoz\TeozContext;
use Janalis\Viz\PlantUML\Teoz\YGauge;

/**
 * A change in a participant's life — an activation, a deactivation, a creation, a destruction
 * — where it comes: no room of its own, but a cross for a destruction no message carries.
 */
final class LifeEventTile extends AbstractTile
{
    private readonly LivingSpace $space;
    private readonly YGauge $gauge;

    public function __construct(
        TeozContext $context,
        private readonly SequenceLife $life,
        YGauge $current,
    ) {
        parent::__construct($context);
        $this->space = $context->spaces->get($life->participant);
        $this->gauge = YGauge::propagating($current, $this->height());
    }

    #[\Override]
    public function event(): SequenceEvent
    {
        return $this->life;
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
        return $this->lone() ? $this->context->rose->destroy()->size()[1] : 0.0;
    }

    #[\Override]
    public function resolved(): void
    {
        $y = $this->gauge->min->value();
        $this->space->addStep($this->life, $y);
        if ('destroy' === $this->life->type) {
            $this->space->destroy($y);
        }
    }

    #[\Override]
    public function marks(Layer $layer): array
    {
        if (!$this->lone()) {
            return [];
        }
        $cross = $this->context->rose->destroy();
        [$width, $height] = $cross->size();

        return $cross->marks($this->space->posC()->value() - ($width / 2), $this->gauge->min->value(), new Area($width, $height), $layer);
    }

    #[\Override]
    public function minX(): Real
    {
        return $this->space->posC()->plus($this->level() > 0 ? -CommunicationTile::LIVE_DELTA_SIZE : 0.0);
    }

    #[\Override]
    public function maxX(): Real
    {
        return $this->space->posC()->plus($this->level() * CommunicationTile::LIVE_DELTA_SIZE);
    }

    private function level(): int
    {
        return $this->space->levelAt($this->life, History::IgnoreFutureActivate);
    }

    /** Whether it is a destruction no message carries, drawn as a cross of its own. */
    private function lone(): bool
    {
        return 'destroy' === $this->life->type && null === $this->context->events->messageOf($this->life);
    }
}
