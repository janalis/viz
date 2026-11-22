<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/** What a unit of {@see DotRanking} stands for within a scope. */
enum RankUnit
{
    /** A node in no rank set. */
    case Node;
    /** The nodes of a merged rank set held at neither end. */
    case Same;
    /** A cluster, by its leader. */
    case Cluster;
    /** Every set held at the top. */
    case Min;
    /** Every set held at the top, alone there. */
    case Source;
    /** Every set held at the bottom. */
    case Max;
    /** Every set held at the bottom, alone there. */
    case Sink;
    /** Sets held at both ends, which tie the whole scope to one rank. */
    case Both;

    /** Whether the unit keeps to the top of its scope. */
    public function top(): bool
    {
        return self::Min === $this || self::Source === $this;
    }

    /** Whether the unit keeps to the bottom of its scope. */
    public function bottom(): bool
    {
        return self::Max === $this || self::Sink === $this;
    }

    /** The ranks the unit keeps clear between itself and the rest: one for a source or a sink. */
    public function gap(): int
    {
        return (int) (self::Source === $this || self::Sink === $this);
    }
}
