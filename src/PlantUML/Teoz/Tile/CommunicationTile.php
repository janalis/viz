<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Tile;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Sequences\SequenceEvent;
use Janalis\Viz\PlantUML\Sequences\SequenceMessage;
use Janalis\Viz\PlantUML\Teoz\History;
use Janalis\Viz\PlantUML\Teoz\LivingSpace;
use Janalis\Viz\PlantUML\Teoz\Real\Real;
use Janalis\Viz\PlantUML\Teoz\Real\RealMax;
use Janalis\Viz\PlantUML\Teoz\Rose\Area;
use Janalis\Viz\PlantUML\Teoz\Rose\ArrowComponent;
use Janalis\Viz\PlantUML\Teoz\Rose\ArrowConfig;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;
use Janalis\Viz\PlantUML\Teoz\TeozContext;
use Janalis\Viz\PlantUML\Teoz\TeozEvents;
use Janalis\Viz\PlantUML\Teoz\YGauge;

/**
 * A message between two participants: its arrow from one lifeline to the other, its ends
 * moved by the activations there, and the head of a participant it creates at its end. The
 * participants stand at least the arrow's width apart.
 */
final class CommunicationTile extends AbstractTile
{
    /** How far each level of activation moves an arrow's end. */
    public const float LIVE_DELTA_SIZE = 5.0;

    private readonly LivingSpace $space1;
    private readonly LivingSpace $space2;
    private readonly ArrowComponent $component;
    private readonly YGauge $gauge;

    public function __construct(
        TeozContext $context,
        private readonly SequenceMessage $message,
        YGauge $current,
    ) {
        parent::__construct($context);
        $this->space1 = $context->spaces->get($message->from);
        $this->space2 = $context->spaces->get($message->to);
        if ($this->create()) {
            $this->space2->create();
        }
        $config = ArrowConfig::of($message->arrow);
        $this->component = $context->rose->arrow($message->text, $message->number, $this->reverse() ? $config->reverse() : $config, $message->arrow);
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

    /** Whether the message goes right to left. */
    public function reverse(): bool
    {
        return $this->space1->posC()->value() > $this->space2->posC()->value();
    }

    #[\Override]
    public function contact(): float
    {
        return $this->component->yPoint();
    }

    #[\Override]
    public function height(): float
    {
        $height = $this->component->size()[1];

        return $this->create() ? \max($height, $this->space2->headSize()[1]) : $height;
    }

    #[\Override]
    public function resolved(): void
    {
        $y = $this->gauge->min->value();
        if ($this->create()) {
            $this->space2->create($y);
        }
        $this->space1->addStep($this->message, $y + $this->component->startY());
        $this->space2->addStep($this->message, $y + $this->component->startY());
    }

    #[\Override]
    public function marks(Layer $layer): array
    {
        if ($this->message->endAnchored) {
            return [];
        }
        $y = $this->gauge->min->value();
        $x1 = $this->space1->posC()->value();
        $x2 = $this->point2()->value();
        $level1 = $this->space1->levelAt($this->message, History::IgnoreFutureDeactivate);
        $level2 = $this->space2->levelAt($this->message, History::IgnoreFutureDeactivate);
        $height = $this->component->size()[1];
        $marks = [];
        if ($this->reverse()) {
            $x1 += match (true) {
                1 === $level1 => -self::LIVE_DELTA_SIZE,
                $level1 > 2 => self::LIVE_DELTA_SIZE * ($level1 - 2),
                default => 0.0,
            };
            $x2 += self::LIVE_DELTA_SIZE * $level2;
            if ($this->create()) {
                $marks = $this->space2->headMarks($x2 - $this->space2->headSize()[0], $y, $layer);
            }

            return [...$marks, ...$this->component->marks($x2, $y, new Area($x1 - $x2, $height), $layer)];
        }
        $x1 += self::LIVE_DELTA_SIZE * $level1;
        $x2 += self::LIVE_DELTA_SIZE * ($level2 > 0 ? $level2 - 2 : 0);
        if ($this->create()) {
            $marks = $this->space2->headMarks($x2, $y, $layer);
        }

        return [...$marks, ...$this->component->marks($x1, $y, new Area($x2 - $x1, $height), $layer), ...$this->multicast($y, $layer)];
    }

    /**
     * The arrows to the participants it is also sent to, as teoz draws them: from the sender's
     * lifeline to each one's, unlabelled, each 2 below the one before.
     *
     * @return list<Mark>
     */
    private function multicast(float $y, Layer $layer): array
    {
        $x1 = $this->space1->posC()->value();
        $marks = [];
        foreach ($this->message->multicast as $index => $code) {
            $x2 = $this->context->spaces->get($code)->posC()->value();
            $config = ArrowConfig::of($this->message->arrow);
            $arrow = $this->context->rose->arrow([], null, $x2 < $x1 ? $config->reverse() : $config, $this->message->arrow);
            $top = $y + $this->component->yPoint() + (2 * ($index + 1)) - $arrow->yPoint();
            \array_push($marks, ...$arrow->marks(\min($x1, $x2), $top, new Area(\abs($x2 - $x1), $arrow->size()[1]), $layer));
        }

        return $marks;
    }

    #[\Override]
    public function addConstraints(): void
    {
        $width = $this->component->size()[0];
        $point1 = $this->space1->posC();
        $point2 = $this->point2();
        $level2 = $this->space2->levelAt($this->message, History::IgnoreFutureDeactivate);
        if ($this->reverse()) {
            $level1 = $this->space1->levelAt($this->message, History::IgnoreFutureDeactivate);
            $point1 = $level1 > 0 ? $point1->plus(-self::LIVE_DELTA_SIZE) : $point1;
            $point1->ensureBiggerThan($point2->plus(($level2 * self::LIVE_DELTA_SIZE) + $width));

            return;
        }
        $point2 = $level2 > 0 ? $point2->plus(-self::LIVE_DELTA_SIZE) : $point2;
        $point2->ensureBiggerThan($point1->plus($width));
    }

    #[\Override]
    public function minX(): Real
    {
        return $this->reverse() ? $this->point2() : $this->space1->posC();
    }

    #[\Override]
    public function maxX(): Real
    {
        [$left, $right] = $this->reverse() ? [$this->point2(), $this->space1->posC()] : [$this->space1->posC(), $this->point2()];

        return RealMax::of([$right, $left->plus($this->component->size()[0])]);
    }

    /** Where the arrow ends: the lifeline it reaches, or the edge of the head of the participant it creates. */
    private function point2(): Real
    {
        if (!$this->create()) {
            return $this->space2->posC();
        }

        return $this->reverse() ? $this->space2->posD() : $this->space2->posB;
    }

    private function create(): bool
    {
        return \in_array('create', TeozEvents::lives($this->message), strict: true);
    }
}
