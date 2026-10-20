<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Osage;

use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\GraphViz\DotLayoutInput;
use Janalis\Viz\GraphViz\DotPipeline;
use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\Osage\OsageEngine;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class OsageEngineTest extends TestCase
{
    public function testItemsPackInAGridClustersFirst(): void
    {
        // dot: in X, a and b side by side 58 apart, c under a 40 below; X and Y, then f under X and g under Y.
        $placement = self::place('graph { subgraph cluster_x { a; b; c } subgraph cluster_y { d; e } f; g; a -- d }');
        $at = static fn (string $name): array => [$placement->endpoints[$name]->x, $placement->endpoints[$name]->y];
        [$x, $y] = [$placement->graph->clusters[0], $placement->graph->clusters[1]];

        $this->assertSame([$at('a')[0] + 58, $at('a')[1]], $at('b'));
        $this->assertSame([$at('a')[0], $at('a')[1] + 40], $at('c'));
        $this->assertEqualsWithDelta(($x->x0 + $x->x1) / 2, $at('f')[0], 1e-9, 'f centred under X');
        $this->assertEqualsWithDelta(($y->x0 + $y->x1) / 2, $at('g')[0], 1e-9, 'g under Y');
        $this->assertSame(4.0, $y->x0 - $x->x1, 'the clusters 4 apart');
        $this->assertSame(2.0, $at('a')[0] - 27 - $x->x0, 'a 2 inside X');
        $this->assertEqualsWithDelta(($x->y0 + $x->y1) / 2, ($y->y0 + $y->y1) / 2, 1e-9, 'Y centred in its row');
        $this->assertArrayHasKey(0, $placement->routes, 'edges straight');
    }

    public function testALabelWidensAndHeightensItsCluster(): void
    {
        $graph = Work::run(new DotParser()->parse('graph { subgraph cluster_x { label="a long label"; a } }'));
        $placement = Work::run(new OsageEngine()->place(DotLayoutInput::from($graph, titles: static fn (): array => [100.0, 10.0])));
        $box = $placement->graph->clusters[0];

        $this->assertSame(100.0 + 4, $box->x1 - $box->x0);
        $this->assertSame(36.0 + 10 + 4 + 4, $box->y1 - $box->y0);
    }

    public function testTheDotPipelineDrawsWithOsage(): void
    {
        $drawing = Work::run(new DotPipeline()->drawing('graph { layout=osage; subgraph cluster_x { a; b } c }'));

        $this->assertCount(4, \array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Polygon === $mark->kind), 'the cluster and three nodes');
    }

    private static function place(string $source): Placement
    {
        return Work::run(new OsageEngine()->place(DotLayoutInput::from(Work::run(new DotParser()->parse($source)))));
    }
}
