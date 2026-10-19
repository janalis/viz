<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Tile;

use Janalis\Viz\PlantUML\Sequences\SequenceEvent;
use Janalis\Viz\PlantUML\Sequences\SequenceNote;
use Janalis\Viz\PlantUML\Sequences\SequenceNotes;
use Janalis\Viz\PlantUML\Teoz\History;
use Janalis\Viz\PlantUML\Teoz\Real\Real;
use Janalis\Viz\PlantUML\Teoz\Real\RealMax;
use Janalis\Viz\PlantUML\Teoz\Rose\Area;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;
use Janalis\Viz\PlantUML\Teoz\TeozContext;
use Janalis\Viz\PlantUML\Teoz\YGauge;

/**
 * Notes side by side on one row, each where it would stand alone — those on the same side of
 * the same lifeline stacked outward — kept from overlapping one another.
 */
final class NotesTile extends AbstractTile
{
    private readonly YGauge $gauge;

    public function __construct(
        TeozContext $context,
        private readonly SequenceNotes $notes,
        YGauge $current,
    ) {
        parent::__construct($context);
        $this->gauge = YGauge::withContact($current, $this->contact(), $this->height());
    }

    #[\Override]
    public function event(): SequenceEvent
    {
        return $this->notes;
    }

    #[\Override]
    public function gauge(): YGauge
    {
        return $this->gauge;
    }

    #[\Override]
    public function contact(): float
    {
        return $this->height() / 2;
    }

    #[\Override]
    public function height(): float
    {
        $height = 0.0;
        foreach ($this->notes->notes as $note) {
            $height = \max($height, $this->context->rose->note($note)->size()[1]);
        }

        return $height;
    }

    #[\Override]
    public function marks(Layer $layer): array
    {
        $marks = [];
        foreach ($this->notes->notes as $note) {
            $component = $this->context->rose->note($note);
            [$width, $height] = $component->size();
            \array_push($marks, ...$component->marks($this->x($note)->value(), $this->gauge->min->value(), new Area($width, $height), $layer));
        }

        return $marks;
    }

    #[\Override]
    public function addConstraints(): void
    {
        $notes = $this->notes->notes;
        foreach ($notes as $i => $first) {
            foreach (\array_slice($notes, $i + 1) as $second) {
                $centre1 = $this->centre($first)->value();
                $centre2 = $this->centre($second)->value();
                if ($centre1 === $centre2) {
                    continue;
                }
                if ($centre2 > $centre1) {
                    $this->x($second)->ensureBiggerThan($this->x2($first));
                    continue;
                }
                $this->x($first)->ensureBiggerThan($this->x2($second));
            }
        }
    }

    #[\Override]
    public function minX(): Real
    {
        return RealMax::least(\array_map($this->x(...), $this->notes->notes));
    }

    #[\Override]
    public function maxX(): Real
    {
        return RealMax::of(\array_map($this->x2(...), $this->notes->notes));
    }

    private function centre(SequenceNote $note): Real
    {
        return $this->context->spaces->get($note->participants[0])->posC();
    }

    private function x(SequenceNote $note): Real
    {
        $space = $this->context->spaces->get($note->participants[0]);
        $width = $this->width($note);

        return match (true) {
            'left' === $note->position => $space->posC()->plus(-$width - $this->stacking($note)),
            'right' === $note->position => $space->posC()->plus(($space->levelAt($this->notes, History::IgnoreFutureDeactivate) * CommunicationTile::LIVE_DELTA_SIZE) + $this->stacking($note)),
            \count($note->participants) > 1 => NoteTile::middle($space->posC(), $this->context->spaces->get($note->participants[1])->posC())->plus(-$width / 2),
            default => $space->posC()->plus(-$width / 2),
        };
    }

    private function x2(SequenceNote $note): Real
    {
        return $this->x($note)->plus($this->width($note));
    }

    private function width(SequenceNote $note): float
    {
        return $this->context->rose->note($note)->size()[0];
    }

    /** How far out the notes before it on the same side of the same lifeline push it. */
    private function stacking(SequenceNote $note): float
    {
        $offset = 0.0;
        foreach ($this->notes->notes as $other) {
            if ($other === $note) {
                break;
            }
            if ($other->position === $note->position && $other->participants[0] === $note->participants[0]) {
                $offset += $this->width($other);
            }
        }

        return $offset;
    }
}
