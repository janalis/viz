<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Dot;

use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\GraphViz\DotPipeline;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The nodes of one rank left to right after crossing minimisation, each as `dot` 16.1.0 sets them. */
final class DotMincrossTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function graphs(): iterable
    {
        // n0's two edges to n3 cross n1's edge to n2 twice, so n0 comes first; with one, the order the search meets them stands.
        yield 'a crossing counted once for each edge between the same two nodes' => ['digraph { n3; n1; n0; n2; n1 -> n2; n0 -> n3; n0 -> n3; n1 -> n3; n1 -> n2; n0 -> n2 }', ['n0', 'n1']];
        yield 'one edge each way, the order the search meets them' => ['digraph { n3; n1; n0; n2; n1 -> n2; n0 -> n3; n1 -> n3; n0 -> n2 }', ['n1', 'n0']];
        // The initial order is transposed before the flat edge puts n0 before n2.
        yield 'the initial order transposed before its flat edges order it' => ['digraph { n2; n4; n0; n1; n5; n3; n0 -> n1; n2 -> n3; n2 -> n5; n0 -> n2 [minlen=0] }', ['n1', 'n3', 'n5']];
        yield 'a cluster ordered from its initial order by the long pass alone' => [
            'digraph { n1; n0; n3; n2; n4; n0 -> n1; n1 -> n3; n2 -> n3; n2 -> n4; n1 -> n2; subgraph cluster_a { subgraph cluster_b { n3; n4; n0 } n1; n2 } }',
            ['n3', 'n4', 'n0'],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('graphs')]
    public function testARankIsOrderedAsDotOrdersIt(string $source, array $expected): void
    {
        $this->assertSame($expected, self::leftToRight($source, $expected));
    }

    /**
     * @param list<string> $names
     * @return list<string> the nodes so named, left to right
     */
    private static function leftToRight(string $source, array $names): array
    {
        $x = [];
        foreach (Work::run(new DotPipeline()->drawing($source))->marks as $mark) {
            if (MarkKind::Text !== $mark->kind || !\in_array($mark->text, $names, strict: true)) {
                continue;
            }
            $x[$mark->text] = $mark->points[0][0];
        }
        \asort($x);

        return \array_map(strval(...), \array_keys($x));
    }
}
