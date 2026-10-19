<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Tile;

use Janalis\Viz\PlantUML\Sequences\SequenceEvent;
use Janalis\Viz\PlantUML\Sequences\SequenceMessage;
use Janalis\Viz\PlantUML\Teoz\History;
use Janalis\Viz\PlantUML\Teoz\LivingSpace;
use Janalis\Viz\PlantUML\Teoz\Real\Real;
use Janalis\Viz\PlantUML\Teoz\Rose\Area;
use Janalis\Viz\PlantUML\Teoz\Rose\ArrowConfig;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;
use Janalis\Viz\PlantUML\Teoz\Rose\SelfArrow;
use Janalis\Viz\PlantUML\Teoz\TeozContext;
use Janalis\Viz\PlantUML\Teoz\TeozEvents;
use Janalis\Viz\PlantUML\Teoz\YGauge;

/**
 * A message from a participant to itself: its loop out to the right — to the left when written
 * right to left — past the participant's activations, the next participant kept clear of it.
 */
final class CommunicationTileSelf extends AbstractTile
{
    public readonly LivingSpace $space;
    private readonly SelfArrow $component;
    private readonly YGauge $gauge;

    public function __construct(
        TeozContext $context,
        private readonly SequenceMessage $message,
        YGauge $current,
    ) {
        parent::__construct($context);
        $this->space = $context->spaces->get($message->from);
        $component = $context->rose->arrow($message->text, $message->number, ArrowConfig::of($message->arrow)->selfArrow(), $message->arrow);
        \assert($component instanceof SelfArrow, description: 'a message to itself is drawn as a loop');
        $this->component = $component;
        $this->gauge = $message->parallel ? YGauge::parallel($current, $this->contact(), $this->height()) : YGauge::withContact($current, $this->contact(), $this->height());
    }

    #[\Override]
    public function event(): SequenceEvent
    {
        return $this->message;
    }

    #[\Override]
    public function gauge(): YGauge
    {
        return $this->gauge;
    }

    #[\Override]
    public function contact(): float
    {
        return $this->component->yPoint();
    }

    #[\Override]
    public function height(): float
    {
        return $this->component->size()[1];
    }

    #[\Override]
    public function resolved(): void
    {
        $y = $this->gauge->min->value();
        $lives = TeozEvents::lives($this->message);
        if (\in_array('activate', $lives, strict: true) || (!\in_array('deactivate', $lives, strict: true) && \in_array('destroy', $lives, strict: true))) {
            $this->space->addStep($this->message, $y + $this->component->endY());

            return;
        }
        if (\in_array('deactivate', $lives, strict: true)) {
            $this->space->addStep($this->message, $y + $this->component->startY());
        }
    }

    #[\Override]
    public function marks(Layer $layer): array
    {
        $x = $this->minX()->value();
        $ignore = $this->space->levelAt($this->message, History::IgnoreFutureActivate);
        $consider = $this->space->levelAt($this->message, History::ConsiderFutureDeactivate);
        if (!$this->message->arrow->reversed) {
            $x += (CommunicationTile::LIVE_DELTA_SIZE * $ignore) + (CommunicationTile::LIVE_DELTA_SIZE * \max(0, $consider - $ignore));
        }
        [$width, $height] = $this->component->size();
        $area = new Area($width, $height, ($ignore - $consider) * CommunicationTile::LIVE_DELTA_SIZE, $ignore, CommunicationTile::LIVE_DELTA_SIZE);

        return $this->component->marks($x, $this->gauge->min->value(), $area, $layer);
    }

    #[\Override]
    public function addConstraints(): void
    {
        $spaces = $this->context->spaces;
        if ($this->message->arrow->reversed) {
            $previous = $spaces->previous($this->space);
            if (null !== $previous) {
                $this->space->posC()->ensureBiggerThan($previous->posC2()->plus($this->component->size()[0]));
            }
            $overflow = $this->space->posB->value() - $this->minX()->value();
            if ($overflow > 0) {
                $this->space->ensureMarginBefore($overflow);
            }

            return;
        }
        $spaces->next($this->space)?->posC()->ensureBiggerThan($this->maxX());
        $overflow = $this->maxX()->value() - $this->space->posD()->value();
        if ($overflow > 0) {
            $this->space->ensureMarginAfter($overflow);
        }
    }

    #[\Override]
    public function minX(): Real
    {
        return $this->message->arrow->reversed ? $this->space->posC()->plus(-$this->component->size()[0] - $this->activeShift()) : $this->space->posC();
    }

    #[\Override]
    public function maxX(): Real
    {
        return $this->message->arrow->reversed ? $this->space->posC2() : $this->space->posC2()->plus($this->component->size()[0]);
    }

    #[\Override]
    public function drawnMinX(): Real
    {
        return $this->message->arrow->reversed ? $this->space->posC()->plus(-$this->component->drawnWidth() - $this->activeShift()) : $this->minX();
    }

    #[\Override]
    public function drawnMaxX(): Real
    {
        return $this->message->arrow->reversed ? $this->maxX() : $this->space->posC2()->plus($this->component->drawnWidth());
    }

    /** How far left a loop written right to left goes past the activation it leaves. */
    private function activeShift(): float
    {
        return $this->space->levelAt($this->message, History::IgnoreFutureActivate) > 0 ? CommunicationTile::LIVE_DELTA_SIZE : 0.0;
    }
}
