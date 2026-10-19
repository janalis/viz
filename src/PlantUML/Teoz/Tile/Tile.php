<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Tile;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Sequences\SequenceEvent;
use Janalis\Viz\PlantUML\Teoz\Real\Real;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;
use Janalis\Viz\PlantUML\Teoz\YGauge;

/**
 * One row of a sequence diagram as teoz lays it out — a message, a note, a group, a divider
 * — with where it stands down the diagram and the reals bounding it across.
 */
interface Tile
{
    public function event(): SequenceEvent;

    public function gauge(): YGauge;

    public function height(): float;

    /** How far down its arrow touches, or -1 for a tile without one. */
    public function contact(): float;

    /** Keeps its participants apart enough for it. */
    public function addConstraints(): void;

    public function minX(): Real;

    public function maxX(): Real;

    /** Its left edge as drawn, a loop's label included. */
    public function drawnMinX(): Real;

    /** Its right edge as drawn, a loop's label included. */
    public function drawnMaxX(): Real;

    /** Tells its participants where it touches their lifelines, once the layout is solved. */
    public function resolved(): void;

    /** @return list<Mark> */
    public function marks(Layer $layer): array;
}
