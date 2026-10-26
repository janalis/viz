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

/**
 * A reference over participants, framed from the left edge of the leftmost one's head to the
 * right edge of the rightmost one's, which stands at least the frame's width away; its notes
 * beside it, the last left and the last right.
 */
final class ReferenceTile extends AbstractTile
{
    private readonly Component $component;
    private readonly YGauge $gauge;
    private ?Real $first = null;
    private ?Real $last = null;
    /** @var array<'left'|'right', Component> */
    private array $notes = [];

    public function __construct(
        TeozContext $context,
        private readonly SequenceMark $reference,
        YGauge $current,
    ) {
        parent::__construct($context);
        $this->component = $context->rose->reference($reference->text);
        foreach ($reference->notes as $note) {
            $this->notes['left' === $note->position ? 'left' : 'right'] = $context->rose->note($note);
        }
        $this->gauge = YGauge::create($current->max, $this->height());
    }

    #[\Override]
    public function event(): SequenceEvent
    {
        return $this->reference;
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
        [$first, $last] = $this->bounds();
        $top = $this->gauge->min->value();
        $marks = $this->component->marks($first->value(), $top, new Area($last->value() - $first->value(), $this->height()), $layer);
        foreach ($this->notes as $side => $note) {
            [$width, $height] = $note->size();
            \array_push($marks, ...$note->marks('left' === $side ? $first->value() - $width : $last->value(), $top, new Area($width, $height), $layer));
        }

        return $marks;
    }

    #[\Override]
    public function minX(): Real
    {
        return $this->bounds()[0]->plus(-$this->noteWidth('left'));
    }

    #[\Override]
    public function maxX(): Real
    {
        return $this->bounds()[1]->plus($this->noteWidth('right'));
    }

    /** @param 'left'|'right' $side */
    private function noteWidth(string $side): float
    {
        return \array_key_exists($side, $this->notes) ? $this->notes[$side]->size()[0] : 0.0;
    }

    /** @return array{Real, Real} the frame's left and right edges, kept the frame's width apart the first time they are asked for */
    private function bounds(): array
    {
        if (null !== $this->first && null !== $this->last) {
            return [$this->first, $this->last];
        }
        $first = null;
        $last = null;
        foreach ($this->reference->participants as $code) {
            $space = $this->context->spaces->get($code);
            $centre = $space->posC()->value();
            if (null === $first || $centre < $first->value()) {
                $first = $space->posB;
            }
            if (null === $last || $centre > $last->value()) {
                $last = $space->posD();
            }
        }
        \assert(null !== $first && null !== $last, description: 'a reference stands over participants');
        $last = 1 === \count(\array_unique($this->reference->participants)) ? $last->atLeast(0) : $last;
        $last->ensureBiggerThan($first->plus($this->component->size()[0]));
        $this->first = $first;
        $this->last = $last;

        return [$first, $last];
    }
}
