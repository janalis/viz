<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Activities\Instruction\Branch;

/** A decision's branch with its tile: its steps, its test and labels, and the colours of its arrows in and out. */
final readonly class BranchTile
{
    public function __construct(
        public Branch $branch,
        public Ftile $tile,
        private Rainbow $arrow,
    ) {}

    public function empty(): bool
    {
        return $this->branch->empty();
    }

    public function onlySingleStopOrSpot(): bool
    {
        return $this->branch->list->onlySingleStopOrSpot();
    }

    /** The colour leaving it, when written before the next `else` or `endif`, or at its end. */
    public function out(): ?Rainbow
    {
        $color = ($this->branch->special ?? $this->branch->inlinkRendering)->color;

        return null === $color ? null : $this->arrow->over($color);
    }

    /** The colour of the arrow into it. */
    public function inColor(Rainbow $arrow): Rainbow
    {
        if ($this->empty()) {
            return $arrow->over($this->tile->outLink()->color);
        }

        return $arrow->over($this->branch->labelPositive->color ?? $this->tile->inLink()->color);
    }

    /** @return ?list<string> the label on the arrow into it: written as its first arrow, or after its test */
    public function displayPositive(): ?array
    {
        return $this->tile->inLink()->display ?? $this->branch->labelPositive->display;
    }
}
