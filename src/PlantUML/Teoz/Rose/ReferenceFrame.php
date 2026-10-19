<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

/**
 * A reference over participants, as Rose draws it: a frame 2 in from its area's sides with
 * `ref` in a tab at its top left, and its text under the tab, set as the style aligns it.
 */
final readonly class ReferenceFrame implements Component
{
    private const float CORNER = 10.0;
    private const float FOOTER = 5.0;
    private const float MARGIN = 2.0;

    public function __construct(
        private RoseText $header,
        private RoseText $text,
        private Pen $pen,
        private string $back,
        private Pen $headerPen,
        private string $headerBack,
        private string $align,
    ) {}

    #[\Override]
    public function size(): array
    {
        return [\max($this->text->width(), $this->headerWidth()) + (2 * self::MARGIN), $this->text->height() + $this->headerHeight() + self::FOOTER];
    }

    #[\Override]
    public function marks(float $x, float $y, Area $area, Layer $layer): array
    {
        if (Layer::Background === $layer) {
            return [];
        }
        $tabWidth = (int) $this->headerWidth();
        $tabHeight = (int) $this->headerHeight();
        $left = $x + self::MARGIN;
        [$textWidth] = $this->text->pure();
        $textX = match ($this->align) {
            'center' => $x + (($area->width - $textWidth) / 2),
            'right' => $x + $area->width - $textWidth - $this->text->padding[1] - self::MARGIN,
            default => $x + $this->text->padding[3] + self::MARGIN,
        };

        return [
            $this->pen->rectangle($left, $y, $area->width - (2 * self::MARGIN), $area->height - self::FOOTER, $this->back),
            $this->headerPen->shape([
                [$left, $y],
                [$left + $tabWidth, $y],
                [$left + $tabWidth, $y + $tabHeight - self::CORNER],
                [$left + $tabWidth - self::CORNER, $y + $tabHeight],
                [$left, $y + $tabHeight],
            ], $this->headerBack),
            ...$this->header->marks($x + 15, $y + 2),
            ...$this->text->marks($textX, $y + $this->text->padding[0] + $tabHeight),
        ];
    }

    private function headerWidth(): float
    {
        return $this->header->pure()[0] + 45;
    }

    private function headerHeight(): float
    {
        return $this->header->pure()[1] + 2;
    }
}
