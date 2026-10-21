<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\PlantUML\Style\Style;

/**
 * An arrow's colour, its head's colour, and its style — `normal`, `dashed`, `dotted`, `bold`
 * or `hidden` — as PlantUML reads them from `-[#color,style]->` over the arrow style's own;
 * or several, `-[#red;#blue]->`, drawn side by side, `colorArrowSeparationSpace` apart.
 */
final readonly class Rainbow
{
    /**
     * @param list<self> $stripes each colour of an arrow of several, side by side, the first its own
     */
    public function __construct(
        public string $color = '#181818',
        public string $head = '#181818',
        public string $style = 'normal',
        public array $stripes = [],
        /** The room between the colours of an arrow of several. */
        public float $separation = 0.0,
    ) {}

    /** The arrow style's own colour. */
    public static function of(Style $arrow, float $separation = 0.0): self
    {
        $color = $arrow->color('LineColor', '#181818');

        return new self($color, $arrow->color('HeadColor', $color), separation: $separation);
    }

    /** A colour and style as written over this one, or this one when nothing is written. */
    public function over(?string $written): self
    {
        if (null === $written) {
            return $this;
        }
        if (!\str_contains($written, ';')) {
            return $this->one($written);
        }
        $stripes = \array_map($this->one(...), \explode(';', $written));
        $hidden = [] !== \array_filter($stripes, static fn (self $stripe): bool => 'hidden' === $stripe->style);

        return new self($stripes[0]->color, $stripes[0]->head, $hidden ? 'hidden' : $stripes[0]->style, $stripes, $this->separation);
    }

    private function one(string $written): self
    {
        $color = $this->color;
        $style = 'normal';
        foreach (\explode(',', $written) as $part) {
            $lower = \strtolower(\trim($part));
            if (\in_array($lower, ['dashed', 'dotted', 'bold', 'hidden'], strict: true)) {
                $style = $lower;
                continue;
            }
            $color = PlantUmlColor::parse(\trim($part)) ?? $color;
        }

        return new self($color, $color, $style, separation: $this->separation);
    }
}
