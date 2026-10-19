<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

/**
 * The room a component is drawn in, as teoz hands it over: its size, and for an arrow how
 * far activations shift its ends and its label.
 */
final readonly class Area
{
    public function __construct(
        public float $width,
        public float $height,
        /** A self arrow's shift between the activation it leaves and the one it comes back to. */
        public float $deltaX1 = 0.0,
        /** How many activations deep a self arrow starts. */
        public int $level = 0,
        /** How far each activation level shifts a self arrow. */
        public float $liveDeltaSize = 0.0,
        /** How far a message from the diagram's edge moves its label. */
        public float $textDeltaX = 0.0,
    ) {}
}
