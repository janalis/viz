<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Teoz\Rose\Pen;
use Janalis\Viz\Source\Unsupported;

/**
 * Where an activity diagram's tiles draw, each at its place: shapes as they come, and arrows
 * held back — merged with the arrows they meet end to start — to be drawn last, as PlantUML's
 * graphics for snakes hold them. With swimlanes, each step draws in its own, moved across by
 * the swimlane's offset, or in place to measure what each swimlane holds.
 */
final class Canvas
{
    private function __construct(
        private readonly CanvasStore $store,
        public readonly float $dx = 0.0,
        public readonly float $dy = 0.0,
        private readonly ?LaneView $lanes = null,
        private readonly ?string $lane = null,
    ) {}

    public static function create(?LaneView $lanes = null): self
    {
        return new self(new CanvasStore(), lanes: $lanes);
    }

    /** The canvas moved by a distance. */
    public function moved(float $dx, float $dy): self
    {
        return new self($this->store, $this->dx + $dx, $this->dy + $dy, $this->lanes, $this->lane);
    }

    /**
     * The canvas drawing in a swimlane, moved across by its offset.
     *
     * @throws Unsupported in a step drawn in another swimlane, which PlantUML draws across both
     */
    public function inLane(string $lane): self
    {
        if (null === $this->lanes || $lane === $this->lane) {
            return $this;
        }
        if (null !== $this->lane) {
            throw new Unsupported('The native PlantUML renderer does not draw a step holding steps of another swimlane');
        }

        return new self($this->store, $this->dx + $this->lanes->offset($lane), $this->dy, $this->lanes, $lane);
    }

    public function add(Shape ...$shapes): void
    {
        $this->laned();
        foreach ($shapes as $shape) {
            $this->store->shapes[] = $shape->moved($this->dx, $this->dy);
            $this->store->shapeLanes[] = $this->lane;
        }
    }

    /** An arrow, merged with one it meets end to start if it can. */
    public function snake(Snake $snake): void
    {
        $this->laned();
        $this->hold($snake->moved($this->dx, $this->dy), $this->lane);
    }

    /**
     * An arrow from one step to the next: drawn in their swimlane when they share it; across
     * from one to the other otherwise, as PlantUML bends it, down to halfway, across, then down
     * — and left out while the swimlanes are measured.
     */
    public function crossing(Snake $snake, ?string $from, ?string $to): void
    {
        if (null === $this->lanes || $from === $to || null === $from || null === $to) {
            (null === $from || $from !== $to ? $this : $this->inLane($from))->snake($snake);

            return;
        }
        if ($this->lanes->measuring) {
            return;
        }
        [$x1, $y1] = $snake->worm->first();
        [$x2, $y2] = $snake->worm->last();
        $x1 += $this->dx + $this->lanes->offset($from);
        $x2 += $this->dx + $this->lanes->offset($to);
        [$y1, $y2] = [$y1 + $this->dy, $y2 + $this->dy];
        $middle = ($y1 + $y2) / 2.0;
        $this->hold($snake->along([[$x1, $y1], [$x1, $middle], [$x2, $middle], [$x2, $y2]]), null);
    }

    /**
     * Every shape, the arrows held back drawn last — each without its head when it ends where
     * another arrow starts.
     *
     * @return list<Shape>
     */
    public function flush(Pen $arrow): array
    {
        return \array_merge(...\array_values($this->flushLanes($arrow)));
    }

    /**
     * Every shape by the swimlane it was drawn in — `''` for none — the arrows held back last.
     *
     * @return array<string, list<Shape>>
     */
    public function flushLanes(Pen $arrow): array
    {
        $byLane = [];
        foreach ($this->store->shapes as $index => $shape) {
            $byLane[$this->store->shapeLanes[$index] ?? ''][] = $shape;
        }
        $snakes = $this->store->snakes;
        foreach ($snakes as $index => $snake) {
            foreach ($snakes as $other) {
                if (!(!$other->untouchable() && Snake::same($snake->worm->last(), $other->worm->first()))) {
                    continue;
                }

                $snakes[$index] = $snake->withoutHead();
                break;
            }
            $lane = $this->store->snakeLanes[$index] ?? '';
            $byLane[$lane] = [...($byLane[$lane] ?? []), ...$snakes[$index]->shapes($arrow)];
        }
        $this->store->snakes = [];
        $this->store->snakeLanes = [];

        return $byLane;
    }

    /** Merged with an arrow it meets end to start, of the same swimlane while they are measured. */
    private function hold(Snake $snake, ?string $lane): void
    {
        foreach ($this->store->snakes as $index => $pending) {
            if ($this->lanes?->measuring && $this->store->snakeLanes[$index] !== $lane) {
                continue;
            }
            $merged = $pending->merge($snake);
            if (null !== $merged) {
                $this->store->snakes[$index] = $merged;

                return;
            }
        }
        $this->store->snakes[] = $snake;
        $this->store->snakeLanes[] = $lane;
    }

    /** @throws Unsupported for what is drawn outside any swimlane once there are swimlanes */
    private function laned(): void
    {
        if (null !== $this->lanes && null === $this->lane) {
            throw new Unsupported('The native PlantUML renderer does not draw what stands outside any swimlane');
        }
    }
}
