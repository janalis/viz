<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\FlowchartParser;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidOutline;
use Janalis\Viz\Mermaid\RankedGraphLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FlowchartCutCornersTest extends TestCase
{
    /** @return iterable<string, array{string, list<array{float, float}>, array{float, float}}> */
    public static function shapes(): iterable
    {
        foreach (['notch-rect', 'card', 'notched-rectangle'] as $shape) {
            // Pinned `card`: a twelve-pixel notch whatever the size.
            yield $shape => [$shape, [[-8.8, -6.0], [10.0, -6.0], [10.0, 6.0], [-10.0, 6.0], [-10.0, -4.8]], [-9.25, -5.55]];
        }
        foreach (['sl-rect', 'manual-input', 'sloped-rectangle'] as $shape) {
            // Pinned `sloped_rect`: the left side a third of the height shorter.
            yield $shape => [$shape, [[-10.0, -2.0], [10.0, -6.0], [10.0, 6.0], [-10.0, 6.0]], [-5.0, -3.0]];
        }
    }

    /**
     * @param list<array{float, float}> $expected
     * @param array{float, float} $boundary
     */
    #[DataProvider('shapes')]
    public function testAliasesClipTheirEmptyCornersAndUseTheVisibleBorder(string $shape, array $expected, array $boundary): void
    {
        $node = new GraphNode(0, 'A', 'Label', 0.0, 0.0, 20.0, 12.0, '#ff0000', '#ffff00', dashed: true, strokeWidth: 0.4);
        $this->assertSame($expected, MermaidOutline::points($node, $shape));
        $this->assertFalse(MermaidOutline::contains($node, $shape, -9.75, -5.75));
        $this->assertTrue(MermaidOutline::contains($node, $shape, -9.0, 0.0));
        $this->assertEqualsWithDelta($boundary, MermaidOutline::boundary($node, $shape, -100.0, -60.0), delta: 0.000_001);
        $mark = MermaidOutline::marks($node, $shape)[0];
        $this->assertSame($expected, $mark->points);
        $this->assertSame('#ff0000', $mark->stroke);
        $this->assertSame('#ffff00', $mark->fill);
        $this->assertTrue($mark->dashed);
        $this->assertSame(0.4, $mark->strokeWidth);
        $narrow = new GraphNode(2, 'N', '', 0.0, 0.0, 0.3, 12.0, null, null, dashed: false);
        foreach (MermaidOutline::points($narrow, $shape) as [$x, $y]) {
            $this->assertGreaterThanOrEqual(-0.15, $x);
            $this->assertLessThanOrEqual(0.15, $x);
        }
        $short = new GraphNode(1, 'B', '', 0.0, 0.0, 20.0, 0.3, null, null, dashed: false);
        foreach (MermaidOutline::points($short, $shape) as [$x, $y]) {
            $this->assertGreaterThanOrEqual(-0.15, $y);
            $this->assertLessThanOrEqual(0.15, $y);
        }
    }

    public function testSlantsAndHexagonPointsFollowTheHeight(): void
    {
        $node = new GraphNode(0, 'A', 'Label', 0.0, 0.0, 40.0, 12.0, null, null, dashed: false);
        // Pinned slants run half the height across; a hexagon's points, two sevenths of it.
        $this->assertSame([[-14.0, -6.0], [20.0, -6.0], [14.0, 6.0], [-20.0, 6.0]], MermaidOutline::points($node, 'lean-r'));
        $this->assertSame([[-20.0, -6.0], [14.0, -6.0], [20.0, 6.0], [-14.0, 6.0]], MermaidOutline::points($node, 'lean-l'));
        $this->assertSame([[-14.0, -6.0], [14.0, -6.0], [20.0, 6.0], [-20.0, 6.0]], MermaidOutline::points($node, 'trap-b'));
        $this->assertSame([[-20.0, -6.0], [20.0, -6.0], [14.0, 6.0], [-14.0, 6.0]], MermaidOutline::points($node, 'trap-t'));
        $this->assertEqualsWithDelta(
            [[-20 + (24 / 7), -6.0], [20 - (24 / 7), -6.0], [20.0, 0.0], [20 - (24 / 7), 6.0], [-20 + (24 / 7), 6.0], [-20.0, 0.0]],
            MermaidOutline::points($node, 'hexagon'),
            delta: 0.000_001,
        );
        $narrow = new GraphNode(0, 'A', 'Label', 0.0, 0.0, 8.0, 12.0, null, null, dashed: false);
        $this->assertSame([[-2.0, -6.0], [2.0, -6.0], [4.0, 6.0], [-4.0, 6.0]], MermaidOutline::points($narrow, 'trap-b'), 'a narrow box bounds the slant');
        $this->assertSame([-2.0, 2.0], [MermaidOutline::points($narrow, 'hexagon')[0][0], MermaidOutline::points($narrow, 'hexagon')[1][0]]);
    }

    /** @return iterable<string, array{string, float}> */
    public static function widened(): iterable
    {
        yield 'lean' => ['lean-r', 0.5];
        yield 'trapezoid' => ['trap-b', 1.0];
        yield 'hexagon' => ['hex', 4 / 7];
        yield 'rectangle' => ['rect', 0.0];
    }

    #[DataProvider('widened')]
    public function testSlantedShapesWidenByTheirSlants(string $shape, float $share): void
    {
        $measure = static function (string $shape): array {
            $parser = new FlowchartParser();
            foreach (new MermaidLexer('flowchart TD; A@{shape: '.$shape.', label: Label}')->statements() as $statement) {
                $parser->accept($statement);
            }
            $work = new RankedGraphLayout()->layout($parser->finish());
            \iterator_to_array($work);
            $node = $work->getReturn()->graph->nodes[0];

            return [$node->w, $node->h];
        };
        [$width, $height] = $measure('rect');
        $this->assertEqualsWithDelta([$width + ($share * $height), $height], $measure($shape), delta: 0.000_001);
    }
}
