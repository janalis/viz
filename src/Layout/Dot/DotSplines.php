<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout\Dot;

use Janalis\Viz\Model\Graph\Shape;

/**
 * Edges drawn as splines, as Dobkin et al. route them: each edge between ranks runs through a
 * corridor of boxes — below its tail, the gap between each two ranks, the room beside each of
 * its virtual nodes up to their neighbours and the clusters it is not in, and above its head.
 * The shortest path through the corridor, found by a funnel across the openings between its
 * boxes, is fitted with cubic Bézier curves, each split at the path's corner furthest from the curve first tried
 * until every piece keeps inside the corridor.
 */
final class DotSplines
{
    /** How many straight steps each curve is drawn in. */
    private const int STEPS = 12;
    /** How many points along a curve are checked inside its corridor: enough that one cutting a corner by a point is caught. */
    private const int CHECKS = 192;
    /**
     * How far along its tangent a control point at a split stands, as parts of the curve's chord,
     * tried in turn, the last a straight line: four ninths, then half as far, as measured on `dot`'s
     * curves, whose pieces between two splits stand their controls 4/9 of their chord out.
     */
    private const array PULLS = [4 / 9, 2 / 9, 1 / 9, 0.0];
    /** How far apart edges sharing a path run, as parts of the node separation: measured on dot. */
    public const float SPREAD = 0.74;
    /** How far past the narrower end's side an edge of a spread may start. */
    private const float START = 0.37;
    /** Where an edge of a spread starts, as a part of how far out it runs midway. */
    private const float ENDS = 0.6;
    /** How far a curve may stray outside its corridor: rounding. */
    private const float SLACK = 0.01;

    /** @var array<int, float> each rank's half height */
    private array $half = [];
    /** @var array<int, int> each node's place in its rank */
    private array $place = [];
    /** @var array<int, float> where each label node's label was drawn, its near side across, once its edge is routed */
    private array $drawn = [];
    /** @var array<int, float> where each label or virtual node's line, as drawn, reaches furthest right across its rank, once its edge is routed */
    private array $back = [];
    /** @var array<int, float> where each virtual or label node's edge runs furthest left across its rank, once routed */
    private array $routed = [];
    /** @var array<int, string> the two nodes each label node's edge joins, in either order */
    private array $pairs = [];
    /** @var array<int, int> how many edges run through each node between their ends */
    private array $through = [];

    public function __construct(
        private readonly DotLayers $layers,
        private readonly DotPosition $position,
        private readonly float $nodeSeparation,
        /** @var array<int, Shape> each real node's outline, a box where none is given */
        private readonly array $shapes = [],
    ) {
        foreach ($layers->rank as $node => $rank) {
            $this->half[$rank] = \max($this->half[$rank] ?? 0.0, $layers->height[$node] / 2);
        }
        foreach ($layers->labels as $layered => $node) {
            $ends = [$layers->paths[$layered][0], $layers->paths[$layered][\count($layers->paths[$layered]) - 1]];
            \sort($ends);
            $this->pairs[$node] = \implode(',', $ends);
        }
        foreach ($layers->paths as $path) {
            foreach (\array_slice($path, offset: 1, length: -1) as $node) {
                $this->through[$node] = ($this->through[$node] ?? 0) + 1;
            }
        }
        foreach ($layers->ranks as $nodes) {
            foreach ($nodes as $place => $node) {
                $this->place[$node] = $place;
            }
        }
    }

    /**
     * Where a drawn line runs between two heights: the x of each of its points between them,
     * and where it enters or leaves them.
     *
     * @param list<array{float, float}> $line
     * @return list<float>
     */
    public static function across(array $line, float $low, float $high): array
    {
        $xs = [];
        foreach ($line as $index => [$x, $y]) {
            // The first point is its own step before it, which enters nothing.
            [$ax, $ay] = $line[\max(0, $index - 1)];
            foreach ([$low, $high] as $edge) {
                if ((($ay - $edge) * ($y - $edge)) >= 0) {
                    continue;
                }
                $xs[] = $ax + ((($x - $ax) * ($edge - $ay)) / ($y - $ay));
            }
            if ($y < $low || $y > $high) {
                continue;
            }
            $xs[] = $x;
        }

        return $xs;
    }

