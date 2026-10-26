<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Tile;

use Janalis\Viz\PlantUML\Sequences\SequenceEvent;
use Janalis\Viz\PlantUML\Sequences\SequenceNote;
use Janalis\Viz\PlantUML\Teoz\History;
use Janalis\Viz\PlantUML\Teoz\LivingSpace;
use Janalis\Viz\PlantUML\Teoz\Real\Real;
use Janalis\Viz\PlantUML\Teoz\Real\RealMax;
use Janalis\Viz\PlantUML\Teoz\Real\RealMiddle;
use Janalis\Viz\PlantUML\Teoz\Real\RealMoveable;
use Janalis\Viz\PlantUML\Teoz\Rose\Area;
use Janalis\Viz\PlantUML\Teoz\Rose\Component;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;
use Janalis\Viz\PlantUML\Teoz\TeozContext;
use Janalis\Viz\PlantUML\Teoz\YGauge;

/**
 * A note on its own row: left or right of a lifeline — right of its activations — over it,
 * or over several, widened from the first's head to the last's; its participants' columns
 * widened to hold it.
 */
final class NoteTile extends AbstractTile
{
    private readonly Component $component;
    private readonly YGauge $gauge;
    /** `left`, `right`, `over` or `several` */
    private readonly string $position;

    public function __construct(
        TeozContext $context,
        private readonly SequenceNote $note,
        public readonly LivingSpace $space1,
        private readonly LivingSpace $space2,
        YGauge $current,
    ) {
        parent::__construct($context);
        $this->position = match (true) {
            'across' === $note->position, 'over' === $note->position && \count($note->participants) > 1 => 'several',
            default => $note->position,
        };
        $this->component = $context->rose->note($note);
        $this->gauge = $note->parallel ? YGauge::parallel($current, $this->contact(), $this->height()) : YGauge::withContact($current, $this->contact(), $this->height());
    }

    #[\Override]
    public function event(): SequenceEvent
    {
        return $this->note;
    }

    #[\Override]
    public function gauge(): YGauge
    {
        return $this->gauge;
    }

    #[\Override]
    public function contact(): float
    {
        return $this->component->size()[1] / 2;
    }

    #[\Override]
    public function height(): float
    {
        return $this->component->size()[1];
    }

    #[\Override]
    public function marks(Layer $layer): array
    {
        return $this->component->marks($this->x()->value(), $this->gauge->min->value(), new Area($this->usedWidth(), $this->height()), $layer);
    }

    #[\Override]
    public function addConstraints(): void
    {
        $overflowLeft = $this->space1->posB->value() - $this->minX()->value();
        if ($overflowLeft > 0) {
            $this->space1->ensureMarginBefore($overflowLeft);
        }
        $anchor = 'several' === $this->position ? $this->space2 : $this->space1;
        $overflowRight = $this->maxX()->value() - $anchor->posD()->value();
        if ($overflowRight > 0) {
            $anchor->ensureMarginAfter($overflowRight);
        }
    }

    #[\Override]
    public function minX(): Real
    {
        return 'several' === $this->position ? RealMax::least([$this->x(), $this->space1->posB]) : $this->x();
    }

    #[\Override]
    public function maxX(): Real
    {
        $right = $this->x()->plus($this->usedWidth());

        return 'several' === $this->position ? RealMax::of([$right, $this->space2->posD()]) : $right;
    }

    /** How far left of its first participant's lifeline the note reaches. */
    public function leftOverhang(): float
    {
        return match ($this->position) {
            'left' => $this->usedWidth(),
            'over' => $this->usedWidth() / 2,
            default => 0.0,
        };
    }

    /** @return list<Real> its right edges that stand still as the layout is solved */
    public function stableMaxX(): array
    {
        $right = [$this->x()->plus($this->usedWidth())];

        return 'several' === $this->position ? [...$right, $this->space2->posD()] : $right;
    }

    /** @return list<Real> its left edges that stand still as the layout is solved */
    public function stableMinX(): array
    {
        return 'several' === $this->position ? [$this->x(), $this->space1->posB] : [$this->x()];
    }

    /** Its width: its own, or for a note over several the span of their heads if wider. */
    private function usedWidth(): float
    {
        $width = $this->component->size()[0];
        if ('several' !== $this->position) {
            return $width;
        }

        return \max($width, $this->space2->posD()->value() - $this->space1->posB->value());
    }

    private function x(): Real
    {
        $width = $this->usedWidth();
        $centre = $this->space1->posC();

        return match ($this->position) {
            'left' => $centre->plus(-$width),
            'right' => $centre->plus($this->space1->levelAt($this->note, History::IgnoreFutureDeactivate) * CommunicationTile::LIVE_DELTA_SIZE),
            'several' => self::middle($centre, $this->space2->posC())->plus(-$width / 2),
            default => $centre->plus(-$width / 2),
        };
    }

    public static function middle(Real $first, Real $second): RealMiddle
    {
        \assert($first instanceof RealMoveable && $second instanceof RealMoveable, description: 'lifelines move');

        return new RealMiddle($first, $second);
    }
}
