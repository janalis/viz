<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Janalis\Viz\Layout\GraphCluster;
use Janalis\Viz\Layout\GraphEdge;
use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidDrawing;
use Janalis\Viz\Mermaid\MermaidMark;
use Janalis\Viz\Mermaid\MermaidPng;
use Janalis\Viz\Mermaid\MermaidSvg;

final class MermaidDrawingTest extends TestCase
{
    private static function drawing(): MermaidDrawing
    {
        $work = MermaidDrawing::graph(
            new GraphLayout(
                30,
                20,
                [
                    new GraphNode(0, 'background', '', 15, 10, 28, 18, '#123456', '#abcdef', dashed: false, background: true),
                    new GraphNode(1, 'a', "Hello\n<script>&\"\x01", 8, 10, 10, 6, null, null, dashed: true),
                    new GraphNode(2, 'b', 'Target', 23, 10, 10, 6, null, null, dashed: false),
                ],
                [
                    new GraphEdge(1, 2, [[13.0, 10.0], [17.5, 10.0]], [18.0, 10.0], 13, 10, 18, 10, '#ff0000', 'edge', [15.0, 8.0], dashed: true),
                    new GraphEdge(1, 1, [[8.0, 5.0], [8.0, 6.0]], null, 8, 5, 8, 6, null, null, null, dashed: false),
                ],
                [new GraphCluster('group', 'Group', 0, 0, 30, 20, null, null)],
            ),
        );
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testRelationshipCaptionsMaskConnectorsAndSplitIntoBaselines(): void
    {
        $edge = new GraphEdge(0, 1, [[1.0, 10.0], [29.0, 10.0]], null, 1, 10, 29, 10, null, "owns\n日本", [15.0, 10.0], dashed: false);
        $work = MermaidDrawing::graph(new GraphLayout(30, 20, [], [$edge], []));
        \iterator_to_array($work);
        $marks = $work->getReturn()->marks;
        $this->assertSame(['polyline', 'polygon', 'text', 'text'], \array_column($marks, 'kind'));
        $this->assertSame('#ffffff', $marks[1]->fill);
        $this->assertSame(0.0, $marks[1]->strokeWidth);
        $this->assertSame([[12.4, 8.0], [17.6, 8.0], [17.6, 12.0], [12.4, 12.0]], $marks[1]->points);
        $this->assertSame(['owns', '日本'], [$marks[2]->text, $marks[3]->text]);
        $this->assertSame([[15.0, 9.5]], $marks[2]->points);
        $this->assertSame([[15.0, 11.5]], $marks[3]->points);
    }

    public function testEmptyEdgeLabelsDoNotEraseConnectors(): void
    {
        $edge = new GraphEdge(0, 1, [[1.0, 10.0], [29.0, 10.0]], null, 1, 10, 29, 10, null, '', [15.0, 10.0], dashed: false);
        $work = MermaidDrawing::graph(new GraphLayout(30, 20, [], [$edge], []));
        \iterator_to_array($work);
        $this->assertSame(['polyline'], \array_column($work->getReturn()->marks, 'kind'));
    }

    public function testRasterStrokeNonePreservesFillsAndOmitsLines(): void
    {
        $drawing = new MermaidDrawing(12, 12, [
            new MermaidMark('polygon', [[2.0, 2.0], [10.0, 2.0], [10.0, 10.0], [2.0, 10.0]], stroke: 'none', fill: '#12ef34'),
            new MermaidMark('polyline', [[0.0, 0.0], [12.0, 12.0]], stroke: 'none'),
        ]);
        $work = new MermaidPng()->render($drawing);
        \iterator_to_array($work);
        $image = \imagecreatefromstring($work->getReturn());
        $this->assertInstanceOf(\GdImage::class, $image);
        $this->assertSame(0x12_ef34, \imagecolorat($image, x: 20, y: 60));
        $this->assertSame(0xff_ffff, \imagecolorat($image, x: 10, y: 10));
    }

    public function testSharedDrawingOrderAndInertSvgWithEmbeddedFont(): void
    {
        $drawing = self::drawing();
        $this->assertSame(30.0, $drawing->width);
        $this->assertSame(20.0, $drawing->height);
        $this->assertSame('polygon', $drawing->marks[0]->kind);
        $this->assertSame('#f5f5f5', $drawing->marks[0]->fill);
        $this->assertSame('Group', $drawing->marks[1]->text);
        $this->assertSame('#abcdef', $drawing->marks[2]->fill, 'background nodes paint before edges');
        $this->assertSame('polyline', $drawing->marks[4]->kind);
        $this->assertSame([[18.0, 10.0], [17.1, 10.4], [17.1, 9.6]], $drawing->marks[5]->points);
        $this->assertSame('edge', \array_last($drawing->marks)->text);
        $work = MermaidSvg::render($drawing);
        \iterator_to_array($work);
        $svg = $work->getReturn();
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($svg, options: \LIBXML_NONET));
        $this->assertSame('300', $dom->documentElement->getAttribute('width'));
        $this->assertSame('200', $dom->documentElement->getAttribute('height'));
        $this->assertSame('0 0 30 20', $dom->documentElement->getAttribute('viewBox'));
        $this->assertSame(0, $dom->getElementsByTagName('script')->length);
        $this->assertStringContainsString('&lt;script&gt;&amp;&quot;', $svg);
        $this->assertStringNotContainsString("\x01", $svg);
        $this->assertStringContainsString('data:font/ttf;base64,', $svg);
        $this->assertStringContainsString('SIL OPEN FONT LICENSE', $svg);
        $this->assertStringContainsString('stroke-dasharray=', $svg);
        $this->assertStringContainsString('fill="none"', $svg);
        $work = MermaidDrawing::graph(new GraphLayout(0, 0, [], [], []));
        \iterator_to_array($work);
        $this->assertSame(1.0, $work->getReturn()->width);
        $this->assertSame(1.0, $work->getReturn()->height);
    }

