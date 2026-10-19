<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

/**
 * A group's frame as teoz has Rose draw it: its outline, and its title in a tab at its top
 * left with its comment in brackets after it.
 */
final readonly class GroupingHeader implements Component
{
    private const float CORNER = 10.0;

    public function __construct(
        private RoseText $title,
        private ?RoseText $comment,
        private Pen $pen,
        private float $round,
        private Pen $tabPen,
        private string $tabBack,
    ) {}

    #[\Override]
    public function size(): array
    {
        $comment = null === $this->comment ? 0.0 : $this->title->padding[3] + $this->comment->pure()[0];
        $taller = null === $this->comment ? 0.0 : \max(0.0, $this->comment->pure()[1] - 15);

        return [$this->title->width() + $comment, $this->title->height() + $taller];
    }

    #[\Override]
    public function marks(float $x, float $y, Area $area, Layer $layer): array
    {
        if (Layer::Background === $layer) {
            return [];
        }
        $width = $this->title->width();
        $height = $this->title->height();
        $marks = [
            $this->tabPen->shape([[$x, $y], [$x + $width, $y], [$x + $width, $y + $height - self::CORNER], [$x + $width - self::CORNER, $y + $height], [$x, $y + $height]], $this->tabBack),
            $this->pen->rectangle($x, $y, $area->width, $area->height, 'none', $this->round),
            ...$this->title->marks($x + $this->title->padding[3], $y + $this->title->padding[0]),
        ];
        if (null !== $this->comment) {
            \array_push($marks, ...$this->comment->marks($x + $this->title->padding[3] + $width, $y + $this->title->padding[0] + 1));
        }

        return $marks;
    }
}
