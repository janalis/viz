<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Activities\LinkRendering;

/**
 * A tile with its notes beside it, centred down its height: one note 20 away, pointing at the
 * tile unless it floats; several stacked on their side 10 apart, not pointing; or, with no
 * tile, a note alone in the flow.
 */
final class FtileWithNote extends AbstractFtile
{
    private const float SPACE = 20.0;

    /**
     * @param list<Opale> $left
     * @param list<Opale> $right
     */
    private function __construct(
        private readonly ?Ftile $tile,
        private readonly array $left,
        private readonly array $right,
        /** Whether a single note points at the tile. */
        private readonly bool $single,
        private readonly bool $out = true,
    ) {}

    /** One note beside a tile, pointing at it unless it floats. */
    public static function one(Ftile $tile, Opale $note, string $position): self
    {
        return 'left' === $position ? new self($tile, [$note], [], single: true) : new self($tile, [], [$note], single: true);
    }

    /**
     * Several notes beside a tile, each side's stacked.
     *
     * @param list<Opale> $left
     * @param list<Opale> $right
     */
    public static function several(Ftile $tile, array $left, array $right): self
    {
        return new self($tile, $left, $right, single: false);
    }

    /** A note alone in the flow, which goes through it unless it floats. */
    public static function alone(Opale $note, bool $out): self
    {
        return new self(null, [$note], [], single: false, out: $out);
    }

    #[\Override]
    protected function measure(): Geometry
    {
        [$width, $height] = $this->total();
        if (null === $this->tile) {
            return new Geometry($width, $height, $width / 2, 0.0, $this->out ? $height : null);
        }
        $inner = $this->tile->geometry();
        [$dx, $dy] = $this->tileAt();

        return new Geometry($width, $height, $inner->left + $dx, $inner->inY + $dy, null === $inner->outY ? null : $inner->outY + $dy);
    }

    #[\Override]
    public function children(): array
    {
        return null === $this->tile ? [] : [$this->tile];
    }

    /** As PlantUML, a single note's tile is found where it would be without the note. */
    #[\Override]
    public function translateFor(Ftile $child): array
    {
        return $this->single ? [0.0, 0.0] : $this->tileAt();
    }

    #[\Override]
    public function inLink(): LinkRendering
    {
        return $this->tile?->inLink() ?? new LinkRendering();
    }

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        [$width, $height] = $this->total();
        $tile = $this->tile;
        if (null === $tile) {
            $canvas->add(...$this->left[0]->shapes(0.0, 0.0));

            return;
        }
        if ($this->single) {
            $note = $this->left[0] ?? $this->right[0];
            [$noteWidth, $noteHeight] = $note->size();
            [] === $this->left ? $note->point('left', -self::SPACE) : $note->point('right', $noteWidth + self::SPACE);
            $canvas->add(...$note->shapes([] === $this->left ? $width - $noteWidth : 0.0, ($height - $noteHeight) / 2));
        }
        if (!$this->single) {
            [$leftWidth, $leftHeight] = self::stack($this->left);
            [$rightWidth, $rightHeight] = self::stack($this->right);
            $this->drawStack($canvas, $this->left, 0.0, ($height - $leftHeight) / 2, $leftWidth);
            $this->drawStack($canvas, $this->right, $width - $rightWidth, ($height - $rightHeight) / 2, $rightWidth);
        }
        [$dx, $dy] = $this->tileAt();
        $tile->draw($canvas->moved($dx, $dy));
    }

    /** @return array{float, float} where the tile stands */
    private function tileAt(): array
    {
        [, $height] = $this->total();
        $tileHeight = null === $this->tile ? 0.0 : $this->tile->geometry()->height;
        if ($this->single) {
            $dx = [] === $this->left ? 0.0 : $this->left[0]->size()[0] + self::SPACE;

            return [$dx, ($height - $tileHeight) / 2];
        }

        return [self::stack($this->left)[0], ($height - $tileHeight) / 2];
    }

    /** @return array{float, float} */
    private function total(): array
    {
        if (null === $this->tile) {
            return $this->left[0]->size();
        }
        $inner = $this->tile->geometry();
        if ($this->single) {
            [$noteWidth, $noteHeight] = ($this->left[0] ?? $this->right[0])->size();

            return [$inner->width + $noteWidth + self::SPACE, \max($noteHeight, $inner->height)];
        }
        [$leftWidth, $leftHeight] = self::stack($this->left);
        [$rightWidth, $rightHeight] = self::stack($this->right);

        return [$inner->width + $leftWidth + $rightWidth, \max($leftHeight, $rightHeight, $inner->height)];
    }

    /**
     * The size of notes stacked, each 10 clear all round.
     *
     * @param list<Opale> $notes
     * @return array{float, float}
     */
    private static function stack(array $notes): array
    {
        $width = 0.0;
        $height = 0.0;
        foreach ($notes as $note) {
            [$noteWidth, $noteHeight] = $note->size();
            $width = \max($width, $noteWidth + 20);
            $height += $noteHeight + 20;
        }

        return [$width, $height];
    }

    /** @param list<Opale> $notes */
    private function drawStack(Canvas $canvas, array $notes, float $x, float $y, float $width): void
    {
        foreach ($notes as $note) {
            [$noteWidth, $noteHeight] = $note->size();
            $canvas->add(...$note->shapes($x + (($width - $noteWidth - 20) / 2) + 10, $y + 10));
            $y += $noteHeight + 20;
        }
    }
}
