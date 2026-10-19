<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Tile;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Sequences\SequenceEvent;
use Janalis\Viz\PlantUML\Sequences\SequenceExo;
use Janalis\Viz\PlantUML\Sequences\SequenceGroup;
use Janalis\Viz\PlantUML\Sequences\SequenceLife;
use Janalis\Viz\PlantUML\Sequences\SequenceMark;
use Janalis\Viz\PlantUML\Sequences\SequenceMessage;
use Janalis\Viz\PlantUML\Sequences\SequenceNote;
use Janalis\Viz\PlantUML\Sequences\SequenceNotes;
use Janalis\Viz\PlantUML\Teoz\LivingSpace;
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
 * A group — `alt`, `loop`, `group` and the rest — framed around the tiles it holds, 16 clear
 * of them either side, its header's tab at its top left and its `else` sections each in its
 * own background; the participants beside it kept clear of its frame.
 */
final class GroupingTile extends AbstractTile
{
    public const float EXTERNAL_MARGIN_X1 = 3.0;
    public const float EXTERNAL_MARGIN_X2 = 9.0;
    public const float EXTERNAL_MARGIN_Y = 4.0;
    private const float MARGIN_X = 16.0;
    private const float MARGIN_Y_MAGIC = 20.0;

    private readonly Component $component;
    private readonly YGauge $gauge;
    /** @var list<Tile> */
    private array $tiles = [];
    private readonly Real $min;
    private readonly Real $max;
    private readonly float $bodyHeight;

    /** @param \Iterator<int, SequenceEvent> $events the diagram's events, the group's own up next */
    public function __construct(
        TeozContext $context,
        private readonly SequenceGroup $start,
        \Iterator $events,
        YGauge $current,
    ) {
        parent::__construct($context);
        $this->component = $context->rose->groupHeader($start);
        [$headerWidth, $headerHeight] = $this->component->size();
        $previousMax = $current->max;
        $firstY = $start->parallel ? $current->min : $previousMax;
        $inner = YGauge::create($firstY->atLeast($headerHeight + (self::MARGIN_Y_MAGIC / 2) + self::EXTERNAL_MARGIN_Y), 0.0);
        while ($events->valid()) {
            $event = $events->current();
            \assert(null !== $event, description: 'a valid iterator has a current event');
            $events->next();
            if ($event instanceof SequenceGroup && 'end' === $event->role) {
                break;
            }
            foreach (TileBuilder::one($context, $event, $events, $this, $inner) as $tile) {
                $this->tiles[] = $tile;
                $inner = $tile->gauge();
            }
        }
        $this->bodyHeight = self::bodyHeight($this->tiles);
        $mins = [];
        $maxs = [];
        $elses = [];
        foreach ($this->tiles as $tile) {
            if ($tile instanceof ElseTile) {
                $elses[] = $tile;
                continue;
            }
            if ($tile instanceof HSpaceTile) {
                continue;
            }
            $mins[] = $tile->drawnMinX()->plus(-self::MARGIN_X);
            $maxs[] = $tile->drawnMaxX()->plus(self::MARGIN_X);
        }
        $this->min = RealMax::least([] === $mins ? [$context->xOrigin] : $mins);
        foreach ($elses as $else) {
            $maxs[] = $else->maxX();
        }
        $maxs[] = $this->min->plus($headerWidth + 16);
        $this->max = RealMax::of($maxs);
        $bottom = $firstY->atLeast($this->height());
        if ($start->parallel) {
            $bottom->ensureBiggerThan($previousMax);
        }
        $this->gauge = new YGauge($firstY, $bottom);
    }

    #[\Override]
    public function event(): SequenceEvent
    {
        return $this->start;
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
        return $this->component->size()[1] + $this->bodyHeight + self::MARGIN_Y_MAGIC + (2 * self::EXTERNAL_MARGIN_Y);
    }

    /** @return list<Tile> */
    public function tiles(): array
    {
        return $this->tiles;
    }

    #[\Override]
    public function resolved(): void
    {
        foreach ($this->tiles as $tile) {
            $tile->resolved();
        }
    }

