<?php

declare(strict_types=1);

namespace Janalis\Viz\Pdf;

/** The `ToUnicode` CMap that makes glyph-id text selectable and searchable again. */
final class PdfToUnicode
{
    /** PDF readers cap one `bfchar` block at a hundred entries. */
    private const int BLOCK = 100;

    /** @param array<int, int> $characters codepoint by two-byte glyph code */
    public static function cmap(array $characters): string
    {
        \ksort($characters);
        $entries = [];
        foreach ($characters as $glyph => $codepoint) {
            $entries[] = \sprintf('<%04X> <%s>', $glyph, self::utf16($codepoint));
        }
        $blocks = '';
        foreach (\array_chunk($entries, self::BLOCK) as $block) {
            $blocks .= \count($block)." beginbfchar\n".\implode("\n", $block)."\nendbfchar\n";
        }

        return (
            "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            ."/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            ."/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            ."1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
            .$blocks
            ."endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend"
        );
    }

    /** UTF-16BE hex; characters beyond the Basic Multilingual Plane become a surrogate pair. */
    public static function utf16(int $codepoint): string
    {
        if ($codepoint <= 0xFFFF) {
            return \sprintf('%04X', $codepoint);
        }
        $offset = $codepoint - 0x1_0000;

        return \sprintf('%04X%04X', 0xD800 | ($offset >> 10), 0xDC00 | ($offset & 0x3FF));
    }
}
