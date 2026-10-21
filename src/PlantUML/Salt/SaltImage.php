<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

/** PlantUML's `ElementImage`: a picture drawn a point for each `X` of its rows. */
final readonly class SaltImage implements SaltElement
{
    /** @param non-empty-list<string> $rows */
    public function __construct(
        private array $rows,
    ) {}

    #[\Override]
    public function size(): array
    {
        return [(float) \strlen($this->rows[0]), (float) \count($this->rows)];
    }

    #[\Override]
    public function marks(float $x, float $y, int $pass, float $width, float $height): array
    {
        if (0 !== $pass) {
            return [];
        }
        $marks = [];
        $columns = \strlen($this->rows[0]);
        foreach ($this->rows as $row => $pixels) {
            for ($column = 0; $column < $columns; ++$column) {
                if ('X' !== ($pixels[$column] ?? '')) {
                    continue;
                }
                $marks[] = SaltPen::box($x + $column, $y + $row, 1.0, 1.0, '#000000', '#000000', 0.0);
            }
        }

        return $marks;
    }
}
