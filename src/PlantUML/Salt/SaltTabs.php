<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

/**
 * PlantUML's `ElementTabBar`: its tabs side by side, each open at the bottom and 10 apart,
 * or, when any tab ends a line, one under another, each open on the right.
 */
final class SaltTabs implements SaltElement
{
    private const float BEFORE = 2.0;
    private const float AFTER = 3.0;
    private const float APART = 10.0;

    /** @var list<SaltText> */
    private array $tabs = [];
    public bool $vertical = false;

    public function tab(string $text): void
    {
        $this->tabs[] = new SaltText($text);
    }

    #[\Override]
    public function size(): array
    {
        [$width, $height] = [0.0, 0.0];
        foreach ($this->tabs as $tab) {
            [$tabWidth, $tabHeight] = $tab->size();
            [$width, $height] = $this->vertical
                ? [\max($width, $tabWidth), $height + $tabHeight + self::BEFORE + self::AFTER + self::APART]
                : [$width + $tabWidth + self::BEFORE + self::AFTER + self::APART, \max($height, $tabHeight)];
        }

        return [$width, $height];
    }

    #[\Override]
    public function marks(float $x, float $y, int $pass, float $width, float $height): array
    {
        if (0 !== $pass) {
            return [];
        }
        $marks = [];
        $across = $this->size()[0];
        foreach ($this->tabs as $tab) {
            [$tabWidth, $tabHeight] = $tab->size();
            if ($this->vertical) {
                $bottom = $y + $tabHeight + self::BEFORE + self::AFTER;
                \array_push(
                    $marks,
                    ...$tab->marks($x, $y + self::BEFORE, 0, 0.0, 0.0),
                    ...[
                        SaltPen::line($x, $y, $x + $across, $y),
                        SaltPen::line($x, $y, $x, $bottom),
                        SaltPen::line($x, $bottom, $x + $across, $bottom),
                        SaltPen::line($x + $across, $bottom, $x + $across, $bottom + self::APART),
                    ],
                );
                $y = $bottom + self::APART;
                continue;
            }
            $right = $x + $tabWidth + self::BEFORE + self::AFTER;
            \array_push(
                $marks,
                ...$tab->marks($x + self::BEFORE, $y, 0, 0.0, 0.0),
                ...[
                    SaltPen::line($x, $y, $x, $y + $tabHeight),
                    SaltPen::line($x, $y, $right, $y),
                    SaltPen::line($right, $y, $right, $y + $tabHeight),
                    SaltPen::line($right, $y + $tabHeight, $right + self::APART, $y + $tabHeight),
                ],
            );
            $x = $right + self::APART;
        }

        return $marks;
    }
}