    public function testRasterColorsGeometryTextAndResolutionCap(): void
    {
        $work = new MermaidPng()->render(self::drawing());
        \iterator_to_array($work);
        $image = \imagecreatefromstring($work->getReturn());
        $this->assertInstanceOf(\GdImage::class, $image);
        $this->assertSame(300, \imagesx($image));
        $this->assertSame(200, \imagesy($image));
        $this->assertSame(0xab_cdef, \imagecolorat($image, x: 20, y: 20));
        $this->assertSame(0xec_ecff, \imagecolorat($image, x: 200, y: 80));
        $this->assertSame(0xff_0000, \imagecolorat($image, x: 150, y: 100));
        foreach ([new MermaidDrawing(20, 20, []), new MermaidDrawing(1.0e9, 1, [])] as $drawing) {
            $work = new MermaidPng(maxPixels: 100)->render($drawing, dpi: 192);
            \iterator_to_array($work);
            $image = \imagecreatefromstring($work->getReturn());
            $this->assertInstanceOf(\GdImage::class, $image);
            $this->assertLessThanOrEqual(100, \imagesx($image) * \imagesy($image));
        }
        $work = new MermaidPng()->render(new MermaidDrawing(1, 1, [new MermaidMark('polyline', [[0.0, 0.0]])]));
        \iterator_to_array($work);
        $this->assertStringStartsWith("\x89PNG", $work->getReturn());
    }

    public function testMissingGdAndInvalidDimensionsAreReported(): void
    {
        foreach ([
            [new MermaidPng(gdAvailable: false), new MermaidDrawing(1, 1, []), 96, 'GD extension'],
            [new MermaidPng(), new MermaidDrawing(0, 1, []), 96, 'raster dimensions'],
            [new MermaidPng(), new MermaidDrawing(1, \INF, []), 96, 'raster dimensions'],
            [new MermaidPng(), new MermaidDrawing(1, 1, []), 0, 'raster dimensions'],
            [new MermaidPng(maxPixels: 0), new MermaidDrawing(1, 1, []), 96, 'raster dimensions'],
        ] as [$backend, $drawing, $dpi, $message]) {
            try {
                \iterator_to_array($backend->render($drawing, $dpi));
                $this->fail('invalid render must fail before allocation');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }
}