    /**
     * Edges in the order `dot` is seen to route them: the shortest first, from end to end, of
     * edges as long those given first, else in the order the graph gives them, whatever order
     * their layers keep them in.
     *
     * @param array<int, int> $edges each edge's original index, by its index in the layers
     * @param array<int, int> $first the edges to route first of those as long, by their index in the layers
     * @return array<int, int> the same, reordered
     */
    public function order(array $edges, array $first = []): array
    {
        $lengths = [];
        foreach (\array_keys($edges) as $layered) {
            $path = $this->layers->paths[$layered] ?? [];
            // To the thousandth of a point, so edges as long tie whatever the rounding.
            $lengths[$layered] = [] === $path
                ? 0.0
                : \round(\hypot(
                    $this->position->x[$path[0]] - $this->position->x[$path[\count($path) - 1]],
                    $this->position->y[$this->layers->rank[$path[0]]] - $this->position->y[$this->layers->rank[$path[\count($path) - 1]]],
                ), precision: 3);
        }
        \uksort($edges, static fn (int $a, int $b): int => [$lengths[$a], !\array_key_exists($a, $first), $edges[$a]] <=> [$lengths[$b], !\array_key_exists($b, $first), $edges[$b]]);

        return $edges;
    }

    /**
     * The spline along a path through the ranks, from its first node's centre to its last's,
     * and the shortest path it is fitted to, corner to corner; each moved across by an offset,
     * for one of several edges sharing the path, the full offset along its middle and no
     * further than its ends' outlines allow where it starts and ends.
     *
     * @param list<int> $path one edge's nodes, rank by rank, either way
     * @return array{list<array{float, float}>, list<array{float, float}>, list<array{float, float}>} the curve, drawn in straight steps, the corners, and the curve's control points, none for one moved across
     */
    public function route(array $path, float $offset = 0.0): array
    {
        $down = $this->layers->rank[$path[0]] < $this->layers->rank[$path[\count($path) - 1]];
        $path = $down ? $path : \array_reverse($path);
        [$curves, $points] = $this->curves($path);
        $drawn = [$points[0]];
        foreach ($curves as $curve) {
            for ($step = 1; $step <= self::STEPS; ++$step) {
                $drawn[] = self::at($curve, $step / self::STEPS);
            }
        }
        $controls = [] === $curves ? [] : [$curves[0][0], ...\array_merge(...\array_map(static fn (array $curve): array => \array_slice($curve, offset: 1), $curves))];
        if (0.0 !== $offset) {
            $drawn = $this->spread($drawn, $offset, $path[0], $path[\count($path) - 1]);
            $points = $this->spread($points, $offset, $path[0], $path[\count($path) - 1]);
            $controls = 1 === \count($curves) ? $this->spreadCurve($curves[0], $offset, $path[0], $path[\count($path) - 1]) : [];
        }
        foreach (\array_slice($path, offset: 1, length: -1) as $node) {
            $rank = $this->layers->rank[$node];
            $xs = self::across($drawn, $this->position->y[$rank] - $this->half[$rank], $this->position->y[$rank] + $this->half[$rank]);
            if ([] !== $xs) {
                $this->routed[$node] = \min($this->routed[$node] ?? \INF, ...$xs);
                // A label node's line is kept when its label is set; an edge's without one, here,
                // where it runs alone through its virtual node, not spread with others.
                if (DotLayers::VIRTUAL === $this->layers->kind[$node] && 1 === $this->through[$node]) {
                    $this->back[$node] = \max(...$xs);
                }
            }
        }

        return $down ? [$drawn, $points, $controls] : [\array_reverse($drawn), \array_reverse($points), \array_reverse($controls)];
    }

    /**
     * The point midway along a path's spline, by its parameter, between where it leaves its
     * top node's outline and where it reaches its bottom node's, as `dot` is seen to take it.
     *
     * @param list<int> $path one edge's nodes, rank by rank, either way
     * @return array{float, float}
     */
    public function middle(array $path): array
    {
        $path = $this->layers->rank[$path[0]] < $this->layers->rank[$path[\count($path) - 1]] ? $path : \array_reverse($path);
        $curves = $this->curves($path)[0];
        $count = \count($curves);
        $along = static fn (float $u): array => self::at($curves[\min($count - 1, (int) $u)], $u - \min($count - 1, (int) $u));
        $leaves = $this->crossing($along, $path[0], 0.0, $count);
        $reaches = $this->crossing($along, $path[\count($path) - 1], $count, $leaves);

        return $along(($leaves + $reaches) / 2);
    }

