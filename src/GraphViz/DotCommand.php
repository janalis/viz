<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz;

use Janalis\Viz\Process\Command;
use Janalis\Viz\Render\Format;

/** The `dot` invocations, each reading the graph from stdin and writing to stdout. */
final class DotCommand implements Command
{
    /**
     * dot's natural size: 72 dpi is one PostScript point per pixel, which is what
     * lets a bitmap share its coordinates with the layout it was drawn from.
     */
    public const int BASE_DPI = 72;

    /**
     * The laid-out graph as JSON, for {@see DotJsonParser}.
     *
     * @return non-empty-list<string>
     */
    public static function layout(string $tool): array
    {
        return [$tool, '-Tjson0'];
    }

    /**
     * One image. A bitmap is drawn at $dpi with no padding: dot pads by 4 points
     * a side by default, which would shift every coordinate off the layout's, so
     * with `-Gpad=0` the PNG is exactly ceil(bb) × dpi/72 pixels.
     *
     * @return non-empty-list<string>
     */
    public static function render(string $tool, Format $format, int $dpi = self::BASE_DPI): array
    {
        return match ($format) {
            Format::Png => [$tool, '-Tpng', '-Gdpi='.$dpi, '-Gpad=0'],
            Format::Svg => [$tool, '-Tsvg'],
            Format::Pdf => [$tool, '-Tpdf'],
        };
    }

    #[\Override]
    public function name(): string
    {
        return 'dot';
    }

    #[\Override]
    public function missing(): string
    {
        return 'graphviz not found: install it, or configure the path to dot';
    }

    #[\Override]
    public function baseDpi(): int
    {
        return self::BASE_DPI;
    }

    #[\Override]
    public function writesFile(): bool
    {
        return false;
    }

    #[\Override]
    public function argv(string $tool, Format $format, int $dpi, ?string $output): array
    {
        return self::render($tool, $format, $dpi);
    }
}
