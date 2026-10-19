<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Render;

use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class WorkTest extends TestCase
{
    public function testAGeneratorRunsToItsReturnValue(): void
    {
        $steps = 0;
        $work = (static function () use (&$steps): \Generator {
            for ($i = 0; $i < 3; ++$i) {
                ++$steps;
                yield null;
            }

            return 'done';
        })();

        $this->assertSame('done', Work::run($work));
        $this->assertSame(3, $steps);
    }

    public function testRenderOptionsDefaultToAFileExport(): void
    {
        $options = new \Janalis\Viz\Render\RenderOptions();

        $this->assertNull($options->dpi);
        $this->assertSame(16_000_000, $options->maxPixels);
        $this->assertSame(60.0, $options->timeout);
    }
}
