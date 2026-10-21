<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Activities\LinkRendering;

/** A step's tile in the swimlane it was written in, drawn there. */
final readonly class FtileLane implements Ftile
{
    public function __construct(
        public Ftile $tile,
        public string $lane,
    ) {}

    /** The swimlane the flow comes into a tile in, as PlantUML finds it: its first step's. */
    public static function in(Ftile $tile): ?string
    {
        $children = $tile->children();

        return match (true) {
            $tile instanceof self => $tile->lane,
            [] === $children => null,
            default => self::in($children[0]),
        };
    }

    /** The swimlane the flow leaves a tile in, as PlantUML finds it: its last step's. */
    public static function out(Ftile $tile): ?string
    {
        $children = $tile->children();

        return match (true) {
            $tile instanceof self => self::exit($tile->tile) ?? $tile->lane,
            [] === $children => null,
            default => self::out($children[\count($children) - 1]),
        };
    }

    /** The swimlane a step's tile says the flow leaves it in, through the tiles wrapping it. */
    private static function exit(Ftile $tile): ?string
    {
        $children = $tile->children();

        return match (true) {
            $tile instanceof LaneExit => $tile->exitLane(),
            1 === \count($children) && !$tile instanceof self => self::exit($children[0]),
            default => null,
        };
    }

    /**
     * As PlantUML's swimlanes, whether one stands left of every other of a set holding more than it.
     *
     * @param list<string> $others
     * @param array<string, int> $order each swimlane's place from the left
     */
    public static function leftOfAll(?string $lane, array $others, array $order): bool
    {
        if (null === $lane || [] === \array_diff($others, [$lane])) {
            return false;
        }
        foreach ($others as $other) {
            if (($order[$other] ?? 0) < ($order[$lane] ?? 0)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> the swimlanes of the steps a tile holds */
    public static function all(Ftile $tile): array
    {
        $lanes = $tile instanceof self ? [$tile->lane] : [];
        foreach ($tile->children() as $child) {
            \array_push($lanes, ...self::all($child));
        }

        return \array_values(\array_unique($lanes));
    }

    #[\Override]
    public function geometry(): Geometry
    {
        return $this->tile->geometry();
    }

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        $this->tile->draw($canvas->inLane($this->lane));
    }

    #[\Override]
    public function children(): array
    {
        return [$this->tile];
    }

    #[\Override]
    public function translateFor(Ftile $child): array
    {
        return [0.0, 0.0];
    }

    #[\Override]
    public function inLink(): LinkRendering
    {
        return $this->tile->inLink();
    }

    #[\Override]
    public function outLink(): LinkRendering
    {
        return $this->tile->outLink();
    }

    #[\Override]
    public function weldings(): array
    {
        return $this->tile->weldings();
    }
}
