<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz\Drawing;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\GraphViz\Drawing\DotArrow;
use PHPUnit\Framework\TestCase;

final class DotArrowTest extends TestCase
{
    public function testNamesParseIntoPartsFromTheTip(): void
    {
        $this->assertSame([['normal', false, '']], DotArrow::parse('')->parts);
        $this->assertSame([['normal', true, '']], DotArrow::parse('empty')->parts);
        $this->assertSame([['vee', false, 'l']], DotArrow::parse('halfopen')->parts);
        $this->assertSame([['inv', false, ''], ['dot', false, '']], DotArrow::parse('invdot')->parts);
        $this->assertSame([['box', true, 'r'], ['tee', false, '']], DotArrow::parse(' ORBOXtee ')->parts);
        $this->assertSame([['normal', false, '']], DotArrow::parse('bogus')->parts);
        $this->assertSame(4, \count(DotArrow::parse('teeteeteeteetee')->parts));
    }

    public function testLengthsAddUpAndScale(): void
    {
        $this->assertSame(20.0, DotArrow::parse('normal')->length(2.0));
        $this->assertSame(15.0, DotArrow::parse('teebox')->length(1.0));
        // As `dot` holds a sharp tip back, so its stroke reaches no further than the end of the line.
        $this->assertEqualsWithDelta(11.513, DotArrow::parse('normal')->length(1.0, penWidth: 1.0), 1e-3);
        $this->assertSame(0.0, DotArrow::parse('none')->length(1.0));
    }

    public function testEveryShapeDrawsFromItsTipBack(): void
    {
        foreach (['normal', 'inv', 'dot', 'diamond', 'box', 'crow', 'vee', 'tee', 'curve', 'icurve'] as $shape) {
            $marks = DotArrow::parse('o'.$shape)->marks([100.0, 100.0], [0.0, 1.0], size: 1.0, color: '#ff0000', background: '#ffffff', penWidth: 1.0);
            $this->assertCount(\in_array($shape, ['box', 'tee'], strict: true) ? 2 : 1, $marks, $shape);
            $this->assertSame(MarkKind::Polygon, $marks[0]->kind);
            $this->assertSame('#ffffff', $marks[0]->fill);
            foreach ($marks[0]->points as [$x, $y]) {
                $this->assertLessThanOrEqual(100.0 + 1e-9, $y, $shape.' stays behind its tip');
            }
        }
        $this->assertSame([], DotArrow::parse('none')->marks([0.0, 0.0], [1.0, 0.0], size: 1.0, color: '#000000', background: '#ffffff', penWidth: 1.0));
    }

    public function testShapesStandAsDotDrawsThem(): void
    {
        $at = static fn (string $name): array => \array_map(
            static fn (array $point): array => [\round($point[0], precision: 2), \round($point[1], precision: 2)],
            DotArrow::parse($name)->marks([0.0, 0.0], [1.0, 0.0], size: 1.0, color: '#000000', background: '#ffffff', penWidth: 1.0)[0]->points,
        );

        // As measured on `dot`'s output: tips held back from the end of the line by as far as their stroke reaches past them.
        $this->assertSame([[-1.51, 0.0], [-11.51, -3.5], [-11.51, 3.5]], $at('normal'));
        $this->assertSame([[-1.22, 0.0], [-11.22, -4.5], [-5.0, 0.0], [-11.22, 4.5]], $at('vee'));
        $this->assertSame([[-0.9, 0.0], [-6.9, -4.0], [-12.9, 0.0], [-6.9, 4.0]], $at('diamond'));
        $this->assertSame([[-1.0, -5.0], [-3.0, -5.0], [-3.0, 5.0], [-1.0, 5.0]], $at('tee'));
    }

    public function testHalvesAndChainsDrawPartBehindPart(): void
    {
        $left = DotArrow::parse('lnormal')->marks([0.0, 0.0], [1.0, 0.0], size: 1.0, color: '#000000', background: '#ffffff', penWidth: 1.0);
        $this->assertSame('#000000', $left[0]->fill);
        // Travelling right, the left half lies above: no point below the line.
        $this->assertSame([], \array_filter($left[0]->points, static fn (array $point): bool => $point[1] > 1e-9));
        $right = DotArrow::parse('rnormal')->marks([0.0, 0.0], [1.0, 0.0], size: 1.0, color: '#000000', background: '#ffffff', penWidth: 1.0);
        $this->assertSame([], \array_filter($right[0]->points, static fn (array $point): bool => $point[1] < -1e-9));

        $chain = DotArrow::parse('teenormal')->marks([0.0, 0.0], [1.0, 0.0], size: 1.0, color: '#000000', background: '#ffffff', penWidth: 1.0);
        $this->assertCount(3, $chain);
        $this->assertEqualsWithDelta(-5.0, \max(\array_map(static fn (array $point): float => $point[0], $chain[2]->points)), 1e-9);
        $this->assertContainsOnlyInstancesOf(Mark::class, $chain);
    }
}
