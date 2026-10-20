<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Teoz\Rose\Pen;

/**
 * Where an activity diagram's tiles draw, each at its place: shapes as they come, and arrows
 * held back — merged with the arrows they meet end to start — to be drawn last, as PlantUML's
 * graphics for snakes hold them.
 */
final class Canvas
{
    private function __construct(
        private readonly CanvasStore $store,
        public readonly float $dx = 0.0,
        public readonly float $dy = 0.0,
    ) {}

    public static function create(): self
    {
        return new self(new CanvasStore());
    }

    /** The canvas moved by a distance. */
    public function moved(float $dx, float $dy): self
    {
        return new self($this->store, $this->dx + $dx, $this->dy + $dy);
    }

    public function add(Shape ...$shapes): void
    {
        foreach ($shapes as $shape) {
            $this->store->shapes[] = $shape->moved($this->dx, $this->dy);
        }
    }

    /** An arrow, merged with one it meets end to start if it can. */
    public function snake(Snake $snake): void
    {
        $snake = $snake->moved($this->dx, $this->dy);
        foreach ($this->store->snakes as $index => $pending) {
            $merged = $pending->merge($snake);
            if (null !== $merged) {
                $this->store->snakes[$index] = $merged;

                return;
            }
        }
        $this->store->snakes[] = $snake;
    }

    /**
     * Every shape, the arrows held back drawn last — each without its head when it ends where
     * another arrow starts.
     *
     * @return list<Shape>
     */
    public function flush(Pen $arrow): array
    {
        $snakes = $this->store->snakes;
        foreach ($snakes as $index => $snake) {
            foreach ($snakes as $other) {
                if (!(!$other->untouchable() && Snake::same($snake->worm->last(), $other->worm->first()))) {
                    continue;
                }

                $snakes[$index] = $snake->withoutHead();
                break;
            }
            \array_push($this->store->shapes, ...$snakes[$index]->shapes($arrow));
        }
        $this->store->snakes = [];

        return $this->store->shapes;
    }
}
