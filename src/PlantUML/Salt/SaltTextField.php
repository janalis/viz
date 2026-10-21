<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

/** PlantUML's `ElementTextField`: its text 3 in, underlined, the line turned up 2 at each end. */
final readonly class SaltTextField implements SaltElement
{
    private SaltField $field;

    public function __construct(string $text)
    {
        $this->field = new SaltField($text);
    }

    #[\Override]
    public function size(): array
    {
        [$width, $height] = $this->field->size();

        return [$width + 6, $height + 2];
    }

    #[\Override]
    public function marks(float $x, float $y, int $pass, float $width, float $height): array
    {
        if (0 !== $pass) {
            return [];
        }
        [$fieldWidth] = $this->size();
        [$textWidth, $textHeight] = $this->field->size();
        $up = $y + $textHeight - 3;

        return [
            ...$this->field->label->marks($x + 3, $y),
            SaltPen::line($x + 1, $y + $textHeight, $x + $fieldWidth - 2, $y + $textHeight),
            SaltPen::line($x + 1, $up, $x + 1, $up + 2),
            SaltPen::line($x + $textWidth + 4, $up, $x + $textWidth + 4, $up + 2),
        ];
    }
}
