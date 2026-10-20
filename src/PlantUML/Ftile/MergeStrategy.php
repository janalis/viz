<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/** How far an arrow may merge with the one it meets: wholly, with fewer simplifications, or not at all. */
enum MergeStrategy: int
{
    case Full = 0;
    case Limited = 1;
    case None = 2;

    /** The stricter of two. */
    public function max(self $other): self
    {
        return $this->value >= $other->value ? $this : $other;
    }
}