    /**
     * Where along the spline it crosses a node's outline — its ellipse, its diamond, else its
     * box — by bisection between a parameter inside the node and one outside.
     *
     * @param \Closure(float): array{float, float} $along
     */
    private function crossing(\Closure $along, int $node, float $inside, float $outside): float
    {
        [$x, $y] = $this->centre($node);
        for ($step = 0; $step < 40; ++$step) {
            $u = ($inside + $outside) / 2;
            [$px, $py] = $along($u);
            $in = $this->within($node, $px - $x, $py - $y);
            [$inside, $outside] = $in ? [$u, $outside] : [$inside, $u];
        }

        return ($inside + $outside) / 2;
    }

    /** Whether a point, from a node's centre, lies within its outline. */
    private function within(int $node, float $dx, float $dy): bool
    {
        [$left, $right, $half] = [$this->layers->left[$node], $this->layers->right[$node], $this->layers->height[$node] / 2];
        $across = \abs($dx) / ($dx < 0 ? $left : $right);
        $down = \abs($dy) / $half;

        return match ($this->shapes[$node] ?? Shape::Box) {
            Shape::Ellipse, Shape::Circle => ($across ** 2 + $down ** 2) <= 1,
            Shape::Diamond => ($across + $down) <= 1,
            default => $across <= 1 && $down <= 1,
        };
    }

    /**
     * The curves fitted to a path, top to bottom, and the shortest path through its corridor they are fitted to.
     *
     * @param list<int> $path top to bottom
     * @return array{list<array{array{float, float}, array{float, float}, array{float, float}, array{float, float}}>, list<array{float, float}>}
     */
    private function curves(array $path): array
    {
        $boxes = $this->corridor($path);
        $points = self::funnel($this->centre($path[0]), $this->centre($path[\count($path) - 1]), self::openings($boxes));

        // Its ends leave and reach the nodes' centres along no tangent of their own, as `dot`'s curves are seen to.
        return [$this->fit($points, [0.0, 0.0], [0.0, 0.0], $boxes), $points];
    }

    /**
     * A curve moved across as its drawing is: its ends as far as they start, its controls so far
     * that its middle stands the full offset across, as dot's are seen to stand.
     *
     * @param array{array{float, float}, array{float, float}, array{float, float}, array{float, float}} $curve
     * @return list<array{float, float}>
     */
    private function spreadCurve(array $curve, float $offset, int $tail, int $head): array
    {
        $ends = $this->ends($offset, $tail, $head);
        $controls = ((8 * $offset) - (2 * $ends)) / 6;

        return [
            [$curve[0][0] + $ends, $curve[0][1]],
            [$curve[1][0] + $controls, $curve[1][1]],
            [$curve[2][0] + $controls, $curve[2][1]],
            [$curve[3][0] + $ends, $curve[3][1]],
        ];
    }

    /** How far across a spread edge starts and ends: as dot is seen to start them, at three fifths of their spread midway, no further out than a little past the narrower end's side. */
    private function ends(float $offset, int $tail, int $head): float
    {
        $layers = $this->layers;
        $narrowest = \min($layers->left[$tail], $layers->right[$tail], $layers->left[$head], $layers->right[$head]) + self::START;

        return \max(-$narrowest, \min($narrowest, $offset * self::ENDS));
    }

