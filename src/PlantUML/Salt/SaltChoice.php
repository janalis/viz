<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

/** PlantUML's `ElementRadioCheckbox`: a box, or a circle, 10 across, ticked or dotted when on, its text 20 right. */
final readonly class SaltChoice implements SaltElement
{
    private const float MARGIN = 20.0;
    private const float STROKE = 1.5;

    private SaltLabel $label;

    public function __construct(
        string $text,
        private bool $radio,
        private bool $checked,
    ) {
        $this->label = new SaltLabel([$text]);
    }

    #[\Override]
    public function size(): array
    {
        [$width, $height] = $this->label->size();

        return [$width + self::MARGIN, $height];
    }

    #[\Override]
    public function marks(float $x, float $y, int $pass, float $width, float $height): array
    {
        if (0 !== $pass) {
            return [];
        }
        $marks = $this->label->marks($x + self::MARGIN, $y);
        $middle = $y + ($this->size()[1] / 2);
        if ($this->radio) {
            $marks[] = SaltPen::circle($x + 7, $middle, 5.0, 'none', self::STROKE);
            if ($this->checked) {
                $marks[] = SaltPen::circle($x + 7, $middle, 2.0, '#000000', self::STROKE);
            }

            return $marks;
        }
        $marks[] = SaltPen::box($x + 2, $middle - 5, 10.0, 10.0, 'none', '#000000', self::STROKE);
        if ($this->checked) {
            $marks[] = SaltPen::shape([[0.0, 0.0], [3.0, 3.0], [10.0, -6.0], [3.0, 1.0]], $x + 3, $y + 6, '#000000', self::STROKE);
        }

        return $marks;
    }
}
