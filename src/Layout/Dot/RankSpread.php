<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

/** How {@see DotRanking} spreads a graph's nodes over the ranks they may take, as the paper balances ranks. */
enum RankSpread
{
    /** Not at all, as dot ranks a graph with clusters. */
    case None;
    /** Each connected component on its own, as dot ranks a graph without clusters. */
    case Components;
    /** Counting the whole graph's ranks at once, as dot ranks under `newrank`. */
    case Graph;
}
