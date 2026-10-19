<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML;

use Janalis\Viz\Process\Tool;
use Janalis\Viz\Render\Format;

/** The `plantuml` invocation: the diagram on stdin, the image on stdout. */
final class PlantUmlCommand
{
    /** PlantUML's natural size: its `dpi` skinparam defaults to 96, where dot's is 72. */
    public const int BASE_DPI = 96;

    /**
     * The full argv for one render. A `.jar` runs through java.
     *
     * `-failfast2` checks the whole document BEFORE drawing, so a syntax error
     * exits non-zero instead of producing an image of the error message — which
     * is what PlantUML does by default, and would otherwise arrive as a perfectly
     * valid picture of a mistake.
     *
     * @return non-empty-list<string>
     */
    public static function render(string $path, Format $format, int $dpi = self::BASE_DPI): array
    {
        $argv = [...Tool::command($path), '-t'.$format->value, '-pipe', '-failfast2', '-charset', 'UTF-8'];

        return Format::Png === $format ? [...$argv, '-Sdpi='.$dpi] : $argv;
    }
}
