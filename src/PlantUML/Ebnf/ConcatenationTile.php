<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

/** Terms one after the other, as PlantUML's `ETileConcatenation` draws them: on one line, 20 apart, arrows between them. */
final class ConcatenationTile extends EbnfTile
{
    private const float MARGIN = 20.0;

    /** @var list<EbnfTile> */
    private array $tiles = [];

    /** A tile before the others, as PlantUML's `push`. */
    public function push(EbnfTile $tile): void
    {
        \array_unshift($this->tiles, $tile);
    }

    public function pushLast(EbnfTile $tile): void
    {
        $this->tiles[] = $tile;
    }

    public function first(): EbnfTile
    {
        return $this->tiles[0];
    }

    public function replaceFirst(EbnfTile $tile): void
    {
        $this->tiles[0] = $tile;
    }

    #[\Override]
    public function width(): float
    {
        return \array_sum(\array_map(static fn (EbnfTile $tile): float => $tile->width(), $this->tiles)) + (\max(0, \count($this->tiles) - 1) * self::MARGIN);
    }

    #[\Override]
    public function h1(): float
    {
        return \array_reduce($this->tiles, static fn (float $h1, EbnfTile $tile): float => \max($h1, $tile->h1()), initial: 0.0);
    }

    #[\Override]
    public function h2(): float
    {
        return \array_reduce($this->tiles, static fn (float $h2, EbnfTile $tile): float => \max($h2, $tile->h2()), initial: 0.0);
    }

    #[\Override]
    public function marks(float $x, float $y, EbnfPen $pen): array
    {
        $line = $y + $this->h1();
        $marks = $pen->line($x, $line, $x, $line);
        $left = $x;
        $last = \count($this->tiles) - 1;
        foreach ($this->tiles as $index => $tile) {
            \array_push($marks, ...$tile->marks($left, $line - $tile->h1(), $pen));
            $left += $tile->width();
            if ($index === $last) {
                continue;
            }
            \array_push($marks, ...$pen->directed($line, $left, $left + self::MARGIN, 0.5, 25.0));
            $left += self::MARGIN;
        }

        return $marks;
    }
}
