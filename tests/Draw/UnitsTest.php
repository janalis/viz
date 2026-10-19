<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Draw;

use Janalis\Viz\Draw\Units;
use PHPUnit\Framework\TestCase;

final class UnitsTest extends TestCase
{
    public function testPointsConvertToTenPixelUnits(): void
    {
        $this->assertEqualsWithDelta(9.6, 72 * Units::POINT, 1e-12, 'an inch is 96 pixels');
        $this->assertSame(1.0, Units::fontScale(12.0));
        $this->assertEqualsWithDelta(7.5, Units::cell(12.0), 1e-12, 'a cell is a 1.0 unit, ten pixels, at 12pt');
        $this->assertEqualsWithDelta(15.0, Units::line(12.0), 1e-12);
    }
}
