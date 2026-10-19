<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Pdf;

use Janalis\Viz\Mermaid\MermaidFont;
use Janalis\Viz\Pdf\PdfFonts;
use PHPUnit\Framework\TestCase;

/** A document's faces, read once per file. */
final class PdfFontsTest extends TestCase
{
    public function testEachFileGivesOneFontOrRemembersItHasNone(): void
    {
        $fonts = new PdfFonts();
        $font = $fonts->get(MermaidFont::FILE);
        $this->assertNotNull($font);
        $this->assertSame('IBMPlexMono-Regular', $font->font->name);
        $this->assertSame($font, $fonts->get(MermaidFont::FILE));

        $path = (string) \tempnam(\sys_get_temp_dir(), prefix: 'viz-ttf-');
        try {
            \file_put_contents($path, data: 'not a font');
            $this->assertNull($fonts->get($path));
            \copy(MermaidFont::FILE, $path);
            $this->assertNull($fonts->get($path), 'the refusal is remembered');
            $this->assertNotNull(new PdfFonts()->get($path));
        } finally {
            \unlink($path);
        }
    }
}