    /**
     * @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private function spread(array $points, float $offset, int $tail, int $head): array
    {
        $ends = $this->ends($offset, $tail, $head);
        $lengths = [0.0];
        for ($index = 1, $count = \count($points); $index < $count; ++$index) {
            $lengths[] = $lengths[$index - 1] + \hypot($points[$index][0] - $points[$index - 1][0], $points[$index][1] - $points[$index - 1][1]);
        }
        $total = \max($lengths[\count($lengths) - 1], 1e-9);
        foreach ($points as $index => [$x, $y]) {
            // The offset swells from what the ends allow to its full width midway.
            $swell = \sin((\M_PI * $lengths[$index]) / $total);
            $points[$index] = [$x + $ends + (($offset - $ends) * $swell), $y];
        }

        return $points;
    }

    /**
     * @param list<int> $path top to bottom
     * @return list<array{float, float, float, float}> left, top, right, bottom
     */
    private function corridor(array $path): array
    {
        $layers = $this->layers;
        $x = $this->position->x;
        $y = $this->position->y;
        $tail = $path[0];
        $open = $this->open($layers->cluster[$tail]) + $this->open($layers->cluster[$path[\count($path) - 1]]);
        $rank = $layers->rank[$tail];
        // Each end may be left sideways, through the room beside its node on its rank, as `dot`'s curves are seen to leave.
        [$left, $right] = $this->beside($tail);
        $boxes = [[$left, $y[$rank], $right, $y[$rank] + $this->half[$rank]]];
        $last = \count($path) - 1;
        for ($index = 1; $index <= $last; ++$index) {
            $node = $path[$index];
            $rank = $layers->rank[$node];
            [$left, $right] = $this->beside($node);
            $box = [$left, $y[$rank] - $this->half[$rank], $right, $index === $last ? $y[$rank] : $y[$rank] + $this->half[$rank]];
            \array_push($boxes, ...$this->gap($boxes[\count($boxes) - 1], $box, $open));
            $boxes[] = $box;
        }

        return $boxes;
    }

    /**
     * The clusters an edge may pass through: those holding either of its ends.
     *
     * @return array<int, true>
     */
    private function open(int $cluster): array
    {
        $open = [];
        for (; -1 !== $cluster; $cluster = $this->layers->parents[$cluster]) {
            $open[$cluster] = true;
        }

        return $open;
    }

    /**
     * The gap between two ranks, from the box above to the box below: as wide as both, but
     * clear of the clusters the edge may not pass through where they reach into it. Cut into
     * slabs where such a cluster starts or ends, each slab keeps the free stretch that meets
     * the one above it, and the box below where it can.
     *
     * @param array{float, float, float, float} $above
     * @param array{float, float, float, float} $below
     * @param array<int, true> $open
     * @return list<array{float, float, float, float}>
     */
    private function gap(array $above, array $below, array $open): array
    {
        [$left, $right] = [\min($above[0], $below[0]), \max($above[2], $below[2])];
        [$top, $bottom] = [$above[3], $below[1]];
        $blocks = [];
        $cuts = [$top, $bottom];
        foreach ($this->position->boxes as $cluster => [$x0, $y0, $x1, $y1]) {
            if (\array_key_exists($cluster, $open) || $x1 <= $left || $x0 >= $right || $y1 <= $top || $y0 >= $bottom) {
                continue;
            }
            $blocks[] = [$x0, \max($y0, $top), $x1, \min($y1, $bottom)];
            \array_push($cuts, \max($y0, $top), \min($y1, $bottom));
        }
        $cuts = \array_values(\array_unique($cuts, \SORT_REGULAR));
        \sort($cuts);
        $boxes = [];
        $previous = [$above[0], $above[2]];
        for ($index = 1, $count = \count($cuts); $index < $count; ++$index) {
            [$from, $to] = [$cuts[$index - 1], $cuts[$index]];
            $free = [[$left, $right]];
            foreach ($blocks as [$x0, $y0, $x1, $y1]) {
                if ($y0 >= $to || $y1 <= $from) {
                    continue;
                }
                $free = self::without($free, $x0, $x1);
            }
            $previous = self::meeting($free, $previous, [$below[0], $below[2]]);
            $boxes[] = [$previous[0], $from, $previous[1], $to];
        }

        return $boxes;
    }

    /**
     * Stretches across with one taken out.
     *
     * @param list<array{float, float}> $stretches
     * @return list<array{float, float}>
     */
    private static function without(array $stretches, float $from, float $to): array
    {
        $kept = [];
        foreach ($stretches as [$start, $end]) {
            if ($start < $from) {
                $kept[] = [$start, \min($end, $from)];
            }
            if ($end > $to) {
                $kept[] = [\max($start, $to), $end];
            }
        }

        return $kept;
    }

