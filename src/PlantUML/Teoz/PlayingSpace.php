<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Sequences\SequenceExo;
use Janalis\Viz\PlantUML\Sequences\SequenceMessage;
use Janalis\Viz\PlantUML\Teoz\Real\Real;
use Janalis\Viz\PlantUML\Teoz\Real\RealMax;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;
use Janalis\Viz\PlantUML\Teoz\Tile\GroupingTile;
use Janalis\Viz\PlantUML\Teoz\Tile\Tile;
use Janalis\Viz\PlantUML\Teoz\Tile\TileBuilder;

/**
 * Where a sequence diagram's tiles play, below the participants' heads: the tiles one under
 * another from 8 down, spanning from the leftmost of them, the boxes and the origin to the
 * rightmost, the participants' heads and activations included.
 */
final class PlayingSpace
{
    private const float STARTING_Y = 8.0;

    /** @var list<Tile> */
    public readonly array $tiles;
    public readonly Real $min;
    public readonly Real $max;

    /** @param list<Doll> $dolls */
    public function __construct(
        private readonly TeozContext $context,
        array $dolls,
    ) {
        $mins = [$context->xOrigin, ...\array_map(static fn (Doll $doll): Real => $doll->minX(), $dolls)];
        $maxs = [$context->xOrigin, ...\array_map(static fn (Doll $doll): Real => $doll->maxX(), $dolls)];
        $start = YGauge::create($context->yOrigin->plus(self::STARTING_Y), 0.0);
        $this->tiles = TileBuilder::several($context, new \ArrayIterator($context->events->events), $start);
        foreach ($this->tiles as $tile) {
            $mins[] = $tile->minX();
            $maxs[] = $tile->maxX();
        }
        foreach ($context->spaces->all() as $space) {
            $maxs[] = $space->posD();
            $maxs[] = $space->posC2();
        }
        $this->min = RealMax::least($mins);
        $this->max = RealMax::of($maxs);
    }

    public function addConstraints(): void
    {
        foreach ($this->tiles as $tile) {
            $tile->addConstraints();
        }
        // Messages written `&` stand side by side: each kept clear of those it goes along with.
        $cluster = [];
        foreach ($this->tiles as $tile) {
            if (!TeozEvents::parallel($tile->event())) {
                $cluster = [$tile];
                continue;
            }
            foreach ($cluster as $other) {
                $this->disjoint($other, $tile);
            }
            $cluster[] = $tile;
        }
    }

    /** Tells every tile, nested ones included, where it touches the lifelines. */
    public function resolve(): void
    {
        foreach ($this->tiles as $tile) {
            $tile->resolved();
        }
    }

    /** @return list<Mark> the tiles' marks, behind the lifelines or in front of them */
    public function marks(Layer $layer): array
    {
        $marks = [];
        foreach ($this->tiles as $tile) {
            \array_push($marks, ...$tile->marks($layer));
        }

        return $marks;
    }

    /** Its height: down to the last tile or the lowest shape drawn, 10 below. */
    public function height(): float
    {
        $last = [] === $this->tiles ? self::STARTING_Y : $this->tiles[\count($this->tiles) - 1]->gauge()->max->value();

        return \max(Marks::bottom($this->marks(Layer::Foreground)), $last) + 10;
    }

    private function disjoint(Tile $a, Tile $b): void
    {
        $anchorA = $this->anchor($a);
        $anchorB = $this->anchor($b);
        if (null === $anchorA || null === $anchorB || $anchorA === $anchorB) {
            return;
        }
        if ($anchorA->posB->value() <= $anchorB->posB->value()) {
            $b->minX()->ensureBiggerThan($a->maxX());

            return;
        }
        $a->minX()->ensureBiggerThan($b->maxX());
    }

    /** The participant a tile leaves from: a message's first, or that of the first message a group holds. */
    private function anchor(Tile $tile): ?LivingSpace
    {
        $event = $tile->event();
        if ($event instanceof SequenceMessage) {
            return $this->context->spaces->get($event->from);
        }
        if ($event instanceof SequenceExo) {
            return $this->context->spaces->get($event->participant);
        }
        if (!$tile instanceof GroupingTile) {
            return null;
        }
        foreach ($tile->tiles() as $child) {
            $anchor = $this->anchor($child);
            if (null !== $anchor) {
                return $anchor;
            }
        }

        return null;
    }
}
