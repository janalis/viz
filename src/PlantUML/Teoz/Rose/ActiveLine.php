<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

/**
 * An activation box, 10 wide, as Rose draws it: closed at the top and the bottom, or open
 * where a delay cuts it.
 */
final readonly class ActiveLine implements Component
{
    public const float WIDTH = 10.0;

    public function __construct(
        private Pen $pen,
        private string $back,
        private bool $closeUp = true,
        private bool $closeDown = true,
    ) {}

    #[\Override]
    public function size(): array
    {
        return [self::WIDTH, 0.0];
    }

    #[\Override]
    public function marks(float $x, float $y, Area $area, Layer $layer): array
    {
        if (Layer::Background === $layer || 0.0 === $area->height) {
            return [];
        }
        $x += (int) (($area->width - self::WIDTH) / 2);
        if ($this->closeUp && $this->closeDown) {
            return [$this->pen->rectangle($x, $y, self::WIDTH, $area->height, $this->back)];
        }
        $marks = [
            new Pen($this->back, 0.0)->rectangle($x, $y, self::WIDTH, $area->height, $this->back),
            $this->pen->line($x, $y, $x, $y + $area->height),
            $this->pen->line($x + self::WIDTH, $y, $x + self::WIDTH, $y + $area->height),
        ];
        if ($this->closeUp) {
            $marks[] = $this->pen->line($x, $y, $x + self::WIDTH, $y);
        }
        if ($this->closeDown) {
            $marks[] = $this->pen->line($x, $y + $area->height, $x + self::WIDTH, $y + $area->height);
        }

        return $marks;
    }
}
