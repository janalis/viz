<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Flow;

use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Source\Unsupported;

/**
 * A flow diagram's lines carried out as PlantUML's `FlowDiagram` and `TilesField` carry them
 * out: the first box at the field's origin, each next one two cells from the last towards its
 * side — south unless `n`, `e` or `w` says otherwise — what stands there and east of it, and
 * below, moved two cells east to make room; each box joined to the last, and a line with only
 * a name joining the last box to that one, from the side it names.
 */
final class FlowReader
{
    /** @var list<FlowTile> */
    private array $tiles = [];
    /** @var list<array{FlowTile, string, FlowTile, string}> each path's start, its side, its end and its side */
    private array $paths = [];
    private ?FlowTile $last = null;

    /**
     * @param list<PlantUmlCommand> $commands
     * @throws Unsupported when a command is not drawn natively
     * @throws PlantUmlRefusal when PlantUML fails to carry out a command
     */
    public static function read(PlantUmlDocument $document, array $commands): PlantUmlFlow
    {
        $reader = new self();
        foreach ($commands as $command) {
            $side = \strtolower($command->get('ORIENTATION') ?? 's');
            match ($command->name) {
                'CommandLineSimple' => $reader->box($side, (string) $command->get('ID_DEST'), (string) $command->get('LABEL')),
                'CommandLink' => $reader->link($side, (string) $command->get('ID_DEST')),
                default => throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line()),
            };
        }

        return new PlantUmlFlow($document, $reader->tiles, $reader->paths);
    }

    private function box(string $side, string $id, string $label): void
    {
        $last = $this->last;
        if (null === $last) {
            $this->last = new FlowTile($id, $label, 0, 0);
            $this->tiles[] = $this->last;

            return;
        }
        [$dx, $dy] = FlowTile::step($side, 2);
        [$x, $y] = [$last->xmin + $dx, $last->ymin + $dy];
        while ($this->occupied($x, $y)) {
            $this->eastOf($x, $y);
        }
        $tile = new FlowTile($id, $label, $x, $y);
        $this->tiles[] = $tile;
        $this->path($last, $side, $tile, FlowTile::opposite($side));
        $this->last = $tile;
    }

    private function link(string $side, string $id): void
    {
        $last = $this->last ?? throw new PlantUmlRefusal('No box to link from');
        $tiles = \array_values(\array_filter($this->tiles, static fn (FlowTile $tile): bool => $tile->id === $id));
        $this->path($last, $side, $tiles[0] ?? throw new PlantUmlRefusal('No box '.$id), FlowTile::opposite($side));
    }

    /** As `TilesField`'s `buildPath`: only between sides that face each other across neighbouring boxes, or the same box's. */
    private function path(FlowTile $start, string $from, FlowTile $end, string $to): void
    {
        $level = $start->ymin === $end->ymin;
        $beside = $start->xmin === $end->xmin;
        $adjoining = match (true) {
            $start === $end => $from !== $to,
            'e' === $from => $level && ($start->xmax() + 1) === $end->xmin,
            'w' === $from => $level && $start->xmin === ($end->xmax() + 1),
            's' === $from => $beside && ($start->ymax() + 1) === $end->ymin,
            default => $beside && $start->ymin === ($end->ymax() + 1),
        };
        if (!$adjoining && !($level && 'w' === $from)) {
            throw new PlantUmlRefusal('The boxes do not face each other');
        }
        $this->paths[] = [$start, $from, $end, $to];
    }

    private function occupied(int $x, int $y): bool
    {
        foreach ($this->tiles as $tile) {
            if ($tile->xmin === $x && $tile->ymin === $y) {
                return true;
            }
        }

        return false;
    }

    /** As `moveAllToEast`: every box reaching that far east and that far down, two cells further east. */
    private function eastOf(int $x, int $y): void
    {
        foreach ($this->tiles as $tile) {
            if ($tile->xmax() < $x || $tile->ymax() < $y) {
                continue;
            }
            $tile->xmin += 2;
        }
    }
}
