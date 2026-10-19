<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidConfiguration;
use Janalis\Viz\Mermaid\MermaidDrawing;
use Janalis\Viz\Mermaid\MermaidOutline;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Mermaid\PieConfiguration;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PieGeometryTest extends TestCase
{
    public function testConfigurationOverlaysKeepZeroAndValidateTheFamily(): void
    {
        $statement = new MermaidStatement('', offset: 0, line: 0, column: 0);
        $base = MermaidConfiguration::parse((object) ['pie' => (object) ['donutHole' => 0.4, 'textPosition' => 0.7, 'legendPosition' => 'left']], $statement);
        $zero = MermaidConfiguration::parse((object) ['pie' => (object) ['donutHole' => 0, 'textPosition' => 0]], $statement);
        $merged = $base->overlay($zero)->pie;
        $this->assertSame(0.0, $merged->donutHole);
        $this->assertSame(0.0, $merged->textPosition);
        $this->assertSame('left', $merged->legendPosition);
        $this->assertFalse(new PieConfiguration()->configured());
        $this->assertTrue($merged->configured());
        foreach ([-1, 1, 99] as $hole) {
            $this->assertSame(0.0, PieConfiguration::parse((object) ['donutHole' => $hole], $statement)->donutHole);
        }
        $this->expectException(MermaidSyntaxError::class);
        $base->validateFamily('sequence');
    }

    #[DataProvider('invalid')]
    public function testInvalidConfigurationIsRejected(string $key, mixed $value): void
    {
        $this->expectException(MermaidSyntaxError::class);
        PieConfiguration::parse((object) [$key => $value], new MermaidStatement('', offset: 0, line: 0, column: 0));
    }

    public static function invalid(): iterable
    {
        yield ['legendPosition', 'outside'];
        yield ['textPosition', -1];
        yield ['textPosition', 2];
        yield ['donutHole', \INF];
        yield ['donutHole', '0.4'];
        yield ['textPosition', false];
    }

    #[DataProvider('positions')]
    public function testLegendPositionsAndDonutGeometryRemainInsideTheScene(string $position): void
    {
        $source = "---\nconfig: {pie: {donutHole: 0.4, textPosition: 0.7, legendPosition: ".$position."}}\n---\npie showData title Demo\n\"First\":1\n\"Last\":3\n\"Zero\":0";
        $scene = NativeFixtureScene::render($source);
        $nodes = \array_values(\array_intersect_key($scene->graph->nodes, $scene->elements));
        $this->assertCount(3, $nodes);
        foreach ($nodes as $node) {
            $shape = $scene->shapes[$node->id];
            foreach (MermaidOutline::points($node, $shape) as [$x, $y]) {
                $this->assertGreaterThanOrEqual(0, $x);
                $this->assertLessThanOrEqual($scene->graph->width, $x);
                $this->assertGreaterThanOrEqual(0, $y);
                $this->assertLessThanOrEqual($scene->graph->height, $y);
            }
            $this->assertTrue(MermaidOutline::contains($node, $shape, $node->x, $node->y));
        }
        $first = $nodes[0];
        $parts = \explode(':', $scene->shapes[$first->id]);
        $cx = $first->x + (float) $parts[4];
        $cy = $first->y + (float) $parts[5];
        $this->assertFalse(MermaidOutline::contains($first, $scene->shapes[$first->id], $cx, $cy));
        $label = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $node->id !== $first->id && $node->label === $first->label);
        match ($position) {
            'left' => $this->assertLessThan($cx - 24, $label->x),
            'right' => $this->assertGreaterThan($cx + 24, $label->x),
            'top' => $this->assertLessThan($cy - 24, $label->y),
            'bottom' => $this->assertGreaterThan($cy + 24, $label->y),
            default => $this->assertEqualsWithDelta($cx + 2, $label->x, delta: 0.01),
        };
        $this->assertCount(3, \array_unique(\array_values($scene->elements)));
        $drawing = MermaidDrawing::graph($scene->graph, $scene->shapes);
        \iterator_to_array($drawing);
        $lastSector = -1;
        $firstCaption = \PHP_INT_MAX;
        foreach ($drawing->getReturn()->marks as $index => $mark) {
            if ('polygon' === $mark->kind && \count($mark->points) > 10) {
                $lastSector = $index;
            }
            if ('text' === $mark->kind && '' !== $mark->text) {
                $firstCaption = \min($firstCaption, $index);
            }
        }
        $this->assertGreaterThanOrEqual(0, $lastSector);
        $percentage = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => '25%' === $node->label);
        $this->assertEqualsWithDelta(16.8, \hypot($percentage->x - $cx, $percentage->y - $cy), delta: 1.0e-10);
        $this->assertLessThan($firstCaption, $lastSector, 'all sectors paint before captions and centered legends');
        $this->assertStringContainsString('75%', NativeFixtureExport::render($source, Format::Svg));
    }

    /** @return iterable<string, array{string}> */
    public static function positions(): iterable
    {
        foreach (['top', 'bottom', 'left', 'right', 'center'] as $position) {
            yield $position => [$position];
        }
    }

    public function testSmallSlicesRemainSearchableWhileVisibleArcsFillTheWholeCircle(): void
    {
        $scene = NativeFixtureScene::render("pie\n\"Large\":386\n\"Medium\":85\n\"Tiny\":1\n\"Zero\":0");
        $nodes = \array_values(\array_intersect_key($scene->graph->nodes, $scene->elements));
        $this->assertCount(4, $nodes);
        $this->assertSame('text', $scene->shapes[$nodes[2]->id]);
        $this->assertSame('text', $scene->shapes[$nodes[3]->id]);
        $first = \explode(':', $scene->shapes[$nodes[0]->id]);
        $last = \explode(':', $scene->shapes[$nodes[1]->id]);
        $this->assertSame(0.0, (float) $first[1]);
        $this->assertEqualsWithDelta(2 * \M_PI, (float) $last[2], delta: 1.0e-12);
        $this->assertEqualsWithDelta(386 / 85, ((float) $first[2] - (float) $first[1]) / ((float) $last[2] - (float) $last[1]), delta: 1.0e-12);
        $this->assertStringContainsString('Tiny (0.21%)', $nodes[2]->label);
    }

    public function testHitTestingFindsEachSectorAndRejectsTheDonutHole(): void
    {
        $scene = NativeFixtureScene::render("---\nconfig: {pie: {donutHole: 0.4}}\n---\npie\n\"A\":1\n\"B\":1");
        $nodes = \array_values(\array_intersect_key($scene->graph->nodes, $scene->elements));
        $parts = \explode(':', $scene->shapes[$nodes[0]->id]);
        $cx = $nodes[0]->x + (float) $parts[4];
        $cy = $nodes[0]->y + (float) $parts[5];
        $this->assertSame($nodes[0]->id, MermaidOutline::pieAt($scene, $cx + 15, $cy + 1));
        $this->assertSame($nodes[1]->id, MermaidOutline::pieAt($scene, $cx - 15, $cy + 1));
        $this->assertNull(MermaidOutline::pieAt($scene, $cx, $cy), 'the donut hole selects nothing');
        $this->assertNull(MermaidOutline::pieAt($scene, $cx + 30, $cy), 'outside the ring selects nothing');
        $legend = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $node->id !== $nodes[0]->id && $node->label === $nodes[0]->label);
        $this->assertNull(MermaidOutline::pieAt($scene, $legend->x, $legend->y), 'only sectors are hit tested');
    }

    public function testAWholePieHasNoRadialSeamAndAWholeDonutKeepsItsHole(): void
    {
        foreach ([0.0, 0.4] as $hole) {
            $scene = NativeFixtureScene::render("---\nconfig: {pie: {donutHole: ".$hole."}}\n---\npie\n\"Only\":1");
            $id = \array_key_first($scene->elements);
            $node = $scene->graph->nodes[$id];
            $parts = \explode(':', $scene->shapes[$id]);
            $cx = $node->x + (float) $parts[4];
            $cy = $node->y + (float) $parts[5];
            $points = MermaidOutline::points($node, $scene->shapes[$id]);
            $area = 0.0;
            $previous = \array_last($points);
            foreach ($points as [$x, $y]) {
                $area += ($previous[0] * $y) - ($x * $previous[1]);
                $previous = [$x, $y];
            }
            $this->assertEqualsWithDelta(\M_PI * 24 * 24 * (1 - ($hole * $hole)), $area / 2, delta: 1.0);
            $this->assertNotContains([$cx, $cy], $points);
            $this->assertSame(0.0 === $hole, MermaidOutline::contains($node, $scene->shapes[$id], $cx, $cy));
            $this->assertTrue(MermaidOutline::contains($node, $scene->shapes[$id], $cx + 20, $cy));
            if (0.4 === $hole) {
                // The inner arc runs back from the end angle at the hole's radius.
                $this->assertEqualsWithDelta([$cx, $cy - 9.6], \array_last($points), delta: 1.0e-9);
            }
        }
    }
}
