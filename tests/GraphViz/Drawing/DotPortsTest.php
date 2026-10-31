<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz\Drawing;

use Janalis\Viz\GraphViz\Drawing\DotNodes;
use Janalis\Viz\GraphViz\Drawing\DotPorts;
use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\GraphViz\Model\DotGraph;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class DotPortsTest extends TestCase
{
    /** A node's box, off the origin. */
    private const array BOX = [100.0, 50.0, 200.0, 90.0];

    public function testCompassPointsStandOnTheBoxsSides(): void
    {
        $graph = self::graph('digraph { a }');
        $at = static fn (string $port): ?array => DotPorts::anchor($graph, $graph->nodes['a'], null, $port, self::BOX)[0];

        $this->assertSame(
            [[150.0, 50.0], [200.0, 50.0], [200.0, 70.0], [200.0, 90.0], [150.0, 90.0], [100.0, 90.0], [100.0, 70.0], [100.0, 50.0]],
            \array_map($at, ['n', 'NE', 'e', 'se', 's', 'sw', 'w', 'nw']),
        );
        $this->assertNull($at('c'), 'the centre is no port of its own');
        $this->assertNull($at('nowhere'));
        $this->assertSame([null, null, ''], DotPorts::anchor($graph, $graph->nodes['a'], null, '', self::BOX));
    }

    public function testFieldsAndCellsAnchorAtTheirCentreOrSides(): void
    {
        $graph = self::graph('digraph { r [shape=record, label="<x> x|<y> y"]; h [label=<<TABLE><TR><TD PORT="p">p</TD></TR></TABLE>>] }');
        $record = DotNodes::record($graph, $graph->nodes['r']);
        $field = static fn (string $port): array => DotPorts::anchor($graph, $graph->nodes['r'], $record, $port, self::BOX);

        $this->assertSame([[175.0, 70.0], [150.0, 50.0, 200.0, 90.0], ''], $field('y'), 'the right field\'s centre, and its box');
        $this->assertSame([150.0, 90.0], $field('x:se')[0], 'and a compass point on the field');
        $this->assertSame('se', $field('x:SE')[2], 'which the edge leaves by');
        $this->assertSame([125.0, 70.0], $field('x:sideways')[0], 'or its centre for any other');
        $this->assertSame([200.0, 70.0], $field('e')[0], 'a compass point names no field: the node\'s side');

        [$cell, $box] = DotPorts::anchor($graph, $graph->nodes['h'], null, 'p:n', self::BOX);
        $this->assertNotNull($box);
        $this->assertEqualsWithDelta(150.0, ($box[0] + $box[2]) / 2, 1e-9, 'the cell in the middle of the node');
        $this->assertEqualsWithDelta(70.0, ($box[1] + $box[3]) / 2, 1e-9);
        $this->assertSame([150.0, $box[1]], $cell, 'its top side');
        $this->assertSame('', DotPorts::anchor($graph, $graph->nodes['h'], null, 'p:c', self::BOX)[2], 'the centre leaves by no side');
    }

    private static function graph(string $source): DotGraph
    {
        return Work::run(new DotParser()->parse($source));
    }
}
