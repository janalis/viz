<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\Source\Unsupported;

/**
 * An arrow's colour, its head's colour, and its style — `normal`, `dashed`, `dotted`, `bold`
 * or `hidden` — as PlantUML reads them from `-[#color,style]->` over the arrow style's own.
 */
final readonly class Rainbow
{
    public function __construct(
        public string $color = '#181818',
        public string $head = '#181818',
        public string $style = 'normal',
    ) {}

    /** The arrow style's own colour. */
    public static function of(Style $arrow): self
    {
        $color = $arrow->color('LineColor', '#181818');

        return new self($color, $arrow->color('HeadColor', $color));
    }

    /**
     * A colour and style as written over this one, or this one when nothing is written.
     *
     * @throws Unsupported for several colours, which PlantUML draws side by side
     */
    public function over(?string $written): self
    {
        if (null === $written) {
            return $this;
        }
        if (\str_contains($written, ';')) {
            throw new Unsupported('The native PlantUML renderer does not draw arrows of several colours: '.$written);
        }
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

        return new self($color, $color, $style);
    }
}
