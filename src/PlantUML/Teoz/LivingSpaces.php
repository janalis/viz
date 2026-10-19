<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;

/** The participants' columns, left to right, each at least 10 clear of the one before. */
final class LivingSpaces
{
    /** @var array<string, LivingSpace> by participant code, in order */
    private array $spaces = [];

    public function put(LivingSpace $space): void
    {
        $this->spaces[$space->participant->code] = $space;
    }

    public function get(string $code): LivingSpace
    {
        return $this->spaces[$code];
    }

    /** @return list<LivingSpace> */
    public function all(): array
    {
        return \array_values($this->spaces);
    }

    public function first(): LivingSpace
    {
        return $this->all()[0];
    }

    public function last(): LivingSpace
    {
        $all = $this->all();

        return $all[\count($all) - 1];
    }

    public function previous(LivingSpace $space): ?LivingSpace
    {
        $all = $this->all();
        $index = (int) \array_search($space, $all, strict: true);

        return $all[$index - 1] ?? null;
    }

    public function next(LivingSpace $space): ?LivingSpace
    {
        $all = $this->all();
        $index = (int) \array_search($space, $all, strict: true);

        return $all[$index + 1] ?? null;
    }

    public function addConstraints(): void
    {
        $previous = null;
        foreach ($this->spaces as $space) {
            if (null !== $previous) {
                $space->posA()->ensureBiggerThan($previous->posE()->plus(10));
            }
            $previous = $space;
        }
    }

    /** The tallest head's height. */
    public function headHeight(): float
    {
        $height = 0.0;
        foreach ($this->spaces as $space) {
            $height = \max($height, $space->headSize()[1]);
        }

        return $height;
    }

    /**
     * The heads along the top, their bottoms aligned, but those created by a message.
     *
     * @return list<Mark>
     */
    public function heads(): array
    {
        $marks = [];
        $height = $this->headHeight();
        foreach ($this->spaces as $space) {
            if ($space->created()) {
                continue;
            }
            \array_push($marks, ...$space->headMarks($space->posB->value(), $height - $space->headSize()[1], Layer::Foreground));
        }

        return $marks;
    }

    /**
     * The feet along the bottom, their tops aligned.
     *
     * @return list<Mark>
     */
    public function feet(): array
    {
        $marks = [];
        foreach ($this->spaces as $space) {
            \array_push($marks, ...$space->footMarks($space->posB->value(), 0.0));
        }

        return $marks;
    }

    /** @return list<Mark> each lifeline and its activations, down from a point to a height */
    public function lifeMarks(float $top, float $height): array
    {
        $marks = [];
        foreach ($this->spaces as $space) {
            \array_push($marks, ...$space->lifeMarks($top, $height));
        }

        return $marks;
    }

    public function delayOn(float $y, float $height): void
    {
        foreach ($this->spaces as $space) {
            $space->delayOn($y, $height);
        }
    }
}
