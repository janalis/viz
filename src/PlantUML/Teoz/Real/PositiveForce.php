<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Real;

/** A force keeping a moveable real at least a distance past another. */
final readonly class PositiveForce
{
    public function __construct(
        private Real $fixed,
        private RealMoveable $moving,
        private float $distance,
    ) {}

    /** Pushes the moving real past the fixed one if it is short of the distance; whether it moved. */
    public function apply(): bool
    {
        $moving = $this->moving->value();
        $fixed = $this->fixed->value();
        $short = $moving - $fixed - $this->distance;
        // As Java, a tolerance of a thousand units in the last place of the larger value, at least a millionth.
        $epsilon = \max(0.000_001, 1000 * self::ulp(\max(\abs($moving), \abs($fixed))));
        if ($short >= -$epsilon) {
            return false;
        }
        $this->moving->move(-$short);

        return true;
    }

    /** The gap between a double and the next one up, as Java's Math.ulp gives it. */
    private static function ulp(float $value): float
    {
        if (0.0 === $value) {
            return \PHP_FLOAT_MIN;
        }
        $exponent = (int) \floor(\log($value, base: 2));

        return 2.0 ** ($exponent - 52);
    }
}
