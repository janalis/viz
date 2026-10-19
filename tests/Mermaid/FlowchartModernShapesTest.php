<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\FlowchartLayout;
use Janalis\Viz\Mermaid\FlowchartParser;
use Janalis\Viz\Mermaid\FlowchartShapeGeometry;
use Janalis\Viz\Mermaid\FlowchartShapes;
use Janalis\Viz\Mermaid\MermaidDrawing;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidMark;
use Janalis\Viz\Mermaid\MermaidOutline;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FlowchartModernShapesTest extends TestCase
{
    private static function scene(string $source): MermaidScene
    {
        $parser = new FlowchartParser();
        foreach (new MermaidLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }
        $work = new FlowchartLayout()->layout($parser->finish());
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testPrimitiveLabelsRemainSearchableWithoutPaintingOverTheirSymbols(): void
    {
        foreach (['hourglass', 'bolt', 'cross-circ', 'fork', 'sm-circ', 'f-circ', 'fr-circ'] as $shape) {
            $source = 'flowchart; A@{shape:'.$shape.',label:"Hidden semantic name"}';
            $scene = self::scene($source);
            $node = $scene->selectionGraph->nodes[0];
            $this->assertSame('Hidden semantic name', $node->label);
            $this->assertLessThanOrEqual(12, $node->w);
            $this->assertLessThanOrEqual(12, $node->h);
            $svg = NativeFixtureExport::render($source, Format::Svg);
            $this->assertStringNotContainsString('>Hidden semantic name</text>', $svg);
            $this->assertTrue(MermaidOutline::contains($node, $shape, $node->x, $node->y));
        }
        $horizontal = self::scene('flowchart LR; A@{shape:fork}')->graph->nodes[0];
        $this->assertGreaterThan($horizontal->w, $horizontal->h);
    }

    public function testInternalBordersAndMarkersUseAuthoredStrokeAndNoPaintSurvivesFillNone(): void
    {
        foreach (['lin-rect', 'div-rect', 'win-pane', 'lin-cyl', 'h-cyl', 'tag-doc', 'tag-rect', 'browser', 'console', 'bucket', 'fr-circ'] as $shape) {
            $source = 'flowchart; A@{shape:'.$shape.',label:"Sample"}; style A fill:none,stroke:#654321,stroke-width:4,stroke-dasharray:4';
            $scene = self::scene($source);
            $marks = MermaidOutline::marks($scene->graph->nodes[0], $shape);
            $this->assertGreaterThanOrEqual(2, \count($marks));
            foreach ($marks as $mark) {
                $this->assertSame('#654321', $mark->stroke);
                $this->assertTrue($mark->dashed);
                $this->assertSame('none', $mark->fill);
                $this->assertEqualsWithDelta(0.4, $mark->strokeWidth, delta: 1.0e-12);
            }
        }
    }

    public function testStackedLayersStayInTheirHitBoundsAndRetainThreePaintedDocuments(): void
    {
        foreach (['docs', 'st-rect'] as $shape) {
            $scene = self::scene('flowchart; A@{shape:'.$shape.',label:"Sample"}');
            $node = $scene->graph->nodes[0];
            $marks = MermaidOutline::marks($node, $shape);
            $this->assertCount(3, $marks);
            foreach ($marks as $mark) {
                foreach ($mark->points as [$x, $y]) {
                    $this->assertGreaterThanOrEqual($node->x - ($node->w / 2) - 0.001, $x);
                    $this->assertLessThanOrEqual($node->x + ($node->w / 2) + 0.001, $x);
                    $this->assertGreaterThanOrEqual($node->y - ($node->h / 2) - 0.001, $y);
                    $this->assertLessThanOrEqual($node->y + ($node->h / 2) + 0.001, $y);
                }
            }
        }
    }

    public function testNewCurvedAndNotchedSilhouettesRejectEmptyCornersAndReceiveEdgesAtTheirBoundary(): void
    {
        foreach (['delay', 'curv-trap', 'h-cyl', 'lin-cyl', 'bucket', 'folder', 'person', 'notch-pent', 'doc', 'bow-rect'] as $shape) {
            $node = new GraphNode(0, 'A', 'Text', 20, 20, 16, 12, null, null, dashed: false);
            $points = MermaidOutline::points($node, $shape);
            $this->assertNotEmpty($points);
            [$cornerX, $cornerY] = match ($shape) {
                'doc' => [28.0, 26.0],
                'bow-rect' => [12.0, 14.0],
                default => [28.0, 14.0],
            };
            $this->assertFalse(MermaidOutline::contains($node, $shape, $cornerX, $cornerY));
            [$x, $y] = MermaidOutline::boundary($node, $shape, 40, 20);
            $this->assertEqualsWithDelta(20, $y, delta: 1.0e-9);
            $this->assertGreaterThan(20, $x);
            $this->assertLessThanOrEqual(28.001, $x);
        }
    }

    public function testWindowAndPersonTextStayClearOfTheirHeaders(): void
    {
        foreach (['folder', 'bucket', 'browser', 'console', 'person'] as $shape) {
            $scene = self::scene('flowchart; A@{shape:'.$shape.',label:"Text"}');
            $drawing = MermaidDrawing::graph($scene->graph, $scene->shapes);
            \iterator_to_array($drawing);
            $text = \array_find($drawing->getReturn()->marks, static fn ($mark): bool => 'text' === $mark->kind && 'Text' === $mark->text);
            $this->assertInstanceOf(MermaidMark::class, $text);
            $this->assertGreaterThan($scene->graph->nodes[0]->y, $text->points[0][1]);
        }
    }

    public function testCylinderCaptionsClearRingsAndCapsMeetTheirOwnSilhouette(): void
    {
        foreach (['Text', 'First<br/>Second<br/>Third<br/>Fourth'] as $label) {
            $scene = self::scene('flowchart; A@{shape:lin-cyl,label:"'.$label.'"}');
            $node = $scene->graph->nodes[0];
            $outline = MermaidOutline::marks($node, 'lin-cyl');
            $ringBottom = \max(\array_column($outline[3]->points, 1));
            $work = MermaidDrawing::graph($scene->graph, $scene->shapes);
            \iterator_to_array($work);
            $labels = \array_filter($work->getReturn()->marks, static fn (MermaidMark $mark): bool => 'text' === $mark->kind);
            foreach ($labels as $text) {
                $this->assertGreaterThan($ringBottom, $text->points[0][1] - 1.5);
            }
        }
        $horizontal = self::scene('flowchart; A@{shape:h-cyl,label:"Cylinder"}')->graph->nodes[0];
        $marks = MermaidOutline::marks($horizontal, 'h-cyl');
        $left = \min(\array_column($marks[0]->points, 0));
        $capLeft = \min(\array_column($marks[1]->points, 0));
        $this->assertEqualsWithDelta($left, $capLeft, delta: 1.0e-12);
    }

    public function testBracesHaveOneCentralCuspAndMirrorTheirComment(): void
    {
        $node = new GraphNode(0, 'A', 'Text', 20, 20, 16, 12, null, null, dashed: false);
        $left = MermaidOutline::marks($node, 'brace')[0]->points;
        $right = MermaidOutline::marks($node, 'brace-r')[0]->points;
        $cusp = \array_find($left, static fn (array $point): bool => 20.0 === $point[1]);
        $this->assertNotNull($cusp);
        $this->assertSame(\min(\array_column($left, 0)), $cusp[0]);
        foreach ($left as $index => [$x, $y]) {
            $this->assertEqualsWithDelta(40 - $x, $right[$index][0], delta: 1.0e-12);
            $this->assertSame($y, $right[$index][1]);
        }
    }

    public function testPersonHeadsStayCircularAndLongStyledLabelsStayInTheBody(): void
    {
        foreach ([12.0, 40.0] as $height) {
            $node = new GraphNode(0, 'A', 'Text', 20, 30, 16, $height, null, null, dashed: false);
            $head = \array_slice(MermaidOutline::points($node, 'person'), offset: 0, length: 25);
            $centerY = 30 - ($height / 2) + 3.68;
            foreach ($head as [$x, $y]) {
                $this->assertEqualsWithDelta(3.68, \hypot($x - 20, $y - $centerY), delta: 1.0e-10);
            }
        }
        $source = 'flowchart; A@{shape:person,label:"'.\implode('<br/>', \array_fill(0, count: 12, value: 'Line')).'"}; style A font-size:24px';
        $scene = self::scene($source);
        $node = $scene->graph->nodes[0];
        $work = MermaidDrawing::graph($scene->graph, $scene->shapes);
        \iterator_to_array($work);
        $bodyTop = $node->y - ($node->h / 2) + FlowchartShapeGeometry::headBand($node);
        foreach ($work->getReturn()->marks as $mark) {
            if ('text' !== $mark->kind) {
                continue;
            }
            $this->assertGreaterThan($bodyTop, $mark->points[0][1] - (1.5 * $mark->fontScale));
            $this->assertLessThan($node->y + ($node->h / 2), $mark->points[0][1] + (0.5 * $mark->fontScale));
        }
    }

    public function testDocumentsAndPaperTapeKeepDistinctWaveEdges(): void
    {
        $node = new GraphNode(0, 'A', 'Text', 20, 20, 16, 12, null, null, dashed: false);
        $document = MermaidOutline::points($node, 'doc');
        $tape = MermaidOutline::points($node, 'flag');
        $this->assertGreaterThan(4, \count(\array_unique(\array_column($document, 1))));
        $this->assertEqualsWithDelta(0, $document[0][1] - $document[16][1], delta: 1.0e-10);
        $this->assertGreaterThan(0.5, \abs($tape[0][1] - $tape[8][1]));
        $this->assertFalse(MermaidOutline::contains($node, 'bow-rect', 28, 20));
        $this->assertTrue(MermaidOutline::contains($node, 'bow-rect', 12, 20));
    }

    public function testJunctionAndForkDefaultsStayFilledWhileUnfilledStylesRemainExplicit(): void
    {
        foreach (['sm-circ', 'f-circ', 'fork'] as $shape) {
            $scene = self::scene('flowchart; A@{shape:'.$shape.'}');
            $this->assertSame('#333333', MermaidOutline::marks($scene->graph->nodes[0], $shape)[0]->fill);
        }
    }

    public function testUnknownShapeIsRejectedRatherThanSubstituted(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Unknown flowchart shape imaginary');
        self::scene('flowchart; A@{shape:imaginary}');
    }

    /** @return iterable<string, array{string, array{float, float}}> */
    public static function labelOffsets(): iterable
    {
        yield 'doc' => ['doc', [0.0, -0.05]];
        yield 'tag-doc' => ['tag-doc', [0.0, -0.05]];
        yield 'win-pane' => ['win-pane', [0.05, 0.05]];
        yield 'lin-rect' => ['lin-rect', [0.04, 0.0]];
        yield 'div-rect' => ['div-rect', [0.0, -0.08]];
        yield 'bow-rect' => ['bow-rect', [-0.03, 0.0]];
        yield 'brace' => ['brace', [0.04, 0.0]];
        yield 'brace-r' => ['brace-r', [-0.04, 0.0]];
        yield 'rect' => ['rect', [0.0, 0.0]];
    }

    /** @param array{float, float} $offset */
    #[DataProvider('labelOffsets')]
    public function testLabelsShiftOffTheirShapesDecorations(string $shape, array $offset): void
    {
        $node = new GraphNode(0, 'A', 'Text', 20, 20, 16, 12, null, null, dashed: false);
        $this->assertSame($offset, FlowchartShapes::labelOffset($shape, $node));
        $scene = self::scene('flowchart; A@{shape:'.$shape.',label:"Text"}');
        $work = MermaidDrawing::graph($scene->graph, $scene->shapes);
        \iterator_to_array($work);
        $text = \array_find($work->getReturn()->marks, static fn (MermaidMark $mark): bool => 'text' === $mark->kind);
        $plain = self::scene('flowchart; A@{shape:rect,label:"Text"}');
        $plainWork = MermaidDrawing::graph($plain->graph, $plain->shapes);
        \iterator_to_array($plainWork);
        $plainText = \array_find($plainWork->getReturn()->marks, static fn (MermaidMark $mark): bool => 'text' === $mark->kind);
        $node = $scene->graph->nodes[0];
        $this->assertEqualsWithDelta($plainText->points[0][0] - $plain->graph->nodes[0]->x + ($offset[0] * $node->w), $text->points[0][0] - $node->x, delta: 1.0e-9);
        $this->assertEqualsWithDelta($plainText->points[0][1] - $plain->graph->nodes[0]->y + ($offset[1] * $node->h), $text->points[0][1] - $node->y, delta: 1.0e-9);
    }

    public function testBracesWidenByTheirCurlsWithoutGrowingTaller(): void
    {
        $rect = self::scene('flowchart; A@{shape:rect,label:"Text"}')->graph->nodes[0];
        foreach (['brace', 'brace-r', 'braces'] as $shape) {
            $node = self::scene('flowchart; A@{shape:'.$shape.',label:"Text"}')->graph->nodes[0];
            $this->assertSame([$rect->w + 4, $rect->h], [$node->w, $node->h], $shape);
        }
    }

    public function testDataStoresAreOpenBetweenTheirTopAndBottomRules(): void
    {
        $scene = self::scene('flowchart; A@{shape:data-store,label:"Text"}');
        $this->assertSame('datastore', MermaidOutline::canonical($scene->shapes[0]));
        $node = new GraphNode(0, 'A', 'Text', 20, 20, 16, 12, '#ff0000', null, dashed: false);
        $marks = MermaidOutline::marks($node, 'data-store');
        $this->assertSame(['polyline', 'polyline'], \array_column($marks, 'kind'));
        $this->assertSame([[[12.0, 14.0], [28.0, 14.0]], [[12.0, 26.0], [28.0, 26.0]]], \array_column($marks, 'points'));
        $this->assertSame(['#ff0000', '#ff0000'], \array_column($marks, 'stroke'));
    }
}
