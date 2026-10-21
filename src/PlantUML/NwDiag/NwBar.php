<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\NwDiag;

/**
 * A server's column, as PlantUML's `NBar` keeps one: the stages from the first network it
 * meets to the last, one column wide, in the box it is placed with.
 */
final class NwBar implements NwStaged
{
    public ?NwBox $parent = null;
    private ?int $start = null;
    private ?int $end = null;

    public function started(): bool
    {
        return null !== $this->start;
    }

    public function add(int $stage): void
    {
        $this->start = null === $this->start ? $stage : \min($this->start, $stage);
        $this->end = null === $this->end ? $stage : \max($this->end, $stage);
    }

    #[\Override]
    public function start(): int
    {
        return $this->start ?? 0;
    }

    #[\Override]
    public function end(): int
    {
        return $this->end ?? 0;
    }

    #[\Override]
    public function width(): int
    {
        return 1;
    }
}
