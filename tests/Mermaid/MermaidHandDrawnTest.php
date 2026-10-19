<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Sketch\Rough;
use Janalis\Viz\Draw\Sketch\Sketch;
use Janalis\Viz\Mermaid\MermaidPainter;
use PHPUnit\Framework\TestCase;

/** The pinned `look: handDrawn`: roughjs strokes and hachure in pictures, classic cells. */
final class MermaidHandDrawnTest extends TestCase
{
    private const string FLOWCHART = "flowchart TD\nA[Start]-->B{Choice}\nsubgraph S[Group]\nC(Round)\nend\nB-.->C";

    public function testRoughFamiliesSketchOutlinesFramesAndEdgesButKeepTextAndArrowTips(): void
    {
        $classic = self::marks(self::FLOWCHART);
        $sketched = self::marks(self::handDrawn(self::FLOWCHART, seed: 1));
        $this->assertEquals(self::texts($classic), self::texts($sketched), 'labels stay where they were');
        $polygons = \array_filter($sketched, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind);
        $tips = \array_filter($classic, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && 3 === \count($mark->points));
        $this->assertEquals(\array_values($tips), \array_values(\array_filter($polygons, static fn (Mark $mark): bool => 3 === \count($mark->points))), 'arrow tips stay crisp');
        $this->assertCount(\count($tips), \array_filter($polygons, static fn (Mark $mark): bool => '#ffffff' !== $mark->fill), 'only tips and label boxes stay polygons');
        $widths = \array_count_values(\array_map(
            static fn (Mark $mark): string => (string) $mark->strokeWidth,
            \array_filter($sketched, static fn (Mark $mark): bool => MarkKind::Polyline === $mark->kind),
        ));
        $this->assertArrayHasKey('0.13', $widths, 'outlines are 1.3px');
        $this->assertArrayHasKey('0.15', $widths, 'node hachure is 1.5px');
        $this->assertArrayHasKey('0.3', $widths, 'frame hachure is 3px');
        $dashed = \array_filter($sketched, static fn (Mark $mark): bool => MarkKind::Polyline === $mark->kind && $mark->dashed);
        $dotted = \array_find($classic, static fn (Mark $mark): bool => MarkKind::Polyline === $mark->kind && $mark->dashed);
        $this->assertInstanceOf(Mark::class, $dotted);
        $this->assertCount(2 * (\count($dotted->points) - 1), $dashed, 'each segment of the dotted edge is stroked twice');
        $this->assertGreaterThan(\count($classic) * 5, \count($sketched));
    }

    public function testSeedsAreReproducibleAndZeroDrawsAsOne(): void
    {
        $one = self::marks(self::handDrawn(self::FLOWCHART, seed: 1));
        $this->assertEquals($one, self::marks(self::handDrawn(self::FLOWCHART, seed: 1)));
        $this->assertEquals($one, self::marks(self::handDrawn(self::FLOWCHART, seed: 0)));
        $this->assertEquals($one, self::marks(self::handDrawn(self::FLOWCHART, seed: 4_294_967_297)));
        $this->assertEquals($one, self::marks(self::handDrawn(self::FLOWCHART, seed: 1.9)));
        $this->assertEquals($one, self::marks("---\nconfig:\n  look: handDrawn\n---\n".self::FLOWCHART), 'the default seed is zero');
        $this->assertEquals($one, self::marks(self::handDrawn(self::FLOWCHART, seed: '.inf')), 'an infinite seed is passed over');
        $this->assertNotEquals($one, self::marks(self::handDrawn(self::FLOWCHART, seed: 2)));
        $this->assertNotEquals($one, self::marks(self::handDrawn(self::FLOWCHART, seed: -1.9)));
        $this->assertEquals(self::marks(self::handDrawn(self::FLOWCHART, seed: -1)), self::marks(self::handDrawn(self::FLOWCHART, seed: -1.9)), 'fractions truncate toward zero');
    }

    public function testFamiliesWhosePinnedRendererIgnoresTheLookStayClassic(): void
    {
        $pie = "pie title Pets\n\"Dogs\": 40\n\"Cats\": 30";
        $this->assertEquals(self::marks($pie), self::marks(self::handDrawn($pie, seed: 3)));
        foreach (['classDiagram' => "classDiagram\nclass A", 'mindmap' => "mindmap\nroot((Root))\n  A", 'kanban' => "kanban\nTodo\n  t1[Write]"] as $header => $source) {
            $this->assertNotEquals(self::marks($source), self::marks(self::handDrawn($source, seed: 3)), $header);
        }
    }

