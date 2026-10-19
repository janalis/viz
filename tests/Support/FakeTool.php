<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Support;

use Symfony\Component\Filesystem\Filesystem;

/**
 * A stand-in command-line tool: a shell script in a private directory that
 * records its argv and stdin and answers what the test scripted, so a renderer
 * is tested without dot, plantuml or mmdc installed.
 */
final class FakeTool
{
    public readonly string $directory;

    public function __construct(string $body)
    {
        $this->directory = \sys_get_temp_dir().'/viz-tool-'.\bin2hex(\random_bytes(8));
        \mkdir($this->directory);
        \file_put_contents($this->path(), "#!/bin/sh\nprintf '%s\\n' \"\$@\" > \"".$this->directory."/argv\"\n".$body."\n");
        \chmod($this->path(), 0o755);
    }

    public function path(): string
    {
        return $this->directory.'/tool';
    }

    /** @return list<string> */
    public function argv(): array
    {
        return \explode("\n", \rtrim((string) \file_get_contents($this->directory.'/argv'), characters: "\n"));
    }

    public function remove(): void
    {
        new Filesystem()->remove($this->directory);
    }
}