    /**
     * Of the free stretches, the one meeting the stretch above most, then the one below.
     *
     * @param list<array{float, float}> $free
     * @param array{float, float} $above
     * @param array{float, float} $below
     * @return array{float, float}
     */
    private static function meeting(array $free, array $above, array $below): array
    {
        $best = $above;
        $score = -\INF;
        foreach ($free as $stretch) {
            $meets = \min($stretch[1], $above[1]) - \max($stretch[0], $above[0]);
            $reaches = \min($stretch[1], $below[1]) - \max($stretch[0], $below[0]);
            $value = ($meets > 0 ? 1e6 : 0.0) + ($reaches > 0 ? 1e3 : 0.0) + $meets;
            if ($value > $score) {
                [$best, $score] = [$stretch, $value];
            }
        }

        return $best;
    }

    /**
     * How far across the room beside a virtual node reaches: half the room neighbours keep from
     * them: a node separation, or 5 on the ranks a graph with edge labels puts between its nodes'
     * ranks, where `dot` is seen to let a label follow its line so far; and no further than the clusters they lie in and it does not. A label node keeps the edge
     * on the far side from its label: along the label's near side where the node is pressed against
     * its neighbour, as `dot` is seen to draw it, else a point past its centre; and its neighbour on its label's side
     * keeps a point clear of where its label would stand at the node, not half the room, as `dot` is
     * seen to bound it.
     *
     * @return array{float, float} left and right
     */
    private function beside(int $node): array
    {
        $layers = $this->layers;
        $rank = $layers->ranks[$layers->rank[$node]];
        $place = $this->place[$node];
        // Past the outermost nodes, a curve may bow as far out as the drawing is wide, as `dot`'s are seen to bow past them.
        $left = -$this->position->width;
        $right = 2 * $this->position->width;
        $half = [] !== $layers->labels && 1 === ($layers->rank[$node] % 2) ? 2.5 : $this->nodeSeparation / 2;
        $previous = $rank[$place - 1] ?? null;
        if (null !== $previous) {
            $left = \max($this->leftOf($previous, $node, $half), $this->apart($previous, $node, side: 1));
        }
        $next = $rank[$place + 1] ?? null;
        if (null !== $next) {
            $right = \min($this->position->x[$next] - $layers->left[$next] - $this->clear($next, $node, $half), $this->apart($next, $node, side: 0));
            // Where its edge was routed already, with its label on its far side, a quarter of the node
            // separation, rounded down, less 2 short of it, as `dot` is seen to route edges one after another.
            if (!$layers->mirrored && \array_key_exists($next, $this->drawn)) {
                $right = \min($right, $this->drawn[$next] - $this->quarter() + 2);
            }
        }
        $at = $this->position->x[$node];
        if (DotLayers::LABEL === $layers->kind[$node]) {
            // Its label stands on its right, or its left mirrored: the edge keeps to the other side.
            $at = \array_key_exists($node, $this->position->sides) ? $this->position->side($node) : $at + ($layers->mirrored ? -$layers->right[$node] : $layers->left[$node]);
            // Laid out to be turned, its label on its left keeps a quarter of the node separation, rounded
            // down, clear of the far side of the line of its neighbour there, a labelled edge between the
            // same two nodes or any edge without a label, where that was routed already, as `dot` is seen to route edges one after another, wherever network
            // simplex set the node; pushed past the node, its line goes no further than leaves its label's
            // far side 10 points past the node, as measured on dot's output.
            if (
                $layers->mirrored
                && null !== $previous
                && \array_key_exists($previous, $this->back)
                && (DotLayers::VIRTUAL === $layers->kind[$previous] || $this->pairs[$previous] === $this->pairs[$node])
            ) {
                $x = $this->position->x[$node];
                $at = \min($right, $this->back[$previous] + $this->quarter() + $layers->left[$node]);
                $at = $at > $x ? \max($x, \min($at, $x + $layers->left[$node] - 10)) : $at;
            }
            [$left, $right] = $layers->mirrored ? [$at, $right] : [$left, $this->reach($node, $next, $at, $right)];
        }

        return [\min($left, $at), \max($right, $at)];
    }

