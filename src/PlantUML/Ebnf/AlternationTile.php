<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

/**
 * Choices as PlantUML's `ETileAlternation` draws them: the first on the line, the others
 * stacked under it 10 apart, 24 in from either side, each joined to the line by a quarter
 * turn down and back up, 12 round.
 */
final class AlternationTile extends EbnfTile
{
    private const float MARGIN = 12.0;

    /** @var list<EbnfTile> */
    private array $tiles = [];

    /** A choice before the others, as PlantUML's `push`. */
    public function push(EbnfTile $tile): void
    {
        \array_unshift($this->tiles, $tile);
    }

    #[\Override]
    public function width(): float
    {
        return \array_reduce($this->tiles, static fn (float $width, EbnfTile $tile): float => \max($width, $tile->width()), initial: 0.0) + (4 * self::MARGIN);
    }

    #[\Override]
    public function h1(): float
    {
        return $this->tiles[0]->h1();
    }

    #[\Override]
    public function h2(): float
    {
        $height = $this->tiles[0]->h2();
        foreach (\array_slice($this->tiles, offset: 1) as $tile) {
            $height += $tile->height() + 10;
        }

        return $height;
    }

    #[\Override]
    public function marks(float $x, float $y, EbnfPen $pen): array
    {
        [$b, $c] = [$x + self::MARGIN, $x + (2 * self::MARGIN)];
        $r = $x + $this->width();
        [$q, $p] = [$r - self::MARGIN, $r - (2 * self::MARGIN)];
        $line = $y + $this->h1();
        $marks = [];
        $top = $y;
        $last = $line;
        $count = \count($this->tiles);
        foreach ($this->tiles as $index => $tile) {
            $last = $top + $tile->h1();
            \array_push($marks, ...$tile->marks($c, $top, $pen));
            $end = $c + $tile->width();
            \array_push($marks, ...match (true) {
                0 === $index => [...$pen->line($x, $last, $c, $last), ...$pen->line($end, $last, $r, $last)],
                $index < ($count - 1) => [...$pen->corner('sw', self::MARGIN, $b, $last), ...$pen->directed($last, $end, $p, 0.5, 25.0), ...$pen->corner('se', self::MARGIN, $q, $last)],
                default => $pen->directed($last, $end, $p, 0.5, 25.0),
            });
            $top += $tile->height() + 10;
        }
        $down = $last - $line;

        return [
            ...$marks,
            ...$pen->corner('sw', self::MARGIN, $b, $line + $down),
            ...$pen->line($b, $line + self::MARGIN, $b, $line + $down - self::MARGIN),
            ...$pen->corner('ne', self::MARGIN, $b, $line),
            ...$pen->corner('se', self::MARGIN, $q, $line + $down),
            ...$pen->line($q, $line + self::MARGIN, $q, $line + $down - self::MARGIN),
            ...$pen->corner('nw', self::MARGIN, $q, $line),
        ];
    }
}
