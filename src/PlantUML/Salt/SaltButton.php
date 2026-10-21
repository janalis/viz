<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

use Janalis\Viz\PlantUML\Classes\ClassBox;

/** PlantUML's `ElementButton`: its text centred in a grey box rounded by 10, its border 2.5 thick. */
final readonly class SaltButton implements SaltElement
{
    private const float STROKE = 2.5;
    private const float MARGIN = 2.0;

    private SaltField $field;

    public function __construct(string $text)
    {
        $this->field = new SaltField($text);
    }

    #[\Override]
    public function size(): array
    {
        [$width, $height] = $this->field->size();

        return [$width + (2 * self::MARGIN) + (2 * self::STROKE), $height + (2 * self::MARGIN) + (2 * self::STROKE)];
    }

    #[\Override]
    public function marks(float $x, float $y, int $pass, float $width, float $height): array
    {
        if (0 !== $pass) {
            return [];
        }
        [$boxWidth, $boxHeight] = $this->size();
        $box = ClassBox::rectangle($x + self::STROKE, $y + self::STROKE, $boxWidth - (2 * self::STROKE), $boxHeight - (2 * self::STROKE), '#000000', '#eeeeee', self::STROKE, 10.0);

        return [$box, ...$this->field->label->marks($x + (($boxWidth - $this->field->label->size()[0]) / 2), $y + self::STROKE + self::MARGIN)];
    }
}
