<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Nop;

use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\GraphViz\DotLayoutInput;
use Janalis\Viz\GraphViz\DotPipeline;
use Janalis\Viz\GraphViz\DotPositions;
use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\Nop\NopEngine;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\TestCase;

final class NopEngineTest extends TestCase
{
    /** As dot -Tdot writes it: a over b, an edge down with its head's tip, a labelled one back up with its tail's tip. */
    private const string LAID = 'digraph { a [pos="54,162!"]; b [pos="27,90"]; a -> b [pos="e,33.5,107.8 47.5,144 44.5,136.3 40.9,127 37.5,118.3"]; b -> a [label=x, lp="60,120", pos="s,37.5,107.8 30,100 35,120 45,130 50,144"]; a -> c; c [pos="80,20"]; b -> c [pos="1,2 3,4"]; a -> a }';

    public function testNodesAndEdgesStandWhereTheGraphPutsThem(): void
    {
        $placement = self::place(self::LAID);
        [$a, $b] = [$placement->endpoints['a'], $placement->endpoints['b']];

        $this->assertSame([27.0, 72.0], [$a->x - $b->x, $b->y - $a->y], 'a over b, y turned down');
        $this->assertSame(4.0, $b->x - ($b->w / 2), 'the leftmost box at the padding');
        $head = $placement->splines[0];
        $this->assertSame([47.5 - 54 + $a->x, $a->y + 162 - 144], $head[0], 'the spline from its first control point');
        $this->assertSame([33.5 - 54 + $a->x, $a->y + 162 - 107.8], $head[\count($head) - 1], 'to the tip of its head');
        $this->assertCount(1 + 12 + 1, $head);
        $back = $placement->splines[1];
        $this->assertSame([37.5 - 54 + $a->x, $a->y + 162 - 107.8], $back[0], 'from the tip of its tail');
        $this->assertSame([60 - 54 + $a->x, $a->y + 162 - 120], $placement->labels[1]);
        $this->assertArrayNotHasKey(2, $placement->splines, 'an edge without a spline runs straight');
        $this->assertArrayNotHasKey(3, $placement->splines, 'and one with too few control points');
        $this->assertSame([[$b->x, $b->y], [$placement->endpoints['c']->x, $placement->endpoints['c']->y]], $placement->routes[3]);
    }

    public function testANodeWithoutAPositionIsLeftToNop(): void
    {
        $this->expectException(Unsupported::class);
        self::place('digraph { a [pos="0,0"]; b [pos="x,y"]; a -> b; a -> a }');
    }

    public function testTheDotPipelineDrawsWithNop(): void
    {
        $drawing = Work::run(new DotPipeline()->drawing(\str_replace(search: 'digraph {', replace: 'digraph { layout=nop;', subject: self::LAID)));

        $this->assertCount(5, \array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Polyline === $mark->kind), 'four edges and a loop');
    }

    private static function place(string $source): Placement
    {
        $graph = Work::run(new DotParser()->parse($source));

        return Work::run(new NopEngine(DotPositions::nodes($graph), DotPositions::edges($graph), DotPositions::labels($graph))->place(DotLayoutInput::from($graph)));
    }
}
