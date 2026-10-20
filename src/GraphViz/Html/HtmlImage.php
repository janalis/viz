<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Html;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\GraphViz\DotImage;
use Janalis\Viz\GraphViz\Drawing\DotLabel;

/** A cell's `<IMG/>`: its image at its own size, as dot draws one where `SCALE` is not set. */
final readonly class HtmlImage implements DotLabel
{
    public function __construct(
        public DotImage $image,
    ) {}

    #[\Override]
    public function empty(): bool
    {
        return false;
    }

    #[\Override]
    public function size(): array
    {
        return [$this->image->width, $this->image->height];
    }

    #[\Override]
    public function marks(float $x, float $y): array
    {
        [$width, $height] = $this->size();

        return [new Mark(MarkKind::Image, [[$x - ($width / 2), $y - ($height / 2)], [$x + ($width / 2), $y + ($height / 2)]], $this->image->uri())];
    }

    #[\Override]
    public function ports(float $x, float $y): array
    {
        return [];
    }
}
