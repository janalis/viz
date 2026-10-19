<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidDrawing;
use Janalis\Viz\Mermaid\MermaidFallbackFonts;
use Janalis\Viz\Mermaid\MermaidFont;
use Janalis\Viz\Mermaid\MermaidMark;
use Janalis\Viz\Mermaid\MermaidPng;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class MermaidPngTest extends TestCase
{
    public function testAGlyphTheBundledFaceLacksIsDrawnByAHostFallbackMidRun(): void
    {
        $alef = 0x05D0;
        $fallbacks = new MermaidFallbackFonts();
        if ($fallbacks->coverage(MermaidFont::FILE)->covers($alef) || null === $fallbacks->font($alef)) {
            $this->markTestSkipped('no host font draws Hebrew where the bundled face cannot');
        }
        $drawing = new MermaidDrawing(20.0, 6.0, [new MermaidMark('text', [[10.0, 4.0]], "a\u{05D0}b", stroke: '#000000')], '#ffffff');

        $withFallback = Work::run(new MermaidPng(fallbacks: $fallbacks)->render($drawing));
        $bundledOnly = Work::run(new MermaidPng(fallbacks: new MermaidFallbackFonts([]))->render($drawing));

        // Three runs — Plex, the fallback, Plex — draw the letter itself instead of the bundled face's empty box.
        $this->assertNotSame($bundledOnly, $withFallback);
    }
}
