<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Links;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Links\Extremity;
use Janalis\Viz\PlantUML\Links\LinkDecor;
use PHPUnit\Framework\TestCase;

final class ExtremityTest extends TestCase
{
    public function testEveryMarkIsDrawnBackFromTheTip(): void
    {
        foreach (LinkDecor::cases() as $decor) {
            [$marks, $short] = Extremity::marks($decor, [100.0, 100.0], [1.0, 0.0], '#000000', '#ffffff', 1.0);
            $this->assertContainsOnlyInstancesOf(Mark::class, $marks);
            $this->assertSame(LinkDecor::None === $decor, [] === $marks, $decor->value);
            foreach ($marks as $mark) {
                foreach ($mark->points as [$x]) {
                    $this->assertLessThanOrEqual(100.0 + 1e-9, $x, $decor->value.' stays behind its tip');
                }
            }
            $this->assertGreaterThanOrEqual(0.0, $short);
        }
    }

    public function testFilledMarksTakeTheLineColourAndOpenOnesTheBackground(): void
    {
        [[$diamond], $short] = Extremity::marks(LinkDecor::Composition, [0.0, 0.0], [0.0, 1.0], '#123456', '#ffffff', 1.0);
        $this->assertSame(['#123456', 12.0], [$diamond->fill, $short]);
        [[$triangle], $short] = Extremity::marks(LinkDecor::Extends, [0.0, 0.0], [0.0, 1.0], '#123456', '#ffffff', 1.0);
        $this->assertSame(['#ffffff', 19.0], [$triangle->fill, $short]);
        $this->assertEqualsWithDelta([[0.0, 0.0], [7.0, -19.0], [-7.0, -19.0]], $triangle->points, 1e-9, 'pointing down: back is up');
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
