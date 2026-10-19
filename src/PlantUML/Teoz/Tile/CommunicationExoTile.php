<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Tile;

use Janalis\Viz\PlantUML\Sequences\SequenceEvent;
use Janalis\Viz\PlantUML\Sequences\SequenceExo;
use Janalis\Viz\PlantUML\Teoz\History;
use Janalis\Viz\PlantUML\Teoz\LivingSpace;
use Janalis\Viz\PlantUML\Teoz\Real\Real;
use Janalis\Viz\PlantUML\Teoz\Rose\Area;
use Janalis\Viz\PlantUML\Teoz\Rose\ArrowComponent;
use Janalis\Viz\PlantUML\Teoz\Rose\ArrowConfig;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;
use Janalis\Viz\PlantUML\Teoz\TeozContext;
use Janalis\Viz\PlantUML\Teoz\YGauge;

/**
 * A message from or to the diagram's edge: its arrow between a lifeline and the left or right
 * border — or only its own width out, written with `?` — its label kept by the participant.
 */
final class CommunicationExoTile extends AbstractTile
{
    private readonly LivingSpace $space;
    private readonly ArrowComponent $component;
    private readonly YGauge $gauge;

    public function __construct(
        TeozContext $context,
        private readonly SequenceExo $message,
        YGauge $current,
    ) {
        parent::__construct($context);
        $this->space = $context->spaces->get($message->participant);
        $arrow = $message->arrow;
        $head = 'async' === $arrow->head2 ? 'async' : 'normal';
        $config = new ArrowConfig($arrow->both ? $head : 'none', $head, body: $arrow->body);
        $this->component = $context->rose->arrow($message->text, $message->number, \in_array($message->type, ['to_left', 'from_right'], strict: true) ? $config->reverse() : $config, $arrow);
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
        $this->space->addStep($this->message, $this->gauge->min->value() + $this->component->startY());
    }

    #[\Override]
    public function marks(Layer $layer): array
    {
        $x1 = $this->fromLeftBorder() ? $this->context->border1() : $this->point1()->value();
        $x2 = $this->fromRightBorder() ? $this->context->border2() : $this->point2()->value();
        $textDeltaX = match (true) {
            $this->fromLeftBorder() => $this->point1()->value() - $x1,
            $this->fromRightBorder() => $this->point2()->value() - $x2,
            default => 0.0,
        };
        $level = $this->space->levelAt($this->message, History::IgnoreFutureDeactivate);
        if ($level > 0 && $this->rightBorder()) {
            $x1 += CommunicationTile::LIVE_DELTA_SIZE * $level;
        }
        if ($level > 0 && !$this->rightBorder()) {
            $x2 += CommunicationTile::LIVE_DELTA_SIZE * ($level - 2);
        }
        $height = $this->component->size()[1];

        return $this->component->marks($x1, $this->gauge->min->value(), new Area($x2 - $x1, $height, textDeltaX: $textDeltaX), $layer);
    }

    #[\Override]
    public function addConstraints(): void
    {
        if (!$this->rightBorder()) {
            $this->space->posC()->ensureBiggerThan($this->context->xOrigin->plus($this->component->size()[0]));
        }
    }

    #[\Override]
    public function minX(): Real
    {
        return $this->point1();
    }

    #[\Override]
    public function maxX(): Real
    {
        return $this->point2();
    }

    private function point1(): Real
    {
        return $this->rightBorder() ? $this->space->posC() : $this->space->posC()->plus(-$this->component->size()[0]);
    }

    private function point2(): Real
    {
        return $this->rightBorder() ? $this->point1()->plus($this->component->size()[0]) : $this->space->posC();
    }

    private function rightBorder(): bool
    {
        return \in_array($this->message->type, ['from_right', 'to_right'], strict: true);
    }

    /** Whether the arrow runs to the left border, not stopping short. */
    private function fromLeftBorder(): bool
    {
        return !$this->rightBorder() && !$this->message->short;
    }

    /** Whether the arrow runs to the right border, not stopping short. */
    private function fromRightBorder(): bool
    {
        return $this->rightBorder() && !$this->message->short;
    }
}
