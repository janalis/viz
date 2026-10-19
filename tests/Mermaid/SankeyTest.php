<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Mermaid\MermaidColor;
use Janalis\Viz\Mermaid\MermaidDrawing;
use Janalis\Viz\Mermaid\MermaidMark;
use Janalis\Viz\Mermaid\MermaidPng;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Mermaid\MermaidSvg;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Mermaid\SankeyDiagram;
use Janalis\Viz\Mermaid\SankeyFlow;
use Janalis\Viz\Mermaid\SankeyLayout;
use Janalis\Viz\Mermaid\SankeyLexer;
use Janalis\Viz\Mermaid\SankeyParser;

final class SankeyTest extends TestCase
{
    private static function parse(string $source): SankeyDiagram
    {
        $parser = new SankeyParser();
        foreach (new SankeyLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new SankeyLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testCsvAliasesQuotedPunctuationAndMultilineSourcePositions(): void
    {
        $source = "\r\nSANKEY-BETA\r\n\r\n  \"Source, \"\"漢\"\"\", \"Target; %% []\", \" +1.25e1 \"\r\n  \"Target; %% []\",\"Multi\nline\",.5\n__proto__,\"Multi\nline\",2.";
        $diagram = self::parse($source);
        $this->assertSame(1, $diagram->origin);
        $this->assertSame(['Source, "漢"', 'Target; %% []', "Multi\nline", '__proto__'], \array_column($diagram->nodes, 'label'));
        $this->assertSame([3, 3, 4, 6], \array_column($diagram->nodes, 'origin'));
        $this->assertSame([12.5, 0.5, 2.0], \array_column($diagram->links, 'value'));
        $tokens = \iterator_to_array(new SankeyLexer($source)->statements());
        $this->assertSame([1, 3, 4, 6], \array_column($tokens, 'line'));
        $this->assertSame(2, $tokens[1]->column);
        $this->assertSame(\strpos($source, needle: '"Source'), $tokens[1]->offset);
        $this->assertSame(['', 'B'], \array_column(self::parse("sankey\n\"\",B,0")->nodes, 'label'));
    }

    public function testStandaloneCommentsDoNotConsumeQuotedDataOrCsvKeywords(): void
    {
        $source = "%% header with unclosed \" quote\nsankey\n  %% comment with [\n\"%% not a comment\",sankey,1\nsankey,target,2\n%% tail";
        $diagram = self::parse($source);
        $this->assertSame(['%% not a comment', 'sankey', 'target'], \array_column($diagram->nodes, 'label'));
        $this->assertSame([3, 4], \array_column($diagram->links, 'origin'));
    }

    public function testDuplicateFlowsMergeNodeIdentityButKeepTheirValuesAndOrigins(): void
    {
        $diagram = self::parse("sankey\n A , B , 3\nA,B,2\nB,C,5");
        $this->assertCount(3, $diagram->nodes);
        $this->assertCount(3, $diagram->links);
        $this->assertSame([1, 2, 3], \array_column($diagram->links, 'origin'));
        $this->assertSame([0, 0, 1], \array_column($diagram->links, 'source'));
        $build = SankeyFlow::build($diagram);
        \iterator_to_array($build);
        $flow = $build->getReturn();
        $this->assertSame([[0], [1], [2]], $flow->layers);
        $this->assertSame([1.0, 1.0, 1.0], $flow->weights);
        $this->assertSame([0.6, 0.4, 1.0], $flow->values);
        $this->assertSame([[], [0, 1], [2]], $flow->incoming);
        $this->assertSame([[0, 1], [2], []], $flow->outgoing);
    }

    public function testWeightedRibbonsAreProportionalPackedAndPaintedBehindNodes(): void
    {
        $scene = self::scene("sankey\nA,B,8\nA,C,2");
        $this->assertCount(3, $scene->elements);
        $this->assertCount(6, $scene->graph->nodes);
        [$large, $small] = $scene->graph->edges;
        $largeWidth = $large->ribbon[33][1] - $large->ribbon[0][1];
        $smallWidth = $small->ribbon[33][1] - $small->ribbon[0][1];
        $this->assertSame(96.0, $largeWidth);
        $this->assertSame(24.0, $smallWidth);
        $this->assertSame($large->ribbon[33][1], $small->ribbon[0][1], 'outgoing bands share a boundary without overlap');
        // Labels carry the pinned value line, so each node reserves two text rows.
        $this->assertSame([128.0, 104.0, 32.0], \array_column($scene->selectionGraph->nodes, 'h'));
        $this->assertCount(17, $large->points);
        $this->assertCount(34, $large->ribbon);
        $this->assertSame(5.5, $large->minX);
        $this->assertSame(66.5, $large->maxX);
        $this->assertSame(\min(\array_column($large->ribbon, 1)), $large->minY);
        $this->assertSame(\max(\array_column($large->ribbon, 1)), $large->maxY);
        $this->assertSame(1, $scene->origin(0));
        $this->assertSame(2, $scene->origin(2));
        $this->assertNull($scene->selectable(3), 'label decorations cannot steal node selection');
        $this->assertContains('A → C: 2', \array_column($scene->legend, 'text'));
        $drawing = MermaidDrawing::graph($scene->graph, $scene->shapes);
        \iterator_to_array($drawing);
        $marks = $drawing->getReturn()->marks;
        $this->assertSame('polygon', $marks[0]->kind);
        $this->assertSame($large->ribbon, $marks[0]->points);
        $this->assertSame('#4e79a780', $marks[0]->fill);
        $this->assertSame(0.0, $marks[0]->strokeWidth);
        $this->assertSame('#4e79a7', $marks[2]->fill, 'node marks follow ribbons');
    }

    public function testJustifiedSinksAndDisconnectedComponentsKeepForwardEdges(): void
    {
        $scene = self::scene("sankey\nA,B,1\nB,C,1\nA,D,1\nE,F,1");
        $nodes = $scene->selectionGraph->nodes;
        $this->assertSame($nodes[2]->x, $nodes[3]->x);
        $this->assertSame($nodes[2]->x, $nodes[5]->x);
        $this->assertLessThan($nodes[1]->x, $nodes[0]->x);
        foreach ($scene->graph->edges as $edge) {
            $this->assertGreaterThan($edge->minX, $edge->maxX);
        }
    }

    public function testExtremeFiniteWeightsAndZeroValuesNeverCreateInvalidGeometry(): void
    {
        foreach (["sankey\nA,B,1e308\nA,C,1e308\nC,D,1e-300", "sankey\nA,B,+0\nA,C,-0"] as $source) {
            $scene = self::scene($source);
            foreach ($scene->graph->nodes as $node) {
                $this->assertTrue(\is_finite($node->y));
                $this->assertTrue(\is_finite($node->h));
                $this->assertGreaterThan(0, $node->h);
                $this->assertGreaterThanOrEqual(0, $node->y - ($node->h / 2));
                $this->assertLessThanOrEqual($scene->graph->height, $node->y + ($node->h / 2));
            }
            foreach ($scene->graph->edges as $edge) {
                foreach ($edge->ribbon as [$x, $y]) {
                    $this->assertTrue(\is_finite($x) && \is_finite($y));
                }
            }
        }
        $zero = self::scene("sankey\nA,B,0")->graph->edges[0];
        $this->assertSame($zero->ribbon[0], $zero->ribbon[33]);
    }

    #[DataProvider('cycles')]
    public function testCircularFlowsAreRejectedBeforeAnySceneCanBePublished(string $source): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Circular Sankey');
        self::scene($source);
    }

    /** @return iterable<array{string}> */
    public static function cycles(): iterable
    {
        yield ["sankey\nA,A,1"];
        yield ["sankey\nA,B,1\nB,C,1\nC,A,1"];
        yield ["sankey\nRoot,Leaf,1\nA,B,0\nB,A,0"];
    }

    #[DataProvider('invalidSources')]
    public function testMalformedCsvNumbersAndLimitsAreLocated(string $source, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        self::parse($source);
    }

    /** @return iterable<array{string, string}> */
    public static function invalidSources(): iterable
    {
        yield ['', 'Expected Sankey flow data'];
        yield ['sankey', 'Expected Sankey flow data'];
        yield ['graph TD', 'Expected sankey'];
        yield ["sankey\nA,B", 'exactly three'];
        yield ["sankey\nA,B,1,extra", 'exactly three'];
        yield ["sankey\n\"A\"trailing,B,1", 'exactly three'];
        yield ["sankey\n\"A,B,1", 'Unclosed Sankey CSV'];
        foreach (['', '-1', 'NaN', 'Infinity', '1e309', '1junk', '.', '0x10'] as $value) {
            yield ["sankey\nA,B,".$value, 'finite non-negative'];
        }
        yield ["sankey\n".\str_repeat('A', times: 65_538).',B,1', 'CSV record exceeds'];
        yield ["sankey\n".\str_repeat("A,B,1\n", times: 4097), 'Sankey flow limit'];
        $flows = [];
        for ($index = 0; $index < 2049; ++$index) {
            $flows[] = 'A'.$index.',B'.$index.',1';
        }
        yield ["sankey\n".\implode("\n", $flows), 'Sankey node limit'];
    }

    public function testSvgLinksDefaultToAHalfOpaqueLeftToRightGradient(): void
    {
        $result = NativeFixtureExport::render("sankey\n<script>,Target,10", Format::Svg);
        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringContainsString('<stop offset="0" stop-color="#4e79a7" stop-opacity="0.502"/>', $result, 'pinned links default to a gradient, half opaque');
        $this->assertStringContainsString('fill="url(#gradient-', $result);
        $this->assertStringNotContainsString('<script>', $result);
        $work = MermaidSvg::render(new MermaidDrawing(10.0, 2.0, [new MermaidMark(
            'polygon',
            [[2.0, 0.0], [8.0, 0.0], [8.0, 2.0], [2.0, 2.0]],
            strokeWidth: 0.0,
            gradient: ['#000000', '#ffffff80'],
        )]));
        \iterator_to_array($work);
        $this->assertStringContainsString(
            '<linearGradient id="gradient-0" gradientUnits="userSpaceOnUse" x1="2" y1="0" x2="8" y2="0">'
            .'<stop offset="0" stop-color="#000000" stop-opacity="1"/><stop offset="1" stop-color="#ffffff" stop-opacity="0.502"/></linearGradient>',
            $work->getReturn(),
            'the gradient spans the polygon left to right, a six-digit color fully opaque',
        );
        $this->assertStringContainsString('fill="url(#gradient-0)"', $work->getReturn());
    }

    public function testPngLinksBlendTheirGradientAcrossTheBand(): void
    {
        $result = NativeFixtureExport::render("sankey\n<script>,Target,10", Format::Png);
        $this->assertStringStartsWith("\x89PNG", $result);
        $image = \imagecreatefromstring($result);
        $this->assertInstanceOf(\GdImage::class, $image);
        // A flow band, blended between its ends' colors at the pinned 50% link opacity, over white.
        $this->assertSame(0xe2_c3a5, \imagecolorat($image, x: 500, y: 500));
        $drawing = new MermaidDrawing(10.0, 2.0, [
            new MermaidMark('polygon', [[0.0, 0.0], [10.0, 0.0], [10.0, 2.0], [0.0, 2.0]], strokeWidth: 0.0, gradient: ['#000000ff', '#ffffffff']),
            // A zero-width band crosses no edge it can interpolate, and draws nothing.
            new MermaidMark('polygon', [[5.0, 0.0], [5.0, 1.0], [5.0, 2.0]], strokeWidth: 0.0, gradient: ['#ff0000ff', '#ff0000ff']),
        ]);
        $work = new MermaidPng()->render($drawing);
        \iterator_to_array($work);
        $ramp = \imagecreatefromstring($work->getReturn());
        $this->assertInstanceOf(\GdImage::class, $ramp);
        $this->assertSame(
            [0x00_0000, 0x80_8080, 0xfc_fcfc],
            [\imagecolorat($ramp, x: 0, y: 10), \imagecolorat($ramp, x: 50, y: 10), \imagecolorat($ramp, x: 99, y: 10)],
            'the fill runs left to right',
        );
        $work = new MermaidPng()->render(new MermaidDrawing(10.0, 2.0, [new MermaidMark('polygon', [[0.0, 0.0], [10.0, 0.0], [5.0, 2.0]], strokeWidth: 0.0, gradient: ['#000000ff', '#000000ff'])]));
        \iterator_to_array($work);
        $wedge = \imagecreatefromstring($work->getReturn());
        $this->assertInstanceOf(\GdImage::class, $wedge);
        $this->assertSame(0x00_0000, \imagecolorat($wedge, x: 20, y: 4), 'the closing edge bounds the fill too');
        $this->assertSame(0xff_ffff, \imagecolorat($wedge, x: 2, y: 18), 'outside the wedge stays clear');
    }

    public function testMixedColorsInterpolateEveryChannelIncludingAlpha(): void
    {
        $this->assertSame('#000000ff', MermaidColor::mixed('#000000ff', '#ffffff00', share: 0.0));
        $this->assertSame('#80808080', MermaidColor::mixed('#000000ff', '#ffffff00', share: 0.5));
        $this->assertSame('#ffffff00', MermaidColor::mixed('#000000ff', '#ffffff00', share: 1.0));
        $this->assertSame('#203850ff', MermaidColor::mixed('#102030ff', '#305070ff', share: 0.5));
    }
}