    /**
     * How far right a label node's edge may run, its label on its right: to its label's near
     * side; or, where the edge of its neighbour there was routed already, as far as leaves its
     * label a quarter of the node separation, rounded down, clear of that edge where it runs
     * furthest left across the rank, as `dot` is seen to route edges one after another ({@see
     * order()}), no further than the room beside it.
     */
    private function reach(int $node, ?int $next, float $at, float $right): float
    {
        if (null === $next || !\array_key_exists($next, $this->routed)) {
            return $at;
        }

        return \max($at, \min($right, $this->routed[$next] - $this->quarter() - $this->layers->right[$node]));
    }

    /** Where the room beside a node ends on its left, at its neighbour there. */
    private function leftOf(int $previous, int $node, float $half): float
    {
        $bound = $this->position->x[$previous] + $this->layers->right[$previous] + $this->clear($previous, $node, $half);
        // Where its edge was routed already, no further than a quarter of the node separation, rounded
        // down, past its label as drawn; laid out to be turned, its label on its far side, at least that
        // less 2 past the edge as drawn: as `dot` is seen to route edges one after another.
        if (\array_key_exists($previous, $this->drawn)) {
            $bound = $this->layers->mirrored
                ? \max($bound, $this->drawn[$previous] + $this->quarter() - 2)
                : \min($bound, $this->drawn[$previous] + $this->layers->right[$previous] + $this->quarter());
        }

        return $bound;
    }

    /**
     * Where a label node's label was drawn, its near side across, and how far right its line
     * reaches across its rank: the edges routed after it keep clear of them.
     */
    public function labelled(int $node, float $drawn, float $back): void
    {
        $this->drawn[$node] = $drawn;
        $this->back[$node] = $back;
    }

    /** Forgets every edge routed so far, as before routing them again. */
    public function forget(): void
    {
        $this->drawn = [];
        $this->back = [];
        $this->routed = [];
    }

    /** A quarter of the node separation, to the point, rounded down. */
    private function quarter(): float
    {
        return \floor(\round($this->nodeSeparation) / 4);
    }

    /**
     * How far a neighbour keeps clear of a node's side: a point past a label node's label on its
     * label's side, as `dot` is seen to keep it; an edge's virtual node, from a label node's edge,
     * half the node separation; half the room otherwise.
     */
    private function clear(int $neighbour, int $node, float $half): float
    {
        // Its label faces the node from the left, or from the right mirrored.
        $facing = $this->place[$neighbour] > $this->place[$node] === $this->layers->mirrored;

        // A label node's edge keeps as clear of an edge's virtual node as that node keeps room in the
        // layout, half the node separation, rounded down, even on a label's rank, as measured on dot's output.
        if (DotLayers::VIRTUAL === $this->layers->kind[$neighbour] && DotLayers::LABEL === $this->layers->kind[$node]) {
            return \floor($this->nodeSeparation / 2);
        }

        return DotLayers::LABEL === $this->layers->kind[$neighbour] && $facing ? 1.0 : $half;
    }

    /**
     * The side facing the node, its left side, 0, or its right, 1, of the outermost cluster
     * that holds the neighbour and not the node; none where they share every cluster.
     */
    private function apart(int $neighbour, int $node, int $side): float
    {
        $shared = $this->layers->common($this->layers->cluster[$neighbour], $this->layers->cluster[$node]);
        $edge = 1 === $side ? -\INF : \INF;
        for ($cluster = $this->layers->cluster[$neighbour]; -1 !== $cluster && $cluster !== $shared; $cluster = $this->layers->parents[$cluster]) {
            $edge = $this->position->boxes[$cluster][1 === $side ? 2 : 0];
        }

        return $edge;
    }

    /** @return array{float, float} */
    private function centre(int $node): array
    {
        return [$this->position->x[$node], $this->position->y[$this->layers->rank[$node]]];
    }

    /**
     * The openings between each box and the next: where they meet, as far across as both reach.
     *
     * @param list<array{float, float, float, float}> $boxes
     * @return list<array{array{float, float}, array{float, float}}> each opening's left and right end
     */
    private static function openings(array $boxes): array
    {
        $openings = [];
        for ($index = 1, $count = \count($boxes); $index < $count; ++$index) {
            [$above, $below] = [$boxes[$index - 1], $boxes[$index]];
            $left = \max($above[0], $below[0]);
            $right = \max($left, \min($above[2], $below[2]));
            $openings[] = [[$left, $below[1]], [$right, $below[1]]];
        }

        return $openings;
    }