    #[\Override]
    public function marks(Layer $layer): array
    {
        $left = $this->min->value();
        $top = $this->gauge->min->value() + self::EXTERNAL_MARGIN_Y;
        $area = new Area($this->max->value() - $left, $this->bodyHeight + $this->component->size()[1] + (self::MARGIN_Y_MAGIC / 2));
        $marks = Layer::Background === $layer ? $this->background($left, $top, $area) : [];
        \array_push($marks, ...$this->component->marks($left, $top, $area, $layer));
        foreach ($this->tiles as $tile) {
            \array_push($marks, ...$tile->marks($layer));
        }

        return $marks;
    }

    #[\Override]
    public function addConstraints(): void
    {
        foreach ($this->tiles as $tile) {
            $tile->addConstraints();
        }
        $spaces = $this->context->spaces;
        $touched = $this->touched($this->tiles);
        if ([] === $touched) {
            return;
        }
        $rightmost = $touched[\count($touched) - 1];
        $next = $spaces->next($rightmost);
        if (null !== $next) {
            $posA = $next->posA();
            $posA->ensureBiggerThan($rightmost->posC()->plus(self::MARGIN_X + self::EXTERNAL_MARGIN_X2));
            $this->clearRight($this, $posA, self::MARGIN_X + self::EXTERNAL_MARGIN_X2);
        }
        $previous = $spaces->previous($touched[0]);
        if (null !== $previous) {
            $touched[0]->posC()->ensureBiggerThan($previous->posC()->plus(self::MARGIN_X + self::EXTERNAL_MARGIN_X2));
            $this->clearLeft($this, $previous->posC(), self::MARGIN_X + self::EXTERNAL_MARGIN_X2);
        }
    }

    #[\Override]
    public function minX(): Real
    {
        return $this->min->plus(-self::EXTERNAL_MARGIN_X1);
    }

    #[\Override]
    public function maxX(): Real
    {
        return $this->max->plus(self::EXTERNAL_MARGIN_X2);
    }

    public function frameMinX(): Real
    {
        return $this->min;
    }

    public function frameMaxX(): Real
    {
        return $this->max;
    }

    /**
     * The heights of tiles one under another, those written `&` sharing their touch point
     * with the ones before, changes of life taking no room.
     *
     * @param list<Tile> $tiles
     */
    public static function bodyHeight(array $tiles): float
    {
        $total = 0.0;
        $pending = [];
        $cluster = false;
        foreach ($tiles as $tile) {
            if (TeozEvents::parallel($tile->event())) {
                $cluster = true;
                $pending[] = $tile;
                continue;
            }
            if ($tile instanceof LifeEventTile) {
                $pending[] = $tile;
                continue;
            }
            $total += $cluster ? self::together($pending) : self::sum($pending);
            $cluster = false;
            $pending = [$tile];
        }

        return $total + ($cluster ? self::together($pending) : self::sum($pending));
    }

    /** @param list<Tile> $pending tiles one under another */
    private static function sum(array $pending): float
    {
        return \array_sum(\array_map(static fn (Tile $tile): float => $tile->height(), $pending));
    }

    /** @param list<Tile> $pending tiles side by side, sharing their touch point */
    private static function together(array $pending): float
    {
        $contact = 0.0;
        $after = 0.0;
        foreach ($pending as $tile) {
            $contact = \max($contact, $tile->contact());
            $after = \max($after, $tile->height() - $tile->contact());
        }

        return $contact + $after;
    }

    /** @return list<Mark> the group's body filled, each `else` section in its own colour */
    private function background(float $left, float $top, Area $area): array
    {
        $back = $this->context->rose->groupBack($this->start);
        $bands = [];
        $last = $back;
        foreach ($this->tiles as $tile) {
            if (!$tile instanceof ElseTile) {
                continue;
            }
            $color = null === $tile->else->background ? $back : $this->context->rose->groupBack($tile->else);
            if ($color === $last) {
                continue;
            }
            $bands[] = [$tile->gauge()->min->value() - $top + 1, $color];
            $last = $color;
        }
        $bands[] = [$area->height, $back];
        $marks = [];
        $y = 0.0;
        $current = $back;
        foreach ($bands as [$change, $color]) {
            if ('none' !== $current) {
                $marks[] = new Pen($current, 1.0)->rectangle($left, $top + $y, $area->width, $change - $y, $current);
            }
            $y = $change;
            $current = $color;
        }

        return $marks;
    }