    public function testSketchFillsOnlyVisibleColorsAndStrokesOnlyDrawnOutlines(): void
    {
        $sketch = new Sketch(seed: 7);
        $square = [[0.0, 0.0], [4.0, 0.0], [4.0, 4.0], [0.0, 4.0]];
        $marks = $sketch->shape(new Mark(MarkKind::Polygon, $square, stroke: '#112233', fill: '#445566', dashed: true, strokeWidth: 0.4, fillOpacity: 0.5, opacity: 0.8));
        $hachure = \array_filter($marks, static fn (Mark $mark): bool => '#445566' === $mark->stroke);
        $outline = \array_filter($marks, static fn (Mark $mark): bool => '#112233' === $mark->stroke);
        $this->assertCount(\count($marks), [...$hachure, ...$outline]);
        $this->assertGreaterThan(20, \count($hachure));
        $this->assertCount(8, $outline, 'four sides, each stroked twice, each stroke one polyline');
        foreach ($hachure as $mark) {
            $this->assertSame([0.15, 0.4, false, 'polyline'], [$mark->strokeWidth, $mark->opacity, $mark->dashed, $mark->kind->value]);
        }
        foreach ($outline as $mark) {
            $this->assertSame([0.13, 0.8, true], [$mark->strokeWidth, $mark->opacity, $mark->dashed]);
        }
        $wide = $sketch->shape(new Mark(MarkKind::Polygon, $square, fill: '#445566'), hachure: 0.3);
        $this->assertSame(0.3, $wide[0]->strokeWidth);
        foreach (['none', 'transparent', '#44556600', '#FFFFFF00'] as $fill) {
            $this->assertCount(8, $sketch->shape(new Mark(MarkKind::Polygon, $square, fill: $fill)), $fill);
        }
        $this->assertCount(\count($hachure), $sketch->shape(new Mark(MarkKind::Polygon, $square, fill: '#445566', strokeWidth: 0.0)));
        $this->assertCount(\count($hachure), $sketch->shape(new Mark(MarkKind::Polygon, $square, stroke: 'none', fill: '#445566')));
        $this->assertCount(\count($hachure) + 8, $sketch->shape(new Mark(MarkKind::Polygon, $square, fill: '#445566ff')));
        $this->assertCount(2, $sketch->shape(new Mark(MarkKind::Polygon, [[0.0, 0.0], [4.0, 0.0]], fill: '#445566')), 'two points draw one line, unfilled');
        $this->assertCount(4, $sketch->shape(new Mark(MarkKind::Polyline, [[0.0, 0.0], [4.0, 0.0], [4.0, 4.0]], fill: '#445566')), 'an open outline is never filled or closed');
        $text = new Mark(MarkKind::Text, [[1.0, 1.0]], 'label');
        $dot = new Mark(MarkKind::Polygon, [[1.0, 1.0]]);
        $this->assertSame([[$text], [$dot]], [$sketch->shape($text), $sketch->shape($dot)]);
        $edge = $sketch->edge(new Mark(MarkKind::Polyline, [[0.0, 0.0], [30.0, 0.0]], stroke: '#abcdef', dashed: true, strokeWidth: 0.2, opacity: 0.6));
        $this->assertSame(
            Rough::polylines(new Rough(seed: 7, roughness: 0.3)->line([[0.0, 0.0], [300.0, 0.0]]), scale: 10.0),
            \array_map(static fn (Mark $mark): array => $mark->points, $edge),
            'edges are rough at 0.3',
        );
        foreach ($edge as $mark) {
            $this->assertSame(['#abcdef', 0.2, true, 0.6], [$mark->stroke, $mark->strokeWidth, $mark->dashed, $mark->opacity]);
        }
    }

    private static function handDrawn(string $source, int|float|string $seed): string
    {
        return "---\nconfig:\n  look: handDrawn\n  handDrawnSeed: ".$seed."\n---\n".$source;
    }

    /** @return list<Mark> */
    private static function marks(string $source): array
    {
        $work = MermaidPainter::scene(NativeFixtureScene::render($source));
        \iterator_to_array($work);

        return $work->getReturn()->marks;
    }

    /**
     * @param list<Mark> $marks
     * @return list<Mark>
     */
    private static function texts(array $marks): array
    {
        return \array_values(\array_filter($marks, static fn (Mark $mark): bool => MarkKind::Text === $mark->kind));
    }
}
