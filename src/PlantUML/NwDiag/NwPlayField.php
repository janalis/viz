<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\NwDiag;

/**
 * PlantUML's `NPlayField`: the stages networks open one under the other, and the boxes of
 * columns, laid out side by side as its tetris places them.
 */
final class NwPlayField
{
    private int $stages = 0;
    /** @var list<NwBox> */
    private array $boxes = [];

    /** The last stage opened, null before any. */
    public function last(): ?int
    {
        return 0 === $this->stages ? null : $this->stages - 1;
    }

    public function newStage(): int
    {
        return $this->stages++;
    }

    public function add(NwBar $bar): void
    {
        if (null === $bar->parent) {
            $box = new NwBox();
            $box->add($bar);
            $bar->parent = $box;
            $this->boxes[] = $box;
        }
    }

    /**
     * Each group's servers moved into its own box, as PlantUML's `fixGroups` moves them.
     *
     * @param list<NwGroup> $groups
     * @param list<NwServer> $servers
     */
    public function group(array $groups, array $servers): void
    {
        foreach ($groups as $group) {
            foreach ($servers as $server) {
                if (!$group->contains($server->name) || $server->bar->parent === $group->box) {
                    continue;
                }
                $this->boxes = \array_values(\array_filter($this->boxes, static fn (NwBox $box): bool => $box !== $server->bar->parent));
                if (!\in_array($group->box, $this->boxes, strict: true)) {
                    $this->boxes[] = $group->box;
                }
                $server->bar->parent = $group->box;
                $group->box->add($server->bar);
            }
        }
    }

    /** @return array<int, int> each column's place, by its bar's object id */
    public function layout(): array
    {
        /** @var NwTetris<NwBox> $tetris */
        $tetris = new NwTetris();
        foreach ($this->boxes as $box) {
            $tetris->add($box);
        }
        $columns = [];
        foreach ($tetris->placed() as [$box, $column]) {
            foreach ($box->placed() as [$bar, $offset]) {
                $columns[\spl_object_id($bar)] = $column + $offset;
            }
        }

        return $columns;
    }
}
