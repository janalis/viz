<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Process;

use Janalis\Viz\Process\Tool;
use Janalis\Viz\Tests\Support\FakeTool;
use Janalis\Viz\Tests\Support\PreservesPath;
use PHPUnit\Framework\TestCase;

final class ToolTest extends TestCase
{
    use PreservesPath;

    public function testAConfiguredPathWinsOverThePath(): void
    {
        $tool = new Tool('dot', '  /opt/graphviz/bin/dot ');

        $this->assertSame('/opt/graphviz/bin/dot', $tool->path());
        $this->assertSame('dot', $tool->name);
    }

    public function testABlankConfigurationLooksTheToolUpOnThePath(): void
    {
        $fake = new FakeTool('exit 0');
        try {
            \rename($fake->path(), $fake->directory.'/viz-probe');
            \putenv('PATH='.$fake->directory);

            $this->assertSame($fake->directory.'/viz-probe', new Tool('viz-probe', '')->path());
            $this->assertSame($fake->directory.'/viz-probe', new Tool('viz-probe')->path());
            $this->assertNull(new Tool('viz-absent')->path());
        } finally {
            $fake->remove();
        }
    }

    public function testAJarRunsThroughJava(): void
    {
        $this->assertSame(['java', '-jar', '/opt/plantuml.JAR'], Tool::command('/opt/plantuml.JAR'));
        $this->assertSame(['/usr/bin/plantuml'], Tool::command('/usr/bin/plantuml'));
    }
}
