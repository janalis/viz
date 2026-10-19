<?php

declare(strict_types=1);

namespace Janalis\Viz\Process;

use Symfony\Component\Process\ExecutableFinder;

/** A command-line tool: the path a caller configured, else the named executable on the PATH. */
final readonly class Tool
{
    public function __construct(
        public string $name,
        private ?string $configured = null,
        private ExecutableFinder $finder = new ExecutableFinder(),
    ) {}

    /** The tool's path, or null when none is configured and none is installed. */
    public function path(): ?string
    {
        $configured = \trim((string) $this->configured);

        return '' !== $configured ? $configured : $this->finder->find($this->name);
    }

    /**
     * The argv prefix that runs the tool: a `.jar` cannot be executed, so it is
     * handed to java, which is all its launcher scripts do.
     *
     * @return non-empty-list<string>
     */
    public static function command(string $path): array
    {
        return \str_ends_with(\strtolower($path), '.jar') ? ['java', '-jar', $path] : [$path];
    }
}
