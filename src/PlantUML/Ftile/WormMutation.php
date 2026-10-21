<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\Source\Unsupported;

/**
 * How PlantUML moves each point of an arrow to draw the next of its colours beside it: across
 * a step down or up, down a step across, aslant at a turn, so each copy keeps its distance.
 */
final readonly class WormMutation
{
    /** Each pair of steps' moves, by its directions, as PlantUML numbers them round the compass. */
    private const array DEFINITIONS = [
        'D' => '33',
        'U' => '33',
        'L' => '55',
        'R' => '55',
        'RD' => '123',
        'RU' => '543',
        'LD' => '187',
        'DL' => '345',
        'DR' => '765',
        'UL' => '321',
        'UR' => '781',
    ];

    /** @param non-empty-list<array{float, float}> $moves each point's move */
    private function __construct(
        public array $moves,
    ) {}

    /** @throws Unsupported for an arrow turning in a way PlantUML has no moves for */
    public static function of(Worm $worm, float $delta): self
    {
        $signature = $worm->directions();
        if (\array_key_exists($signature, self::DEFINITIONS)) {
            return self::defined($signature, $delta);
        }
        $moves = [];
        for ($i = 0; $i < (\strlen($signature) - 1); ++$i) {
            $pair = self::defined(\substr($signature, $i, length: 2), $delta)->moves;
            if (0 === $i) {
                $moves[] = $pair[0];
            }
            if (!self::same($moves[\count($moves) - 1], $pair[0])) {
                $pair = \array_map(static fn (array $move): array => [-$move[0], -$move[1]], $pair);
            }
            $moves[] = $pair[1];
            if ($i === (\strlen($signature) - 2)) {
                $moves[] = $pair[2];
            }
        }
        if ([] === $moves) {
            throw new Unsupported('The native PlantUML renderer does not draw this arrow in several colours');
        }

        return new self($moves);
    }

    public function negative(): bool
    {
        return $this->moves[0][0] < 0;
    }

    /** The worm with each point moved by its move. */
    public function mute(Worm $worm): Worm
    {
        $result = new Worm();
        foreach ($worm->points as $index => [$x, $y]) {
            [$dx, $dy] = $this->moves[$index] ?? [0.0, 0.0];
            $result->points[] = [$x + $dx, $y + $dy];
        }

        return $result;
    }

    /** How far across a label moves beside an arrow of so many colours: the farthest move across, once for each colour after the first. */
    public function textShift(int $colors): float
    {
        $xs = \array_column($this->moves, 0);
        // As PlantUML, which starts its largest at the smallest positive number, as good as nothing.
        $max = \max(0.0, ...$xs);
        $min = \min($xs);
        $extreme = \abs($max) > \abs($min) ? $max : $min;

        return $extreme * ($colors - 1);
    }

    /** @throws Unsupported */
    private static function defined(string $signature, float $delta): self
    {
        $definition = self::DEFINITIONS[$signature] ?? throw new Unsupported('The native PlantUML renderer does not draw this arrow in several colours');

        /** @var non-empty-list<array{float, float}> $moves */
        $moves = \array_map(static fn (string $type): array => match ($type) {
            '1' => [0.0, -$delta],
            '2' => [$delta, -$delta],
            '3' => [$delta, 0.0],
            '4' => [$delta, $delta],
            '5' => [0.0, $delta],
            '6' => [-$delta, $delta],
            '7' => [-$delta, 0.0],
            default => [-$delta, -$delta],
        }, \str_split($definition));

        return new self($moves);
    }

    /**
     * @param array{float, float} $a
     * @param array{float, float} $b
     */
    private static function same(array $a, array $b): bool
    {
        return \abs($a[0] - $b[0]) < 0.001 && \abs($a[1] - $b[1]) < 0.001;
    }
}