    /**
     * The shortest path down through the openings: a funnel from the start narrows opening by
     * opening; where one side would cross the other, the path turns at that side's end.
     *
     * @param array{float, float} $start
     * @param array{float, float} $end
     * @param list<array{array{float, float}, array{float, float}}> $openings
     * @return list<array{float, float}>
     */
    private static function funnel(array $start, array $end, array $openings): array
    {
        $openings = [[$start, $start], ...$openings, [$end, $end]];
        $path = [$start];
        $apex = $start;
        [$left, $right] = [$start, $start];
        [$apexAt, $leftAt, $rightAt] = [0, 0, 0];
        $count = \count($openings);
        for ($index = 1; $index < $count; ++$index) {
            [$nextLeft, $nextRight] = $openings[$index];
            // A side narrows the funnel unless it would cross the other, where the path turns at the other's end.
            if (self::turn($apex, $right, $nextRight) >= 0) {
                if ($apex !== $right && self::turn($apex, $left, $nextRight) >= 0) {
                    $path[] = $left;
                    [$apex, $apexAt] = [$left, $leftAt];
                    [$left, $right, $leftAt, $rightAt] = [$apex, $apex, $apexAt, $apexAt];
                    $index = $apexAt;
                    continue;
                }
                [$right, $rightAt] = [$nextRight, $index];
            }
            if (self::turn($apex, $left, $nextLeft) <= 0) {
                if ($apex !== $left && self::turn($apex, $right, $nextLeft) <= 0) {
                    $path[] = $right;
                    [$apex, $apexAt] = [$right, $rightAt];
                    [$left, $right, $leftAt, $rightAt] = [$apex, $apex, $apexAt, $apexAt];
                    $index = $apexAt;
                    continue;
                }
                [$left, $leftAt] = [$nextLeft, $index];
            }
        }
        if ($path[\count($path) - 1] !== $end) {
            $path[] = $end;
        }

        return $path;
    }

    /**
     * Which way a path from `$from` through `$to` turns to reach `$point`: positive one way,
     * negative the other, zero straight on.
     *
     * @param array{float, float} $from
     * @param array{float, float} $to
     * @param array{float, float} $point
     */
    private static function turn(array $from, array $to, array $point): float
    {
        return (($to[0] - $from[0]) * ($point[1] - $from[1])) - (($to[1] - $from[1]) * ($point[0] - $from[0]));
    }

    /**
     * Cubic Béziers along a path, leaving and reaching it along the tangents given, each
     * inside the corridor: one if it fits, else two split at the corner furthest from the curve
     * first tried.
     *
     * @param list<array{float, float}> $points
     * @param array{float, float} $leaving
     * @param array{float, float} $reaching
     * @param list<array{float, float, float, float}> $boxes
     * @return list<array{array{float, float}, array{float, float}, array{float, float}, array{float, float}}>
     */
    private function fit(array $points, array $leaving, array $reaching, array $boxes): array
    {
        $first = $points[0];
        $last = $points[\count($points) - 1];
        $chord = \hypot($last[0] - $first[0], $last[1] - $first[1]);
        // A straight stretch between two free ends is drawn with its controls a third of the way along.
        if (\count($points) < 3 && [0.0, 0.0] === $leaving && [0.0, 0.0] === $reaching) {
            return [[$first, self::towards($first, $last, 1 / 3), self::towards($first, $last, 2 / 3), $last]];
        }
        // Without a tangent at either end every pull draws the same curve: one try is enough.
        foreach ([0.0, 0.0] === $leaving && [0.0, 0.0] === $reaching ? [self::PULLS[0]] : self::PULLS as $pull) {
            $reach = $chord * $pull;
            $curve = [$first, [$first[0] + ($leaving[0] * $reach), $first[1] + ($leaving[1] * $reach)], [$last[0] - ($reaching[0] * $reach), $last[1] - ($reaching[1] * $reach)], $last];
            // A straight stretch of the shortest path lies inside, but for rounding.
            if (self::inside($curve, $boxes) || (0.0 === $pull && \count($points) < 3)) {
                return [$curve];
            }
        }
        $reach = $chord * self::PULLS[0];
        $split = self::furthest($points, [
            $first,
            [$first[0] + ($leaving[0] * $reach), $first[1] + ($leaving[1] * $reach)],
            [$last[0] - ($reaching[0] * $reach), $last[1] - ($reaching[1] * $reach)],
            $last,
        ]);
        // Through the split along the bisector of the path's way in and way out, as `dot`'s curves pass their joints.
        [$in, $out] = [self::unit($points[$split - 1], $points[$split]), self::unit($points[$split], $points[$split + 1])];
        $tangent = self::unit([0.0, 0.0], [$in[0] + $out[0], $in[1] + $out[1]]);

        return [
            ...$this->fit(\array_slice($points, offset: 0, length: $split + 1), $leaving, $tangent, $boxes),
            ...$this->fit(\array_slice($points, $split), $tangent, $reaching, $boxes),
        ];
    }

