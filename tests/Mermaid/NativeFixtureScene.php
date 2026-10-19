<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidPipeline;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Render\Work;

/** Scene probes run the same native pipeline as exports, start to finish. */
final class NativeFixtureScene
{
    public static function render(string $source): MermaidScene
    {
        return Work::run(new MermaidPipeline()->scene($source));
    }
}
