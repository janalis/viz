<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz;

use Janalis\Viz\PlantUML\Teoz\Real\Real;

/**
 * Where a tile stands down the diagram, as teoz gauges it: its top and bottom, and for a
 * message the point its arrow touches, from the bottom of the tile before it — a message
 * written `&` shares the touch point of the one before.
 */
final readonly class YGauge
{
    public function __construct(
        public Real $min,
        public Real $max,
        public ?Real $contact = null,
        public ?Real $origin = null,
    ) {}

    public static function create(Real $min, float $height): self
    {
        return new self($min, $min->atLeast($height));
    }

    /** Below the tile before, its touch point at least a distance down and its bottom a height below its top. */
    public static function withContact(self $current, float $contact, float $height): self
    {
        $origin = $current->max;
        $point = $origin->atLeast($contact);

        return new self($point->plus(-$contact), $point->atLeast($height - $contact), $point, $origin);
    }

    /** Beside the tile before, sharing its touch point, or below its top when it has none. */
    public static function parallel(self $current, float $contact, float $height): self
    {
        if (null === $current->contact || null === $current->origin) {
            return self::create($current->min, $height);
        }
        $current->contact->ensureBiggerThan($current->origin->plus($contact));
        $max = $current->contact->atLeast($height - $contact);
        $max->ensureBiggerThan($current->max);

        return new self($current->contact->plus(-$contact), $max, $current->contact, $current->origin);
    }

    /** Below the tile before, keeping its touch point for a message written `&` after. */
    public static function propagating(self $current, float $height): self
    {
        return new self($current->max, $current->max->atLeast($height), $current->contact, $current->origin);
    }
}
