<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Links;

/**
 * A link between two entities as written, in any diagram that has them: its ends in the order dot lays them out, their
 * marks, how many ranks it spans, its line, and its labels in the middle and at each end.
 */
final class PlantUmlLink
{
    public bool $dashed = false;
    public bool $dotted = false;
    public bool $bold = false;
    public bool $hidden = false;
    public bool $norank = false;
    /** Whether it was turned round, as an arrow pointing up or left is: svek then writes its start first. */
    public bool $reversed = false;
    public ?float $thickness = null;
    public ?string $color = null;
    public ?string $label = null;
    /** `>` or `<` when the label points along or against the link. */
    public ?string $labelArrow = null;
    public ?string $firstLabel = null;
    public ?string $secondLabel = null;
    public ?float $weight = null;
    /** The stereotype written after the link, such as `include` in `A ..> B <<include>>`. */
    public ?string $stereotype = null;
    /** The qualifier written at its first end, as `A [key] --> B`, and at its second. */
    public ?string $fromQualifier = null;
    public ?string $toQualifier = null;
    /** Whether a circle stands midway along it, as `A --0-- B` draws one. */
    public bool $middleCircle = false;

    /** @param int $length the dashes in the arrow, 1 for a link drawn across */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly LinkDecor $fromDecor,
        public readonly LinkDecor $toDecor,
        public readonly int $length,
    ) {}

    /** The link spanning as many ranks as given, all else kept. */
    public function lengthened(int $length): self
    {
        return clone($this, ['length' => $length]);
    }

    /** The link with its ends swapped, as an arrow pointing up or left is laid out. */
    public function inverted(): self
    {
        $link = new self($this->to, $this->from, $this->toDecor, $this->fromDecor, $this->length);
        $link->dashed = $this->dashed;
        $link->dotted = $this->dotted;
        $link->bold = $this->bold;
        $link->reversed = !$this->reversed;
        [$link->firstLabel, $link->secondLabel] = [$this->secondLabel, $this->firstLabel];
        [$link->fromQualifier, $link->toQualifier] = [$this->toQualifier, $this->fromQualifier];
        $link->middleCircle = $this->middleCircle;

        return $link;
    }
}
