<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Color;

use Janalis\Viz\Color\ColorValue;
use PHPUnit\Framework\TestCase;

final class ColorValueTest extends TestCase
{
    public function testHexFormsNormalize(): void
    {
        $this->assertSame('#ffcc00', ColorValue::normalize('#ffcc00'));
        $this->assertSame('#ffcc00', ColorValue::normalize('#FFcc00'), 'lowercased');
        $this->assertSame('#ffcc00', ColorValue::normalize('#fc0'), '#rgb expands');
        $this->assertSame('#ffcc00', ColorValue::normalize('#ffcc0080'), 'alpha stripped');
        $this->assertNull(ColorValue::normalize('#zzz'), 'non-hex digits');
        $this->assertNull(ColorValue::normalize('#ffcc0'), 'wrong length');
    }

    public function testNamesAndUnknowns(): void
    {
        $this->assertSame('#ff0000', ColorValue::normalize('red'));
        $this->assertSame('#add8e6', ColorValue::normalize('LightBlue'), 'case-insensitive');
        $this->assertSame('#bebebe', ColorValue::normalize('grey'));
        $this->assertNull(ColorValue::normalize('chartreuse4'), 'unlisted name falls back to theme');
        $this->assertNull(ColorValue::normalize(null));
        $this->assertNull(ColorValue::normalize('   '));
    }

    public function testHsvTriples(): void
    {
        $this->assertSame('#ff0000', ColorValue::normalize('0.0,1.0,1.0'));
        $this->assertSame('#00ff00', ColorValue::normalize('0.333333 1.0 1.0'), 'space-separated accepted');
        $this->assertSame('#ffffff', ColorValue::normalize('0.5,0.0,1.0'), 'zero saturation is white');
        $this->assertSame('#00ff66', ColorValue::normalize('0.4,1.0,1.0'), 'the green-cyan sextant');
        $this->assertSame('#8000ff', ColorValue::normalize('0.75,1.0,1.0'), 'the blue-violet sextant');
        $this->assertSame('#ff004d', ColorValue::normalize('0.95,1.0,1.0'), 'the magenta-red sextant');
    }

    public function testColorListsTakeTheFirstEntry(): void
    {
        $this->assertSame('#ff0000', ColorValue::normalize('red:blue'));
        $this->assertSame('#ff0000', ColorValue::normalize('red;0.5:blue'), 'gradient weight stripped');
    }
}
