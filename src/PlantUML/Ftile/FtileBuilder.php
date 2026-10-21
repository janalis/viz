<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Activities\Instruction\Branch;
use Janalis\Viz\PlantUML\Activities\Instruction\Instruction;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionBreak;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionFork;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionGroup;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionIf;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionList;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionRepeat;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionSimple;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionSpot;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionSwitch;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionTerminal;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionWhile;
use Janalis\Viz\PlantUML\Activities\LinkRendering;
use Janalis\Viz\PlantUML\Activities\PositionedNote;
use Janalis\Viz\PlantUML\Activities\Stereogroup;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\Source\Unsupported;

/** An activity diagram's steps as tiles, each made as PlantUML's instructions make theirs. */
final readonly class FtileBuilder
{
    /**
     * @param ?\WeakMap<Instruction, string> $lanes the swimlane each step was written in, when it has several
     * @param array<string, int> $order each swimlane's place from the left
     */
    public function __construct(
        private FtileFactory $factory,
        private ?\WeakMap $lanes = null,
        private array $order = [],
    ) {}

    /** Steps one under the other, the arrow out of the last as written at the end of their block. */
    public function list(InstructionList $list): Ftile
    {
        if ($list->empty()) {
            $empty = new FtileEmpty();

            return null === $list->outRendering ? $empty : $this->factory->decorateIn($empty, $list->outRendering);
        }
        $breaks = [];
        $result = [] === $list->notes ? null : $this->factory->noteAlone($list->notes[0]);
        foreach ($list->all as $instruction) {
            $tile = $this->tile($instruction);
            \array_push($breaks, ...$tile->weldings());
            if (!$instruction->inLink()->none()) {
                $tile = $this->factory->decorateIn($tile, $instruction->inLink());
            }
            $result = null === $result ? $tile : $this->factory->assembly($result, $tile);
        }
        \assert(null !== $result, description: 'a list with steps has a tile');
        if (null !== $list->outRendering) {
            $result = $this->factory->decorateOut($result, $list->outRendering);
        }

        return [] === $breaks ? $result : new FtileDecorate($result, weldings: $breaks);
    }

    /** @throws Unsupported for a step PlantUML draws in ways not ported */
    public function tile(Instruction $instruction): Ftile
    {
        $tile = $this->unlaned($instruction);
        if (null === $this->lanes) {
            return $tile;
        }

        return new FtileLane($tile, $this->lanes[$instruction] ?? throw new Unsupported('The native PlantUML renderer does not draw a step outside any swimlane'));
    }

    private function unlaned(Instruction $instruction): Ftile
    {
        return match (true) {
            $instruction instanceof InstructionSimple => $this->simple($instruction),
            $instruction instanceof InstructionTerminal => $this->noted($this->factory->circle($instruction->kind, $instruction->stereogroup), $instruction->notes),
            $instruction instanceof InstructionSpot => $this->spot($instruction),
            $instruction instanceof InstructionBreak => new FtileBreak($this->lanes[$instruction] ?? null),
            $instruction instanceof InstructionIf => $this->decision($instruction),
            $instruction instanceof InstructionSwitch => $this->switch($instruction),
            $instruction instanceof InstructionWhile => $this->loop($instruction),
            $instruction instanceof InstructionRepeat => $this->repeat($instruction),
            $instruction instanceof InstructionFork => $this->parallel($instruction),
            $instruction instanceof InstructionGroup => $this->group($instruction),
            default => throw new Unsupported('The native PlantUML renderer does not draw this activity step'),
        };
    }

    /** @param list<PositionedNote> $notes */
    private function noted(Ftile $tile, array $notes): Ftile
    {
        return [] === $notes ? $tile : $this->factory->withNotes($tile, $notes);
    }

    /** @param list<PositionedNote> $notes */
    private function looseNoted(Ftile $tile, array $notes): Ftile
    {
        return [] === $notes ? $tile : $this->factory->withLooseNotes($tile, $notes);
    }

    private function simple(InstructionSimple $simple): Ftile
    {
        $tile = $this->noted($this->factory->activity($simple->label, $simple->stereogroup), $simple->notes);

        return $simple->killed ? new FtileKilled($tile) : $tile;
    }

    private function spot(InstructionSpot $spot): Ftile
    {
        $tile = $this->noted($this->factory->spot($spot->spot, $spot->color), $spot->notes);

        return $spot->killed ? new FtileKilled($tile) : $tile;
    }

    private function decision(InstructionIf $if): Ftile
    {
        $else = $if->else ?? new Branch(new LinkRendering());
        $thens = \array_map($this->branch(...), $if->thens);
        $elseTile = $this->branch($else);
        $diamond = $this->factory->styles->diamond($if->stereogroup);
        $back = PlantUmlColor::parse((string) $if->thens[0]->color) ?? $diamond->color('BackGroundColor', '#f1f1f1');
        $result = 1 === \count($thens)
            ? new ConditionalBuilder($this->factory, $thens[0], $elseTile, $back, $if->notes, $this->laneCount($if, $thens[0]->tile, $elseTile->tile), $this->lanes[$if] ?? null, $this->order)->build()
            : $this->long($if, $thens, $elseTile, $back);
        $breaks = [];
        foreach ([...$thens, $elseTile] as $branch) {
            \array_push($breaks, ...$branch->tile->weldings());
        }

        return [] === $breaks ? $result : new FtileDecorate($result, weldings: $breaks);
    }

    /**
     * A `switch`: each case with room for its labels in and out, between its test's diamond
     * and the one its cases meet at, coloured by the first case's colour or the switch's; its
     * own notes at its top.
     *
     * @throws Unsupported for a switch without cases
     */
    private function switch(InstructionSwitch $switch): Ftile
    {
        if ([] === $switch->cases) {
            throw new Unsupported('The native PlantUML renderer does not draw a switch without cases');
        }
        $cases = \array_map($this->branch(...), $switch->cases);
        $first = Stereogroup::back($switch->cases[0]->specialColors);
        $diamond1 = $this->factory->diamond('inside', $first ?? Stereogroup::back($switch->stereogroup), $this->factory->test($switch->test));
        $diamond2 = $this->factory->diamond('inside', $first ?? Stereogroup::back($switch->endColors) ?? Stereogroup::back($switch->stereogroup));
        $positives = \array_map(fn (BranchTile $case): ?TextBlock => $this->factory->label($case->displayPositive()), $cases);
        $specials = \array_map(static fn (BranchTile $case): ?array => $case->branch->special?->display, $cases);
        $specials = \array_map($this->factory->label(...), $specials);
        $tiles = [];
        foreach ($cases as $index => $case) {
            $tiles[] = self::labelled($case->tile, $positives[$index], $specials[$index]);
        }
        $tile = new FtileSwitch($tiles, $diamond1, $diamond2, $positives);
        $result = new FtileDecorate($tile, connections: [new Arrows(new SwitchArrows($tile, $this->factory->arrow, $positives, $specials)->snakes())]);
        $breaks = [];
        foreach ($cases as $case) {
            \array_push($breaks, ...$case->tile->weldings());
        }
        $result = [] === $breaks ? $result : new FtileDecorate($result, weldings: $breaks);

        return [] === $switch->notes ? $result : $this->factory->withNotes($result, $switch->notes)->atTop();
    }

    /** A case with room above it for its label in, below it for its label out, and on its right for the wider. */
    private static function labelled(Ftile $tile, ?TextBlock $in, ?TextBlock $out): Ftile
    {
        [$inWidth, $inHeight] = $in?->size() ?? [0.0, 0.0];
        [$outWidth, $outHeight] = $out?->size() ?? [0.0, 0.0];
        $geometry = $tile->geometry();
        $right = $geometry->width - $geometry->left;
        $missing = \max(0.0, $inWidth - $right);
        $missing += \max(0.0, $outWidth - $right - $missing);

        return FtileMarged::horizontal(FtileMarged::vertical($tile, $inHeight, $outHeight), 0.0, $missing);
    }

    /** How many swimlanes a step stands in: its own, and those of the steps it holds. */
    private function laneCount(Instruction $instruction, Ftile ...$tiles): int
    {
        $lanes = null === $this->lanes ? [] : [$this->lanes[$instruction] ?? ''];
        foreach ($tiles as $tile) {
            \array_push($lanes, ...FtileLane::all($tile));
        }

        return \count(\array_unique($lanes));
    }

    private function branch(Branch $branch): BranchTile
    {
        return new BranchTile($branch, $this->factory->decorateOut($this->list($branch->list), $branch->inlinkRendering), $this->factory->arrow);
    }

    /** @param non-empty-list<BranchTile> $thens */
    private function long(InstructionIf $if, array $thens, BranchTile $else, string $back): Ftile
    {
        if ($this->factory->vertical) {
            return $this->vertical($if, $thens, $else, $back);
        }
        $diamonds = [];
        foreach ($thens as $index => $branch) {
            $color = PlantUmlColor::parse((string) $branch->branch->color) ?? $back;
            $diamond = $this->factory->diamond('inside2', $color, $this->factory->test($branch->branch->labelTest), $if->stereogroup);
            $diamonds[] = $diamond->with(north: $this->factory->label($branch->displayPositive()) ?? new EmptyBlock(), west: $this->factory->label($branch->branch->inlabel->display));
            if ((\count($thens) - 1) === $index) {
                $diamonds[$index] = $diamonds[$index]->with(east: $this->factory->label($else->displayPositive()) ?? new EmptyBlock());
            }
        }

        return new FtileIfLong(
            $diamonds,
            \array_map(static fn (BranchTile $branch): Ftile => FtileMarged::minimum($branch->tile, 30.0), $thens),
            FtileMarged::minimum($else->tile, 30.0),
            $thens,
            \array_map($this->special(...), $thens),
            $else,
            $this->special($else),
            $this->factory->arrow,
            $this->factory->arrow->over($if->inLink()->color),
            $this->factory->arrow->over($if->outColor->color),
        );
    }

    /**
     * Else-ifs down, as PlantUML lays them out under `!pragma useVerticalIf on`: each test in the
     * arrows' font, its branch's label east of it, the branches right of room for the labels into the tests.
     *
     * @param non-empty-list<BranchTile> $thens
     */
    private function vertical(InstructionIf $if, array $thens, BranchTile $else, string $back): Ftile
    {
        $factory = $this->factory;
        $west = 10.0;
        $diamonds = [];
        $inlabels = [];
        foreach ($thens as $branch) {
            $test = $factory->label($branch->branch->labelTest) ?? new EmptyBlock();
            $diamonds[] = $factory->diamond('inside2', $back, $test, $if->stereogroup)->with(east: $factory->label($branch->displayPositive()) ?? new EmptyBlock());
            $inlabel = $factory->label($branch->branch->inlabel->display);
            $inlabels[] = $inlabel;
            $west = \max($west, $inlabel?->size()[0] ?? 0.0);
        }

        return new FtileIfLongVertical(
            $diamonds,
            \array_map(static fn (BranchTile $branch): Ftile => FtileMarged::horizontal($branch->tile, $west, 0.0), $thens),
            FtileMarged::minimum($else->tile, 30.0),
            $factory->diamond('empty', $back),
            $thens,
            $factory->arrow,
            $factory->arrow->over($if->inLink()->color),
            $factory->label($else->displayPositive()),
            $inlabels,
        );
    }

    /** The label written before the next branch, in the test's font. */
    private function special(BranchTile $branch): ?TextBlock
    {
        $display = $branch->branch->special?->display;

        return null === $display ? null : $this->factory->test($display);
    }

    private function loop(InstructionWhile $while): Ftile
    {
        $factory = $this->factory;
        $block = $this->list($while->list);
        $backward = null === $while->backward ? null : $factory->activity($while->backward, $while->backwardStereogroup);
        $style = $factory->styles->diamond();
        $back = PlantUmlColor::parse((string) $while->color) ?? $style->color('BackGroundColor', '#f1f1f1');
        $test = '' === \trim(\implode('', $while->test)) ? new EmptyBlock() : new Label($while->test, $style);
        $yes = $factory->label($while->yes);
        $out = $factory->label($while->outColor->display);
        $diamond = 'empty' === $factory->condition
            ? $factory->diamond('empty', $back)->with(north: $test, south: $yes, west: $out)
            : $factory->diamond($factory->condition, $back, $test)->with(north: $yes, west: $out);
        $tile = new FtileWhile(
            $block,
            $diamond,
            $factory->arrow,
            $factory->arrow->over($while->incoming1->color),
            $factory->label($while->incoming1->display) ?? new EmptyBlock(),
            $factory->arrow->over($while->incoming2->color),
            $factory->label($while->incoming2->display) ?? new EmptyBlock(),
            $factory->arrow->over($while->outColor->color),
            null === $while->special ? null : $this->tile($while->special),
            $backward,
        );
        $tile = $this->looseNoted($tile, $while->notes);

        return $while->killed || null !== $while->special ? new FtileKilled($tile) : $tile;
    }

    private function repeat(InstructionRepeat $repeat): Ftile
    {
        $factory = $this->factory;
        $block = $factory->decorateOut($this->list($repeat->list), $repeat->endLink);
        $style = $factory->styles->diamond();
        $color1 = PlantUmlColor::parse((string) Stereogroup::back($repeat->stereogroup)) ?? $style->color('BackGroundColor', '#f1f1f1');
        $color2 =
            PlantUmlColor::parse((string) Stereogroup::back($repeat->stereogroup2))
            ?? ([] === Stereogroup::labels($repeat->stereogroup2) ? $color1 : $factory->styles->diamond($repeat->stereogroup2)->color('BackGroundColor', '#f1f1f1'));
        $entry = null === $repeat->startLabel ? $factory->diamond('empty', $color1) : $this->entry($repeat->startLabel, $repeat->stereogroup, $color1);
        $isNull = null === $repeat->test;
        $test =
            null === $repeat->test || '' === \trim(\implode('', $repeat->test))
                ? new EmptyBlock()
                : new Label($repeat->test, 'inside' === $factory->condition ? $style : $factory->styles->arrow());
        $yes = $factory->label($repeat->yes);
        $out = $factory->label($repeat->out);
        $lane = $this->lanes[$repeat] ?? null;
        $laneOut = $repeat->laneOut ?? $lane;
        $laneBackward = $repeat->laneBackward ?? $lane;
        // As PlantUML, the label on the way back stands west of the test when the backward action's swimlane is left of the test's.
        $westward = null !== $repeat->backward && null !== $this->lanes && ($this->order[(string) $laneBackward] ?? 0) < ($this->order[(string) $laneOut] ?? 0);
        [$diamond2, $besides] = match ($factory->condition) {
            'empty' => [$factory->diamond('empty', $color2)->with(east: $test), $test],
            'square' => [$factory->diamond('square', $color2, $test)->with(south: $out, east: $yes), new EmptyBlock()],
            default => [
                $repeat->lastOfParent() && $isNull ? new FtileEmpty() : $factory->diamond('inside', $color2, $test)->with(south: $out, east: $westward ? null : $yes, west: $westward ? $yes : null),
                new EmptyBlock(),
            ],
        };
        $backward = null === $repeat->backward ? null : $this->noted($factory->activity($repeat->backward, $repeat->backwardStereogroup), $repeat->backwardNotes);
        if (null !== $this->lanes) {
            $diamond2 = new FtileLane($diamond2, (string) $laneOut);
            $backward = null === $backward ? null : new FtileLane($backward, (string) $laneBackward);
        }
        $way = match (true) {
            $lane !== $laneOut => 'across',
            FtileLane::leftOfAll($lane, FtileLane::all($block), $this->order) => 'left',
            default => 'right',
        };
        $tile = new FtileRepeat(
            $block,
            $entry,
            $diamond2,
            $besides,
            $factory->arrow->over($block->inLink()->color),
            $factory->label($block->inLink()->display),
            $factory->arrow->over($repeat->incoming1->color),
            $factory->label($repeat->incoming1->display),
            $factory->arrow->over($repeat->incoming2->color),
            $factory->label($repeat->incoming2->display),
            $factory->arrow->over($repeat->endLink->color),
            $factory->label($repeat->endLink->display),
            $backward,
            $way,
        );
        $result = $this->repeatBreaks($tile, $block->weldings(), $color2);

        return $repeat->killed ? new FtileKilled($result) : $result;
    }

    /**
     * The action a `repeat` starts at, on the diamond's background.
     *
     * @param list<string> $label
     */
    private function entry(array $label, ?string $stereogroup, string $back): Ftile
    {
        $style = $this->factory->styles->activity($stereogroup);

        return new FtileBox($label, new Style(['BackGroundColor' => $back] + $style->values), Stereogroup::boxStyle($stereogroup), new LinkRendering());
    }

    /**
     * A diamond under the loop its breaks go to: the first round the loop's left into it, the
     * others to the first's way.
     *
     * @param list<FtileBreak> $breaks
     */
    private function repeatBreaks(Ftile $tile, array $breaks, string $color): Ftile
    {
        if ([] === $breaks) {
            return $tile;
        }
        $diamond = $this->factory->diamond('empty', $color);
        $result = $this->factory->assembly(FtileMarged::horizontal($tile, 10.0, 0.0), $diamond);
        $genealogy = new Genealogy($result);
        [$dx, $dy] = $genealogy->translate($diamond);
        $middle = $dy + ($diamond->geometry()->height / 2);
        $snakes = [];
        foreach ($breaks as $index => $break) {
            [$x, $y] = $genealogy->translate($break);
            $snakes[] = 0 === $index
                ? Snake::through($this->factory->arrow, Snake::arrow('R'), [[$x, $y], [0.0, $y], [0.0, $middle], [$dx, $middle]])
                : Snake::through($this->factory->arrow, Snake::arrow('L'), [[$x, $y], [0.0, $y]]);
        }

        return new FtileDecorate($result, connections: [new Arrows($snakes)]);
    }

    private function parallel(InstructionFork $fork): Ftile
    {
        $tiles = \array_map($this->list(...), $fork->forks);
        $builder = new ParallelBuilder($this->factory, $tiles);
        $result = match ($fork->style) {
            'split' => $builder->split(),
            'merge' => $builder->merge($fork->stereogroup),
            default => $builder->fork($fork->stereogroup, $fork->label),
        };

        return $this->looseNoted($result, $fork->notes);
    }

    private function group(InstructionGroup $group): Ftile
    {
        if ('package' === $group->type) {
            throw new Unsupported('The native PlantUML renderer does not draw activity packages');
        }
        $tile = $this->list($group->list);
        if (null !== $group->note) {
            $tile = FtileWithNote::several($tile, ...$this->sides($group->note));
        }
        $style = $this->factory->styles->style(Stereogroup::stereotypes($group->stereotype), $group->type, 'composite');
        $back = PlantUmlColor::parse((string) $group->color) ?? $style->color('BackGroundColor', 'none');

        return new FtileGroup($tile, new Label($group->title, $style), $style, $back, $group->type, $this->factory->pen());
    }

    /** @return array{list<Opale>, list<Opale>} a note on its side */
    private function sides(PositionedNote $note): array
    {
        $opale = $this->factory->looseNote($note);

        return 'left' === $note->position ? [[$opale], []] : [[], [$opale]];
    }
}
