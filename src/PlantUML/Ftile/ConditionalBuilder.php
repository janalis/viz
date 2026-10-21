<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Activities\PositionedNote;
use Janalis\Viz\PlantUML\Activities\Stereogroup;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;

/**
 * A decision's tile, as PlantUML's conditional builder chooses it: its branches side by side,
 * or — when one only stops or is empty — the other under the test and the way round it.
 */
final readonly class ConditionalBuilder
{
    private Ftile $tile1;
    private Ftile $tile2;

    /** @param list<PositionedNote> $notes */
    public function __construct(
        private FtileFactory $factory,
        private BranchTile $branch1,
        private BranchTile $branch2,
        /** The test's diamond's background. */
        private string $back,
        private array $notes,
        /** How many swimlanes the decision stands in. */
        private int $lanes = 1,
        /** The decision's swimlane, and every swimlane's place from the left. */
        private ?string $lane = null,
        /** @var array<string, int> */
        private array $order = [],
    ) {
        $this->tile1 = FtileMarged::minimum($branch1->tile, 30.0);
        $this->tile2 = FtileMarged::minimum($branch2->tile, 30.0);
    }

    public function build(): Ftile
    {
        $first = $this->branch1;
        $second = $this->branch2;

        return match (true) {
            self::short($second) && !self::short($first), $first->empty() && $second->onlySingleStopOrSpot() => $this->down($first, $second),
            self::short($first) && !self::short($second), $second->empty() && $first->onlySingleStopOrSpot() => $this->down($second, $first),
            default => $this->withLinks(),
        };
    }

    /** Whether a branch is empty or only stops. */
    private static function short(BranchTile $branch): bool
    {
        return $branch->empty() || $branch->onlySingleStopOrSpot();
    }

    /** The branch with steps under the test, the other the way round it or across to its stop. */
    private function down(BranchTile $then, BranchTile $other): Ftile
    {
        $left = !$other->onlySingleStopOrSpot() && FtileLane::leftOfAll($this->lane, FtileLane::all($then->tile), $this->order);
        $diamond1 = $left
            ? $this->test()->with(south: $this->positive($then), west: $this->positive($other))
            : $this->test()->with(south: $this->positive($then), east: $this->positive($other));
        $diamond2 = $this->closing($other)?->with(north: $this->out($then)) ?? $this->noDiamond();
        $note = 1 === \count($this->notes) ? $this->factory->looseNote($this->notes[0]) : null;
        $tile = FtileMarged::horizontal(FtileMarged::minimum($then->tile, 30.0), 10.0, 10.0);
        if ($other->onlySingleStopOrSpot()) {
            return new FtileIfDown($diamond1, $diamond2, $tile, $this->factory->arrow, $other->out() ?? $this->factory->arrow, $other->tile, $note);
        }

        return new FtileIfDown($diamond1, $diamond2, $tile, $this->factory->arrow, $other->out() ?? $this->factory->arrow, note: $note, end: $this->factory->end, left: $left);
    }

    /** Both branches side by side, arrows into each from the test's corners and out of each into the closing diamond. */
    private function withLinks(): Ftile
    {
        $label1 = $this->positive($this->branch1);
        $label2 = $this->positive($this->branch2);
        $diamond1 = $this->test()->with(east: $label2, west: $label1);
        $diamond2 = $this->closing($this->branch2)?->with(west: $this->out($this->branch1)) ?? $this->noDiamond();
        [$left, $right] = $this->sideNotes();
        $tile = new FtileIf(
            $diamond1,
            FtileMarged::horizontal($this->tile1, 10.0, 10.0),
            FtileMarged::horizontal($this->tile2, 10.0, 10.0),
            $diamond2,
            $this->branch1,
            $this->branch2,
            $this->factory->arrow,
            $left,
            $right,
            $this->factory->end,
            $this->lanes,
        );
        $result = FtileMarged::horizontal($tile, $tile->marginForLabel1($label1), $tile->marginForLabel2($label2));

        return FtileMarged::vertical($result, $tile->marginForLabels($label1, $label2), 0.0);
    }

    /** @return array{?Opale, ?Opale} the first note on each side */
    private function sideNotes(): array
    {
        $left = null;
        $right = null;
        foreach ($this->notes as $note) {
            if ('left' === $note->position) {
                $left ??= $this->factory->looseNote($note);
                continue;
            }
            $right ??= $this->factory->looseNote($note);
        }

        return [$left, $right];
    }

    /** The test's diamond, of the document's condition style. */
    private function test(): FtileDiamond
    {
        $test = $this->factory->test($this->branch1->branch->labelTest);

        return 'empty' === $this->factory->condition
            ? $this->factory->diamond('empty', $this->back)->with(north: $test)
            : $this->factory->diamond($this->factory->condition, $this->back, $test);
    }

    /** The room closing a decision without a diamond: as high as half a diamond under a line, a quarter otherwise. */
    private function noDiamond(): FtileEmpty
    {
        return new FtileEmpty(0.0, ConditionEnd::Hline === $this->factory->end ? FtileDiamond::HALF : FtileDiamond::HALF / 2);
    }

    /** The diamond closing the decision when both branches leave, none otherwise or when it closes on a line. */
    private function closing(BranchTile $second): ?FtileDiamond
    {
        if (ConditionEnd::Hline === $this->factory->end || !$this->tile1->geometry()->hasOut() || !$this->tile2->geometry()->hasOut()) {
            return null;
        }
        $color = Stereogroup::back($second->branch->specialColors) ?? $second->branch->color;

        return $this->factory->diamond('empty', PlantUmlColor::parse((string) $color) ?? $this->back)->with(east: $this->out($second));
    }

    private function positive(BranchTile $branch): TextBlock
    {
        return $this->factory->label($branch->displayPositive()) ?? new EmptyBlock();
    }

    private function out(BranchTile $branch): ?TextBlock
    {
        return $this->factory->label($branch->tile->outLink()->display);
    }
}
