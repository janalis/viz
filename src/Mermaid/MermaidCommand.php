<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid;

use Janalis\Viz\Render\Format;

/** The mermaid-cli (`mmdc`) invocation: the diagram on stdin, the image into a file it names. */
final class MermaidCommand
{
    /** mmdc's natural size: a browser's 96 dpi CSS pixel. */
    public const int BASE_DPI = 96;

    /** @return non-empty-list<string> */
    public static function render(string $tool, Format $format, string $output, int $dpi = self::BASE_DPI): array
    {
        return [$tool, '--quiet', '--input', '-', '--outputFormat', $format->value, '--output', $output, '--scale', (string) (\max(1, $dpi) / self::BASE_DPI)];
    }
}
