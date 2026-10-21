<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\NwDiag;

/** Columns placed together, as PlantUML's `NBox` holds a group's: the stages all span, as wide as they lie side by side. */
final class NwBox implements NwStaged
{
    /** @var list<NwBar> */
    private array $bars = [];
    /** @var NwTetris<NwBar> */
    private NwTetris $tetris;

    public function __construct()
    {
        $this->tetris = new NwTetris();
    }

    public function add(NwBar $bar): void
    {
        if (\in_array($bar, $this->bars, strict: true)) {
            return;
        }
        $this->bars[] = $bar;
        $this->tetris->add($bar);
    }

    #[\Override]
    public function start(): int
    {
        return \min(\array_map(static fn (NwBar $bar): int => $bar->start(), $this->bars));
    }

    #[\Override]
    public function end(): int
    {
        return \max(\array_map(static fn (NwBar $bar): int => $bar->end(), $this->bars));
    }

    #[\Override]
    public function width(): int
    {
        return $this->tetris->width();
    }

    /** @return list<array{NwBar, int}> each column and where it stands in the box */
    public function placed(): array
    {
        return $this->tetris->placed();
    }
}
