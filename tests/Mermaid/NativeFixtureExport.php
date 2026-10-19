<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Draw\NativeRenderer;
use Janalis\Viz\Mermaid\MermaidPipeline;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\Work;

/** Export cases share the pipeline; each family asserts its own graphic and escaping contract. */
final class NativeFixtureExport
{
    public static function render(string $source, Format $format): string
    {
        return Work::run(new NativeRenderer(new MermaidPipeline())->image($source, $format));
    }
}
