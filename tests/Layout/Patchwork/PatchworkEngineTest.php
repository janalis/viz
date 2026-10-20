<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Patchwork;

use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\GraphViz\DotLayoutInput;
use Janalis\Viz\GraphViz\DotPipeline;
use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\Patchwork\PatchworkEngine;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class PatchworkEngineTest extends TestCase
{
    public function testNodesFillASquareAsLargeAsTheirAreas(): void
    {
        // dot: one node 31.624 square, four 63.246, one of area four too.
        $this->assertEqualsWithDelta(31.623, self::place('graph { a }')->graph->width - 8, 1e-3);
        $this->assertEqualsWithDelta(63.246, self::place('graph { a; b; c; d }')->graph->width - 8, 1e-3);
        $this->assertEqualsWithDelta(63.246, self::place('graph { a }', ['a' => 4000.0])->graph->width - 8, 1e-3);
        $this->assertSame(8.0, self::place('graph { a; b }', ['a' => 0.0, 'b' => 0.0])->graph->width, 'nothing to fill, but the padding');
    }

    public function testClustersTakeTheirAreasAndCellsTileWithoutGaps(): void
    {
        $placement = self::place('graph { subgraph cluster_x { a; b; c } subgraph cluster_y { d; e } f; g; a -- d }', ['g' => 2000.0]);
        $area = 0.0;
        foreach ($placement->graph->nodes as $node) {
            $area += $node->w * $node->h;
        }
        $cluster = $placement->graph->clusters[0];

        $this->assertEqualsWithDelta(8000.0, $area, 1e-6, 'the nodes tile the square');
        $this->assertEqualsWithDelta(3000.0, ($cluster->x1 - $cluster->x0) * ($cluster->y1 - $cluster->y0), 1e-6, 'X as large as its three nodes');
        $this->assertEqualsWithDelta(2000.0, $placement->endpoints['g']->w * $placement->endpoints['g']->h, 1e-6);
        $this->assertSame([], $placement->routes, 'no edges');
    }

    public function testTheDotPipelineDrawsBoxesWithoutEdgesNorClusterLabels(): void
    {
        $drawing = Work::run(new DotPipeline()->drawing('graph { layout=patchwork; subgraph cluster_x { label=X; a; b [area=2] } c [shape=ellipse]; d [area=x]; a -- b }'));
        $texts = \array_map(static fn ($mark): string => $mark->text, \array_values(\array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Text === $mark->kind)));

        $this->assertSame([], \array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Polyline === $mark->kind), 'no edges');
        $this->assertNotContains('X', $texts, 'no cluster label');
        $this->assertCount(4, \array_filter($drawing->marks, static fn ($mark): bool => MarkKind::Polygon === $mark->kind && 4 === \count($mark->points)), 'the cluster and three boxes');
    }

    /** @param array<string, float> $areas */
    private static function place(string $source, array $areas = []): Placement
    {
        return Work::run(new PatchworkEngine($areas)->place(DotLayoutInput::from(Work::run(new DotParser()->parse($source)))));
    }
}
