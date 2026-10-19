<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid;

use Janalis\Viz\Process\Command;
use Janalis\Viz\Render\Format;

/** The mermaid-cli (`mmdc`) invocation: the diagram on stdin, the image into a file it names. */
final class MermaidCommand implements Command
{
    /** mmdc's natural size: a browser's 96 dpi CSS pixel. */
    public const int BASE_DPI = 96;

    /** @return non-empty-list<string> */
    public static function render(string $tool, Format $format, string $output, int $dpi = self::BASE_DPI): array
    {
        return [$tool, '--quiet', '--input', '-', '--outputFormat', $format->value, '--output', $output, '--scale', (string) (\max(1, $dpi) / self::BASE_DPI)];
    }

    #[\Override]
    public function name(): string
    {
        return 'mmdc';
    }

    #[\Override]
    public function missing(): string
    {
        return 'mmdc not found: install @mermaid-js/mermaid-cli, or configure the path to mmdc';
    }

    #[\Override]
    public function baseDpi(): int
    {
        return self::BASE_DPI;
    }

    #[\Override]
    public function writesFile(): bool
    {
        return true;
    }

    #[\Override]
    public function argv(string $tool, Format $format, int $dpi, ?string $output): array
    {
        return self::render($tool, $format, $output ?? '', $dpi);
    }
}
