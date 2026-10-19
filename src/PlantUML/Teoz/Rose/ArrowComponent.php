<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

/** An arrow's component, with where along its height the arrow touches its participants. */
interface ArrowComponent extends Component
{
    /** How far down its arrow touches, for the tile it stands in. */
    public function yPoint(): float;

    /** How far down its arrow leaves, where an activation it starts or ends meets it. */
    public function startY(): float;
}
