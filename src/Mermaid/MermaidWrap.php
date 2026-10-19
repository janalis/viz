<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid;

use Janalis\Viz\Text\DisplayWidth;

/** Linear grapheme wrapping, yielding before a long label can monopolize the event loop. */
final class MermaidWrap
{
    /** @return \Generator<int, null, mixed, string> */
    public static function text(string $text, int $width): \Generator
    {
        $matches = [];
        \preg_match_all('/\X/u', $text, $matches);
        $lines = [];
        $line = '';
        $columns = 0;
        foreach ($matches[0] as $character) {
            $size = DisplayWidth::of($character);
            if ("\n" === $character || ($columns > 0 && ($columns + $size) > $width)) {
                $lines[] = $line;
                $line = '';
                $columns = 0;
            }
            if ("\n" !== $character) {
                $line .= $character;
                $columns += $size;
            }
            yield null;
        }
        $lines[] = $line;

        return \implode("\n", $lines);
    }
}
