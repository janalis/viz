<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Process;

use Janalis\Viz\Process\Cli;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Tests\Support\FakeTool;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    private ?FakeTool $tool = null;

    #[After]
    protected function removeTool(): void
    {
        $this->tool?->remove();
    }

    public function testStdinGoesInAndStdoutComesBack(): void
    {
        $this->tool = new FakeTool('pwd; tr a-z A-Z');

        $output = new Cli($this->tool->directory)->run([$this->tool->path(), '-x', 'y z'], 'digraph', timeout: 5.0);

        $this->assertSame(\realpath($this->tool->directory)."\nDIGRAPH", $output);
        $this->assertSame(['-x', 'y z'], $this->tool->argv());
    }

    public function testAFailureCarriesTheToolsStderr(): void
    {
        $this->tool = new FakeTool("echo 'syntax error in line 1' >&2; exit 1");

        $this->expectExceptionObject(new RenderFailure('syntax error in line 1'));

        new Cli()->run([$this->tool->path()], '', timeout: 5.0);
    }

    public function testASilentFailureNamesTheToolAndItsStatus(): void
    {
        $this->tool = new FakeTool('exit 3');

        $this->expectExceptionObject(new RenderFailure('tool exited with status 3'));

        new Cli()->run([$this->tool->path()], '', timeout: 5.0);
    }

    public function testAToolThatRunsTooLongIsStopped(): void
    {
        $this->tool = new FakeTool('sleep 5');

        $this->expectExceptionObject(new RenderFailure('tool timed out after 0.2 seconds'));

        new Cli()->run([$this->tool->path()], '', timeout: 0.2);
    }

    public function testAMissingWorkingDirectoryIsAFailure(): void
    {
        $this->tool = new FakeTool('exit 0');

        $this->expectException(RenderFailure::class);

        new Cli('/nonexistent/viz')->run([$this->tool->path()], '', timeout: 5.0);
    }
}
