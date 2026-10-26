<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Sequences\SequenceBox;
use Janalis\Viz\PlantUML\Teoz\Real\Real;
use Janalis\Viz\PlantUML\Teoz\Rose\Area;
use Janalis\Viz\PlantUML\Teoz\Rose\Component;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;

/**
 * A `box` around participants, as teoz lays it out: from 4 left of its first participant's
 * margin to 4 right of its last's, wide enough for its title, its neighbours kept clear.
 */
final class Doll
{
    /** @var list<LivingSpace> */
    private array $spaces = [];

    public function __construct(
        private readonly SequenceBox $box,
        public readonly ?self $parent,
        private readonly TeozContext $context,
    ) {}

    public function add(LivingSpace $space): void
    {
        $this->spaces[] = $space;
    }

    public function titleHeight(): float
    {
        return $this->component()->size()[1];
    }

    /** Its title's height and its parents'. */
    public function offset(): float
    {
        return $this->titleHeight() + ($this->parent?->offset() ?? 0.0);
    }

    /** Widens its first and last participants' margins enough for its title, 10 to spare. */
    public function addInternalConstraints(): void
    {
        $actual = $this->last()->posD()->value() - $this->first()->posB->value();
        $margin = ($this->component()->size()[0] + 10 - $actual) / 2;
        if ($margin > 0) {
            $this->first()->ensureMarginBefore($margin);
            $this->last()->ensureMarginAfter($margin);
        }
    }

    /** Keeps it 10 clear of the participant before it, or of the diagram's left edge, and its padding more. */
    public function addConstraintBefore(): void
    {
        $previous = $this->context->spaces->previous($this->first());
        $this
            ->first()
            ->posALive()
            ->ensureBiggerThan(($previous?->posD() ?? $this->context->xOrigin)->plus(10 + $this->context->rose->boxPadding));
    }

    /** Keeps the participant after it 20 clear of it, and its padding either side more. */
    public function addConstraintAfter(): void
    {
        $this->context
            ->spaces
            ->next($this->last())
            ?->posALive()
            ->ensureBiggerThan(
                $this
                    ->last()
                    ->posELive()
                    ->plus(20 + (2 * $this->context->rose->boxPadding)),
            );
    }

    public function minX(): Real
    {
        return $this->first()->posA();
    }

    public function maxX(): Real
    {
        return $this->last()->posE()->plus(10);
    }

    /** @return list<Mark> the box, under its parents' titles, down to a height */
    public function marks(float $height): array
    {
        $left = $this->first()->posA()->value() - 4;
        $right = $this->last()->posE()->value() + 4;
        $top = 0.0;
        for ($parent = $this->parent; null !== $parent; $parent = $parent->parent) {
            $top += $parent->titleHeight();
        }

        return $this->component()->marks($left, $top + 1, new Area($right - $left, $height - $top), Layer::Background);
    }

    private function component(): Component
    {
        return $this->context->rose->englober($this->box);
    }

    private function first(): LivingSpace
    {
        return $this->spaces[0];
    }

    private function last(): LivingSpace
    {
        return $this->spaces[\count($this->spaces) - 1];
    }
}
