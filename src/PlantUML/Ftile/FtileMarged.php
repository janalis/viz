<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Activities\LinkRendering;

/**
 * A tile with room around it, as PlantUML's margin tiles give it: either side, below or above,
 * up to a width on its right, centred in a height, a height above and below, or centred in a
 * width at least.
 */
final class FtileMarged extends AbstractFtile
{
    private function __construct(
        public readonly Ftile $tile,
        /** `horizontal`, `right`, `vertical`, `centered`, `marged` or `minimum` */
        private readonly string $kind,
        private readonly float $first = 0.0,
        private readonly float $second = 0.0,
    ) {}

    /** Room left and right, or the tile itself when none. */
    public static function horizontal(Ftile $tile, float $left, float $right): Ftile
    {
        return 0.0 === $left && 0.0 === $right ? $tile : new self($tile, 'horizontal', $left, $right);
    }

    /** Wide enough to reach a point on its right. */
    public static function right(Ftile $tile, float $maxX): self
    {
        return new self($tile, 'right', $maxX);
    }

    /** Room above and below, or the tile itself when none. */
    public static function vertical(Ftile $tile, float $top, float $bottom): Ftile
    {
        return 0.0 === $top && 0.0 === $bottom ? $tile : new self($tile, 'vertical', $top, $bottom);
    }

    /** Room below, always. */
    public static function bottom(Ftile $tile, float $bottom): self
    {
        return new self($tile, 'vertical', 0.0, $bottom);
    }

    /** Centred in a height. */
    public static function centered(Ftile $tile, float $height): self
    {
        return new self($tile, 'centered', $height);
    }

    /** A height above and below. */
    public static function marged(float $top, Ftile $tile, float $bottom): self
    {
        return new self($tile, 'marged', $top, $bottom);
    }

    /** Centred in a width at least. */
    public static function minimum(Ftile $tile, float $width): self
    {
        return new self($tile, 'minimum', $width);
    }

    #[\Override]
    protected function measure(): Geometry
    {
        $inner = $this->tile->geometry();

        return match ($this->kind) {
            'horizontal' => new Geometry($inner->width + $this->first + $this->second, $inner->height, $inner->left + $this->first, $inner->inY, $inner->outY),
            'right' => new Geometry($this->first, $inner->height, $inner->left, $inner->inY, $inner->outY),
            'vertical' => $inner->incVertically($this->first, $this->second),
            'centered' => $inner->translate(0.0, $this->shift())->fixedHeight($this->first),
            'marged' => $inner->translate(0.0, $this->first)->fixedHeight($this->first + $inner->height + $this->second),
            default => new Geometry(\max($inner->width, $this->first), $inner->height, $inner->left + $this->shift(), $inner->inY, $inner->outY),
        };
    }

    #[\Override]
    public function children(): array
    {
        return [$this->tile];
    }

    #[\Override]
    public function translateFor(Ftile $child): array
    {
        return match ($this->kind) {
            'horizontal' => [$this->first, 0.0],
            'right' => [0.0, 0.0],
            'vertical', 'marged' => [0.0, $this->first],
            'centered' => [0.0, $this->shift()],
            default => [$this->shift(), 0.0],
        };
    }

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        [$dx, $dy] = $this->translateFor($this->tile);
        $this->tile->draw($canvas->moved($dx, $dy));
    }

    #[\Override]
    public function inLink(): LinkRendering
    {
        return $this->tile->inLink();
    }

    #[\Override]
    public function outLink(): LinkRendering
    {
        return $this->tile->outLink();
    }

    /** As PlantUML, only a tile decorated, not a margin of its own, keeps its breaks: those below and centred in a width. */
    #[\Override]
    public function weldings(): array
    {
        return \in_array($this->kind, ['vertical', 'minimum'], strict: true) ? $this->tile->weldings() : [];
    }

    /** How far down a centred tile moves, or across a tile centred in a width. */
    private function shift(): float
    {
        $inner = $this->tile->geometry();

        return 'centered' === $this->kind ? ($this->first - $inner->height) / 2 : (\max($inner->width, $this->first) - $inner->width) / 2;
    }
}
