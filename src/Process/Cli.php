<?php

declare(strict_types=1);

namespace Janalis\Viz\Process;

use Janalis\Viz\Render\RenderFailure;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/** Runs a tool to completion with the diagram on stdin, and answers its stdout. */
final readonly class Cli
{
    public function __construct(
        private ?string $workingDirectory = null,
    ) {}

    /**
     * @param non-empty-list<string> $argv
     * @throws RenderFailure carrying the tool's stderr when it fails, times out or cannot start
     */
    public function run(array $argv, string $stdin, float $timeout): string
    {
        $process = new Process($argv, $this->workingDirectory, input: $stdin, timeout: $timeout);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new RenderFailure(\sprintf('%s timed out after %s seconds', \basename($argv[0]), $timeout));
        } catch (RuntimeException $exception) {
            throw new RenderFailure($exception->getMessage(), previous: $exception);
        }
        if (!$process->isSuccessful()) {
            $error = \trim($process->getErrorOutput());

            throw new RenderFailure('' !== $error ? $error : \sprintf('%s exited with status %d', \basename($argv[0]), (int) $process->getExitCode()));
        }

        return $process->getOutput();
    }
}
