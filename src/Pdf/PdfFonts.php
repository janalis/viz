<?php

declare(strict_types=1);

namespace Janalis\Viz\Pdf;

/** One document's fonts by file, so each face is read and embedded at most once. */
final class PdfFonts
{
    /** @var array<string, ?PdfFont> null remembers a file that is not an embeddable face */
    private array $fonts = [];

    /** The embeddable face in the file, or null for collections, CFF outlines and unreadable files. */
    public function get(string $path): ?PdfFont
    {
        if (!\array_key_exists($path, $this->fonts)) {
            $font = TrueTypeFont::file($path);
            $this->fonts[$path] = null === $font ? null : new PdfFont($font);
        }

        return $this->fonts[$path];
    }
}
