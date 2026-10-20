<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/**
 * A tile's size, as PlantUML's activity layout measures it: where its flow comes in — `left`
 * across, `inY` down — and where it leaves at the same `left`, `outY` down, unless it does not.
 */
final readonly class Geometry
{
    public function __construct(
        public float $width,
        public float $height,
        public float $left,
        public float $inY,
        /** Where the flow leaves, or null when it does not. */
        public ?float $outY = null,
    ) {}

    public function hasOut(): bool
    {
        return null !== $this->outY;
    }

    /** @return array{float, float} where the flow comes in */
    public function in(): array
    {
        return [$this->left, $this->inY];
    }

    /** @return array{float, float} where the flow leaves */
    public function out(): array
    {
        return [$this->left, (float) $this->outY];
    }

    /** @return array{float, float} the middle of its right side, between in and out */
    public function pointB(): array
    {
        return [$this->width, ($this->inY + (float) $this->outY) / 2];
    }

    /** @return array{float, float} the middle of its left side, between in and out */
    public function pointD(): array
    {
        return [0.0, ($this->inY + (float) $this->outY) / 2];
    }

    public function translate(float $dx, float $dy): self
    {
        return new self($this->width, $this->height, $this->left + $dx, $this->inY + $dy, null === $this->outY ? null : $this->outY + $dy);
    }

    public function withoutOut(): self
    {
        return new self($this->width, $this->height, $this->left, $this->inY);
    }

    public function incVertically(float $top, float $bottom): self
    {
        return new self($this->width, $this->height + $top + $bottom, $this->left, $this->inY + $top, null === $this->outY ? null : $this->outY + $top);
    }

    public function incInY(float $missing): self
    {
        return new self($this->width, $this->height, $this->left, $this->inY + $missing, $this->outY);
    }

    /** Wider and taller, its way out lower by as much. */
    public function addDim(float $width, float $height): self
    {
        return new self($this->width + $width, $this->height + $height, $this->left, $this->inY, null === $this->outY ? null : $this->outY + $height);
    }

    public function fixedHeight(float $height): self
    {
        return new self($this->width, $height, $this->left, $this->inY, $this->outY);
    }

    /** It and another under it, their flows aligned, coming in at its way in and leaving at the other's way out. */
    public function appendBottom(self $other): self
    {
        $left = \max($this->left, $other->left);
        $width = \max($this->width + $left - $this->left, $other->width + $left - $other->left);

        return new self($width, $this->height + $other->height, $left, $this->inY, null === $other->outY ? null : $other->outY + $this->height);
    }
}
