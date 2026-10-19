<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz\Drawing;

use Janalis\Viz\GraphViz\Drawing\DotColors;
use PHPUnit\Framework\TestCase;

final class DotColorsTest extends TestCase
{
    public function testColoursResolveToHexOrNone(): void
    {
        $this->assertSame('#ff0000', DotColors::resolve(' red ', '#000000'));
        $this->assertSame('#123456', DotColors::resolve('#123456', '#000000'));
        $this->assertSame('none', DotColors::resolve('Transparent', '#000000'));
        $this->assertSame('none', DotColors::resolve('none', '#000000'));
        $this->assertSame('#000000', DotColors::resolve('', '#000000'));
        $this->assertSame('#abcdef', DotColors::resolve('no such colour', '#abcdef'));
    }
}