    /**
     * The inner corner furthest from a curve tried along the path, each measured against the
     * curve where it stands its share of the path's length along, as a curve is fitted to points;
     * the last of those as far.
     *
     * @param list<array{float, float}> $points
     * @param array{array{float, float}, array{float, float}, array{float, float}, array{float, float}} $curve
     */
    private static function furthest(array $points, array $curve): int
    {
        $lengths = [0.0];
        for ($index = 1, $count = \count($points); $index < $count; ++$index) {
            $lengths[] = $lengths[$index - 1] + \hypot($points[$index][0] - $points[$index - 1][0], $points[$index][1] - $points[$index - 1][1]);
        }
        $total = \max(1e-9, $lengths[\count($lengths) - 1]);
        $best = 1;
        $distance = -1.0;
        for ($index = 1, $count = \count($points) - 1; $index < $count; ++$index) {
            [$x, $y] = self::at($curve, $lengths[$index] / $total);
            $away = \hypot($points[$index][0] - $x, $points[$index][1] - $y);
            // Of corners as far, the last, as `dot` is seen to split.
            if ($away >= ($distance - 1e-6)) {
                [$best, $distance] = [$index, $away];
            }
        }

        return $best;
    }

    /**
     * @param array{array{float, float}, array{float, float}, array{float, float}, array{float, float}} $curve
     * @param list<array{float, float, float, float}> $boxes
     */
    private static function inside(array $curve, array $boxes): bool
    {
        for ($step = 1; $step < self::CHECKS; ++$step) {
            [$x, $y] = self::at($curve, $step / self::CHECKS);
            $within = false;
            foreach ($boxes as [$left, $top, $right, $bottom]) {
                if ($x < ($left - self::SLACK) || $x > ($right + self::SLACK) || $y < ($top - self::SLACK) || $y > ($bottom + self::SLACK)) {
                    continue;
                }
                $within = true;
                break;
            }
            if (!$within) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{array{float, float}, array{float, float}, array{float, float}, array{float, float}} $curve
     * @return array{float, float}
     */
    private static function at(array $curve, float $t): array
    {
        $u = 1 - $t;
        [$a, $b, $c, $d] = $curve;

        return [
            (($u ** 3) * $a[0]) + (3 * $u * $u * $t * $b[0]) + (3 * $u * $t * $t * $c[0]) + (($t ** 3) * $d[0]),
            (($u ** 3) * $a[1]) + (3 * $u * $u * $t * $b[1]) + (3 * $u * $t * $t * $c[1]) + (($t ** 3) * $d[1]),
        ];
    }

    /**
     * @param array{float, float} $from
     * @param array{float, float} $to
     * @return array{float, float}
     */
    private static function towards(array $from, array $to, float $share): array
    {
        return [$from[0] + (($to[0] - $from[0]) * $share), $from[1] + (($to[1] - $from[1]) * $share)];
    }

    /**
     * @param array{float, float} $from
     * @param array{float, float} $to
     * @return array{float, float}
     */
    private static function unit(array $from, array $to): array
    {
        $length = \hypot($to[0] - $from[0], $to[1] - $from[1]);

        return $length > 0 ? [($to[0] - $from[0]) / $length, ($to[1] - $from[1]) / $length] : [0.0, 1.0];
    }
}
