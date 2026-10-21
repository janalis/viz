<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;

/** PlantUML's `ElementLine`: a grey rule across its room, `--` plain, `==` double, `..` dotted, `~~` thicker. */
final readonly class SaltLine implements SaltElement
{
    private const string COLOR = '#aaaaaa';

    public function __construct(
        private string $kind,
    ) {}

    #[\Override]
    public function size(): array
    {
        return [10.0, 6.0];
    }

    #[\Override]
    public function marks(float $x, float $y, int $pass, float $width, float $height): array
    {
        if (0 !== $pass) {
            return [];
        }
        $middle = $y + ($height / 2);

        return match ($this->kind) {
            '=' => [SaltPen::line($x, $middle - 1, $x + $width, $middle - 1, self::COLOR), SaltPen::line($x, $middle + 1, $x + $width, $middle + 1, self::COLOR)],
            '.' => [new Mark(MarkKind::Polyline, [[$x, $middle], [$x + $width, $middle]], stroke: self::COLOR, dashed: true, strokeWidth: 1.0, dashes: [1.0, 2.0])],
            '-' => [SaltPen::line($x, $middle, $x + $width, $middle, self::COLOR)],
            default => [SaltPen::line($x, $middle, $x + $width, $middle, self::COLOR, 1.5)],
        };
    }
}
