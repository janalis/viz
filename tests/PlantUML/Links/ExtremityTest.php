<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Links;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Links\Extremity;
use Janalis\Viz\PlantUML\Links\LinkDecor;
use PHPUnit\Framework\TestCase;

final class ExtremityTest extends TestCase
{
    public function testEveryMarkIsDrawnBackFromTheTipButThoseCentredOnIt(): void
    {
        // As PlantUML draws them: a square, a circle connect and a parenthesis about the tip.
        $centred = [LinkDecor::Square, LinkDecor::CircleConnect, LinkDecor::Parenthesis];
        foreach (LinkDecor::cases() as $decor) {
            [$marks, $short] = Extremity::marks($decor, [100.0, 100.0], [1.0, 0.0], '#000000', '#ffffff', 1.0);
            $this->assertContainsOnlyInstancesOf(Mark::class, $marks);
            $this->assertSame(LinkDecor::None === $decor, [] === $marks, $decor->value);
            foreach ($marks as $mark) {
                foreach ($mark->points as [$x]) {
                    $this->assertLessThanOrEqual(\in_array($decor, $centred, strict: true) ? 110.0 : 100.0 + 1e-9, $x, $decor->value.' stays behind its tip');
                }
            }
            $this->assertGreaterThanOrEqual(0.0, $short);
        }
    }

    public function testFilledMarksTakeTheLineColourAndOpenOnesTheBackground(): void
    {
        [[$diamond], $short] = Extremity::marks(LinkDecor::Composition, [0.0, 0.0], [0.0, 1.0], '#123456', '#ffffff', 1.0);
        $this->assertSame(['#123456', 12.0], [$diamond->fill, $short]);
        [[$triangle], $short] = Extremity::marks(LinkDecor::Redefines, [0.0, 0.0], [0.0, 1.0], '#123456', '#ffffff', 1.0);
        $this->assertSame(['#ffffff', 18.0], [$triangle->fill, $short]);
        $this->assertEqualsWithDelta([[0.0, 0.0], [7.0, -19.0], [-7.0, -19.0]], $triangle->points, 1e-9, 'pointing down: back is up');
    }

    public function testMarksAreDrawnAsPlantUmlsExtremitiesDrawThem(): void
    {
        $box = static function (LinkDecor $decor): array {
            [$marks, $short] = Extremity::marks($decor, [0.0, 0.0], [0.0, 1.0], '#000000', '#ffffff', 1.0);
            $xs = [];
            $ys = [];
            foreach ($marks as $mark) {
                \array_push($xs, ...\array_column($mark->points, 0));
                \array_push($ys, ...\array_column($mark->points, 1));
            }

            return [\round(\min($xs), precision: 3), \round(\max($xs), precision: 3), \round(\min($ys), precision: 3), \round(\max($ys), precision: 3), $short];
        };

        // As the jar draws them pointing down, from the tip: across, then back up, and how far the line stops short.
        $this->assertSame([-6.0, 6.0, -18.0, 0.0, 18.0], $box(LinkDecor::Extends));
        $this->assertSame([-3.0, 3.0, -8.0, 0.0, 8.0], $box(LinkDecor::ArrowTriangle));
        $this->assertSame([-8.0, 8.0, -16.0, 0.0, 16.0], $box(LinkDecor::Plus));
        $this->assertSame([-5.0, 5.0, -5.0, 5.0, 5.0], $box(LinkDecor::Square));
        $this->assertSame([-4.0, 4.0, -13.0, -5.0, 8.0], $box(LinkDecor::NotNavigable));
        $this->assertSame([-4.0, 4.0, -8.0, 0.0, 8.0], $box(LinkDecor::DoubleLine));
        $this->assertSame([-4.0, 4.0, -15.0, 0.0, 15.0], $box(LinkDecor::CircleLine));
        $this->assertSame([-6.0, 6.0, -18.0, 0.0, 18.0], $box(LinkDecor::CircleCrowfoot));
        $this->assertSame([-7.0, 7.0, -26.7, 0.0, 18.0], $box(LinkDecor::DefinedBy));
        // A parenthesis: an arc 9 about the tip, taken as its whole circle.
        $this->assertSame([-9.0, 9.0, -9.0, 9.0, 10.0], $box(LinkDecor::Parenthesis));
    }

    public function testDecorsReadBothSpellingsAndKeepTheirMargins(): void
    {
        $this->assertSame(LinkDecor::Extends, LinkDecor::left('<|'));
        $this->assertSame(LinkDecor::Extends, LinkDecor::right('|>'));
        $this->assertSame(LinkDecor::Arrow, LinkDecor::left('<_'));
        $this->assertSame(LinkDecor::HalfArrowUp, LinkDecor::right('\\\\'));
        $this->assertSame(LinkDecor::None, LinkDecor::left(null));
        $this->assertSame(LinkDecor::None, LinkDecor::right('???'));
        $this->assertSame(
            [2.0, 30.0, 15.0, 15.0, 1.0, 30.0, 30.0, 10.0, 14.0, 10.0, 7.0, 10.0, 10.0, 10.0, 0.0],
            \array_map(static fn (LinkDecor $decor): float => $decor->margin(), \array_slice(LinkDecor::cases(), offset: 0, length: 15)),
        );
    }
}
