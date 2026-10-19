<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidOutline;
use PHPUnit\Framework\TestCase;

final class MermaidOutlineTest extends TestCase
{
    public function testHitTestingRejectsEmptyCornersAndIncludesTheOutline(): void
    {
        $node = new GraphNode(0, 'n', '', 10, 10, 10, 10, null, null, dashed: false);
        $this->assertFalse(MermaidOutline::contains($node, 'diamond', x: 5.5, y: 5.5));
        $this->assertTrue(MermaidOutline::contains($node, 'diamond', x: 5.0, y: 10.0));
        $this->assertTrue(MermaidOutline::contains($node, 'rect', x: 5.0, y: 5.0));
        $this->assertFalse(MermaidOutline::contains($node, 'circle', x: 5.5, y: 5.5));
        $this->assertFalse(MermaidOutline::contains($node, 'rounded', x: 5.1, y: 5.1));
        $this->assertSame([12.5, 12.5], MermaidOutline::boundary($node, 'diamond', towardX: 20.0, towardY: 20.0));
        $this->assertSame([15.0, 15.0], MermaidOutline::boundary($node, 'rect', towardX: 20.0, towardY: 20.0));
        $this->assertSame([10.0, 5.0], MermaidOutline::boundary($node, 'rect', towardX: 10.0, towardY: 10.0));
        $this->assertEqualsWithDelta([13.535_533_906, 13.535_533_906], MermaidOutline::boundary($node, 'circle', towardX: 20.0, towardY: 20.0), delta: 0.000_001);
        $point = new GraphNode(0, 'point', '', 10, 10, 0, 0, null, null, dashed: false);
        $this->assertSame([10.0, 10.0], MermaidOutline::boundary($point, 'rect', towardX: 20.0, towardY: 20.0));
    }

    public function testOddNodesAreFlagsAndLinedDocumentsKeepTheirMargin(): void
    {
        $node = new GraphNode(0, 'n', '', 10, 10, 12, 6, null, null, dashed: false);
        $this->assertSame('asymmetric', MermaidOutline::canonical('odd'));
        $this->assertSame('lin-doc', MermaidOutline::canonical('lined-document'));
        $this->assertSame([[4.0, 7.0], [16.0, 7.0], [16.0, 13.0], [4.0, 13.0], [6.0, 10.0]], MermaidOutline::points($node, 'odd'));
        $this->assertSame(MermaidOutline::points($node, 'lin-doc'), MermaidOutline::points($node, 'lined-document'));
        $marks = MermaidOutline::marks($node, 'lined-document');
        $this->assertCount(2, $marks);
        $this->assertSame([[5.0, 7.0], [5.0, 12.25]], $marks[1]->points);
    }
}