    /** Keeps a group's tiles and the titles of its nested groups clear of the participant to its right. */
    private function clearRight(self $group, Real $posA, float $margin): void
    {
        $this->clearTitle($group, $posA, $margin);
        foreach ($group->tiles as $tile) {
            if ($tile instanceof self) {
                $this->clearRight($tile, $posA, $margin + self::MARGIN_X + self::EXTERNAL_MARGIN_X2);
                continue;
            }
            foreach (self::stableMaxX($tile) as $maxX) {
                $posA->ensureBiggerThan($maxX->plus($margin));
            }
        }
    }

    /** Keeps a group's tiles clear of the participant to its left. */
    private function clearLeft(self $group, Real $posC, float $margin): void
    {
        foreach ($group->tiles as $tile) {
            if ($tile instanceof self) {
                $this->clearLeft($tile, $posC, $margin + self::MARGIN_X + self::EXTERNAL_MARGIN_X2);
                continue;
            }
            foreach (self::stableMinX($tile) as $minX) {
                $minX->ensureBiggerThan($posC->plus($margin));
            }
        }
    }

    /** Keeps a group's header clear of the participant to its right. */
    private function clearTitle(self $group, Real $posA, float $margin): void
    {
        $touched = $this->touched($group->tiles);
        if ([] === $touched) {
            return;
        }
        $overhang = 0.0;
        foreach ($group->tiles as $tile) {
            if (!$tile instanceof NoteTile || $tile->space1 !== $touched[0]) {
                continue;
            }
            $overhang = \max($overhang, $tile->leftOverhang());
        }
        $width = $group->component->size()[0];
        $posA->ensureBiggerThan($touched[0]->posC()->plus($width + 16 - self::MARGIN_X - self::MARGIN_X - $overhang + $margin));
    }

    /** @return list<Real> */
    private static function stableMaxX(Tile $tile): array
    {
        return match (true) {
            $tile instanceof NoteTile => $tile->stableMaxX(),
            $tile instanceof CommunicationTileSelf,
            $tile instanceof LifeEventTile,
            $tile instanceof ReferenceTile,
            $tile instanceof DividerTile,
            $tile instanceof MessageNoteTile && $tile->beside('right'),
                => [$tile->drawnMaxX()],
            default => [],
        };
    }

    /** @return list<Real> */
    private static function stableMinX(Tile $tile): array
    {
        return match (true) {
            $tile instanceof NoteTile => $tile->stableMinX(),
            $tile instanceof CommunicationTileSelf, $tile instanceof LifeEventTile, $tile instanceof ReferenceTile, $tile instanceof MessageNoteTile && $tile->beside('left') => [$tile->drawnMinX()],
            default => [],
        };
    }

    /**
     * The participants tiles deal with, nested groups' included, left to right.
     *
     * @param list<Tile> $tiles
     * @return list<LivingSpace>
     */
    private function touched(array $tiles): array
    {
        $codes = [];
        $this->collect($tiles, $codes);

        return \array_values(\array_filter($this->context->spaces->all(), static fn (LivingSpace $space): bool => \array_key_exists($space->participant->code, $codes)));
    }

    /**
     * @param list<Tile> $tiles
     * @param array<string, true> $codes
     */
    private function collect(array $tiles, array &$codes): void
    {
        foreach ($tiles as $tile) {
            $event = $tile->event();
            foreach (self::participants($event) as $code) {
                $codes[$code] = true;
            }
            if ($event instanceof SequenceNote && [] === $event->participants) {
                foreach ($this->context->spaces->all() as $space) {
                    $codes[$space->participant->code] = true;
                }
            }
            if ($tile instanceof self) {
                $this->collect($tile->tiles, $codes);
            }
        }
    }

    /** @return list<string> the participants an event deals with */
    private static function participants(SequenceEvent $event): array
    {
        return match (true) {
            $event instanceof SequenceMessage => [$event->from, $event->to],
            $event instanceof SequenceExo, $event instanceof SequenceLife => [$event->participant],
            $event instanceof SequenceNote, $event instanceof SequenceMark => $event->participants,
            $event instanceof SequenceNotes => \array_merge(...\array_map(static fn (SequenceNote $note): array => $note->participants, $event->notes)),
            default => [],
        };
    }
}
