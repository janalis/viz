<?php

declare(strict_types=1);

namespace Janalis\Viz\Process;

use Janalis\Viz\Render\Format;

/** How one command-line tool draws a diagram: its invocation, and what to say when it is missing. */
interface Command
{
    /** The tool's name in a message. */
    public function name(): string;

    /** The failure when the tool is not installed, saying how to fix it. */
    public function missing(): string;

    /** The resolution at which the tool draws a bitmap at its natural size. */
    public function baseDpi(): int;

    /** Whether the tool writes the image into a file it is given, rather than to stdout. */
    public function writesFile(): bool;

    /**
     * @param ?string $output the file to write, for a tool that {@see writesFile()}
     * @return non-empty-list<string>
     */
    public function argv(string $tool, Format $format, int $dpi, ?string $output): array;
}
