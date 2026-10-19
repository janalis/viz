<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Render;

use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    public function testEachFormatNamesItsExtensionAndMediaType(): void
    {
        $this->assertSame(['svg', 'png', 'pdf'], \array_map(static fn (Format $format): string => $format->extension(), Format::cases()));
        $this->assertSame(['image/svg+xml', 'image/png', 'application/pdf'], \array_map(static fn (Format $format): string => $format->mimeType(), Format::cases()));
    }

    public function testAPathNamesItsFormatByExtensionInAnyCase(): void
    {
        $this->assertSame(Format::Svg, Format::forPath('/out/diagram.svg'));
        $this->assertSame(Format::Png, Format::forPath('diagram.PNG'));
        $this->assertSame(Format::Pdf, Format::forPath('a.b/diagram.pdf'));
        $this->assertNull(Format::forPath('diagram.jpg'));
        $this->assertNull(Format::forPath('diagram'));
    }
}
