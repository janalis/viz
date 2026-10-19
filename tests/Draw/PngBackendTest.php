<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Draw;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\PngBackend;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Text\FallbackFonts;
use Janalis\Viz\Text\Font;
use PHPUnit\Framework\TestCase;

final class PngBackendTest extends TestCase
{
    public function testAGlyphTheBundledFaceLacksIsDrawnByAHostFallbackMidRun(): void
    {
        $alef = 0x05D0;
        $fallbacks = new FallbackFonts();
        if ($fallbacks->coverage(Font::FILE)->covers($alef) || null === $fallbacks->font($alef)) {
            $this->markTestSkipped('no host font draws Hebrew where the bundled face cannot');
        }
        $drawing = new Drawing(20.0, 6.0, [new Mark(MarkKind::Text, [[10.0, 4.0]], "a\u{05D0}b", stroke: '#000000')], '#ffffff');

        $withFallback = Work::run(new PngBackend(fallbacks: $fallbacks)->render($drawing));
        $bundledOnly = Work::run(new PngBackend(fallbacks: new FallbackFonts([]))->render($drawing));

        // Three runs — Plex, the fallback, Plex — draw the letter itself instead of the bundled face's empty box.
        $this->assertNotSame($bundledOnly, $withFallback);
    }
}
