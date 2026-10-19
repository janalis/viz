<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Tile;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Sequences\SequenceEvent;
use Janalis\Viz\PlantUML\Sequences\SequenceExo;
use Janalis\Viz\PlantUML\Sequences\SequenceMessage;
use Janalis\Viz\PlantUML\Sequences\SequenceNote;
use Janalis\Viz\PlantUML\Teoz\History;
use Janalis\Viz\PlantUML\Teoz\LivingSpace;
use Janalis\Viz\PlantUML\Teoz\Marks;
use Janalis\Viz\PlantUML\Teoz\Real\Real;
use Janalis\Viz\PlantUML\Teoz\Real\RealMax;
use Janalis\Viz\PlantUML\Teoz\Rose\Area;
use Janalis\Viz\PlantUML\Teoz\Rose\Component;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;
use Janalis\Viz\PlantUML\Teoz\Rose\Pen;
use Janalis\Viz\PlantUML\Teoz\TeozContext;
use Janalis\Viz\PlantUML\Teoz\TeozEvents;
use Janalis\Viz\PlantUML\Teoz\YGauge;

/**
 * A note on a message, around the message's own tile: beside the lifeline it leaves or
 * reaches, beside a loop, or above or below the arrow with a dashed line from the arrow's
 * middle to it.
 */
final class MessageNoteTile extends AbstractTile
{
    private const float SPACE_Y = 10.0;

    private readonly Component $note;
    private readonly YGauge $gauge;

    public function __construct(
        TeozContext $context,
        private readonly Tile $tile,
        private readonly SequenceMessage|SequenceExo $message,
        private readonly SequenceNote $source,
        /** The lifeline a note beside a message stands by. */
        private readonly ?LivingSpace $space = null,
    ) {
        parent::__construct($context);
        $this->note = $context->rose->note(
            $this->loop() || \in_array($source->position, ['top', 'bottom'], strict: true) ? new SequenceNote([], $source->position, $source->text, color: $source->color) : $source,
        );
        $inner = $tile->gauge();
        $this->gauge = new YGauge($inner->min, $inner->min->atLeast($this->height()), $inner->contact, $inner->origin);
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
        return $this->tile->contact();
    }

    #[\Override]
    public function height(): float
    {
        $note = $this->note->size()[1];

        return match ($this->source->position) {
            'top', 'bottom' => $this->tile->height() + $note + self::SPACE_Y,
            default => \max($this->tile->height(), $note),
        };
    }

    #[\Override]
    public function resolved(): void
    {
        $this->tile->resolved();
    }

    #[\Override]
    public function addConstraints(): void
    {
        $this->tile->addConstraints();
        if (!$this->tile instanceof CommunicationTileSelf) {
            return;
        }
        $space = $this->tile->space;
        if ('left' === $this->source->position) {
            $overflow = $space->posB->value() - $this->minX()->value();
            $space->ensureMarginBefore(\max(0.0, $overflow));

            return;
        }
        $overflow = $this->maxX()->value() - $space->posD()->value();
        $space->ensureMarginAfter(\max(0.0, $overflow));
    }

    #[\Override]
    public function marks(Layer $layer): array
    {
        [$width, $height] = $this->note->size();
        $y = $this->gauge->min->value();
        $area = new Area($width, $height);
        if ('top' === $this->source->position) {
            return [
                ...Marks::moved($this->tile->marks($layer), 0.0, $height + self::SPACE_Y),
                ...$this->note->marks($this->tile->minX()->value(), $y, $area, $layer),
                ...$this->line($layer, $y + $this->tile->contact() + $height + self::SPACE_Y, $y + $height - 10),
            ];
        }
        if ('bottom' === $this->source->position) {
            return [
                ...$this->tile->marks($layer),
                ...$this->note->marks($this->tile->minX()->value(), $y + $this->tile->height() + self::SPACE_Y, $area, $layer),
                ...$this->line($layer, $y + $this->tile->contact(), $y + $this->tile->height() + self::SPACE_Y + 5),
            ];
        }

        return [...$this->tile->marks($layer), ...$this->note->marks($this->position()->value(), $y, $area, $layer)];
    }

    #[\Override]
    public function minX(): Real
    {
        return 'left' === $this->source->position ? $this->position() : $this->tile->minX();
    }

    #[\Override]
    public function maxX(): Real
    {
        $width = $this->note->size()[0];

        return match ($this->source->position) {
            'left' => $this->tile->maxX(),
            'right' => $this->position()->plus($width),
            default => RealMax::of([$this->tile->maxX(), $this->tile->minX()->plus($width)]),
        };
    }

    #[\Override]
    public function drawnMinX(): Real
    {
        return 'left' === $this->source->position && $this->loop() ? $this->tile->drawnMinX()->plus(-$this->note->size()[0]) : parent::drawnMinX();
    }

    #[\Override]
    public function drawnMaxX(): Real
    {
        return 'right' === $this->source->position && $this->loop() ? $this->tile->drawnMaxX()->plus($this->note->size()[0]) : parent::drawnMaxX();
    }

    /** Whether the note stands on a side of the message: `left`, `right`, `top` or `bottom`. */
    public function beside(string $position): bool
    {
        return $position === $this->source->position;
    }

    /** Where a note beside the message stands: past the loop, or by its lifeline and the activations there. */
    private function position(): Real
    {
        $width = $this->note->size()[0];
        if ($this->loop()) {
            return 'left' === $this->source->position ? $this->tile->minX()->plus(-$width) : $this->tile->maxX();
        }
        \assert(null !== $this->space, description: 'a note beside a message stands by a lifeline');
        if ('left' === $this->source->position) {
            return $this->space->posC()->plus(-$width);
        }
        if ($this->message instanceof SequenceMessage && \in_array('create', TeozEvents::lives($this->message), strict: true)) {
            return $this->space->posD();
        }

        return $this->space->posC()->plus($this->space->levelAt($this->message, History::IgnoreFutureDeactivate) * CommunicationTile::LIVE_DELTA_SIZE);
    }

    private function loop(): bool
    {
        return $this->tile instanceof CommunicationTileSelf;
    }

    /** @return list<Mark> the dashed line from the arrow's middle down or up to the note's middle */
    private function line(Layer $layer, float $from, float $to): array
    {
        if (Layer::Background === $layer) {
            return [];
        }
        $middle = ($this->tile->minX()->value() + $this->tile->maxX()->value()) / 2;
        $pen = new Pen($this->context->rose->lineColor(), 1.0, [2.0, 2.0]);

        return [$pen->line($middle, $from, $this->tile->minX()->value() + ($this->note->size()[0] / 2), $to)];
    }
}
