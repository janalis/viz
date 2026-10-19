<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Classes;

use Janalis\Viz\PlantUML\Links\LinkDecor;

/**
 * A link between two entities as written: its ends in the order dot lays them out, their
 * marks, how many ranks it spans, its line, and its labels in the middle and at each end.
 */
final class ClassLink
{
    public bool $dashed = false;
    public bool $dotted = false;
    public bool $bold = false;
    public bool $hidden = false;
    public bool $norank = false;
    public ?float $thickness = null;
    public ?string $color = null;
    public ?string $label = null;
    /** `>` or `<` when the label points along or against the link. */
    public ?string $labelArrow = null;
    public ?string $firstLabel = null;
    public ?string $secondLabel = null;
    public ?float $weight = null;

    /** @param int $length the dashes in the arrow, 1 for a link drawn across */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly LinkDecor $fromDecor,
        public readonly LinkDecor $toDecor,
        public readonly int $length,
    ) {}

    /** The link with its ends swapped, as an arrow pointing up or left is laid out. */
    public function inverted(): self
    {
        $link = new self($this->to, $this->from, $this->toDecor, $this->fromDecor, $this->length);
        $link->dashed = $this->dashed;
        $link->dotted = $this->dotted;
        $link->bold = $this->bold;
        [$link->firstLabel, $link->secondLabel] = [$this->secondLabel, $this->firstLabel];

        return $link;
    }
}
