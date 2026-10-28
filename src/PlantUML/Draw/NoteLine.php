<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Route;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Links\LinkDecor;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\Style\Style;

/** The dashed line from a note to what it stands beside, as PlantUML draws it. */
final class NoteLine
{
    /** Straight from the note's middle to its entity's, cut at both outlines. */
    public static function straight(Placement $placement, string $note, string $entity): Mark
    {
        [$from, $to] = [$placement->endpoints[$note], $placement->endpoints[$entity]];

        return new Mark(MarkKind::Polyline, BoxClip::clipped([[$from->x, $from->y], [$to->x, $to->y]], $from, $to), stroke: '#181818', dashed: true, strokeWidth: 0.5, dashes: [7.0, 7.0]);
    }

    /**
     * The notes svek draws pointing at what they stand beside, as its opale draws a note with a
     * single link, to an entity laid out as a node, that dot routes in one curve — to the next
     * rank, or across its own with nothing between: where that link leaves the note, where it
     * ends, and its edge, not drawn.
     *
     * @param list<PlantUmlNote> $notes
     * @param array<string, mixed> $laid the notes laid out, by name
     * @param array<string, int> $noteEdges the edge of each note beside what it stands by, by note
     * @param list<PlantUmlLink> $links
     * @param array<int, int> $edges each drawn link's edge, by link
     * @param array<string, mixed> $targets what a note may point at, by name
     * @return array<string, array{array{float, float}, array{float, float}, int}> by note
     */
    public static function pointers(Placement $placement, array $notes, array $laid, array $noteEdges, array $links, array $edges, array $targets): array
    {
        $ends = [];
        foreach (\array_filter($notes, static fn (PlantUmlNote $note): bool => \array_key_exists($note->id, $noteEdges)) as $note) {
            $ends[$note->id][] = [$noteEdges[$note->id], (string) $note->entity];
        }
        foreach ($edges as $index => $edge) {
            $ends[$links[$index]->from][] = [$edge, $links[$index]->to];
            $ends[$links[$index]->to][] = [$edge, $links[$index]->from];
        }
        $pointers = [];
        foreach (\array_keys($laid) as $id) {
            [[$edge, $other]] = 1 === \count($ends[$id] ?? []) ? $ends[$id] : [[-1, '']];
            if (!\array_key_exists($other, $targets) || \count($placement->routes[$edge] ?? []) > 2) {
                continue;
            }
            [$note, $to] = [$placement->endpoints[$id], $placement->endpoints[$other]];
            $route = $placement->routes[$edge] ?? [[$note->x, $note->y], [$to->x, $to->y]];
            // Its route from the note, whichever way the edge runs.
            $from = \hypot($route[0][0] - $note->x, $route[0][1] - $note->y) <= \hypot($route[0][0] - $to->x, $route[0][1] - $to->y);
            $route = BoxClip::clipped(Route::smooth($from ? $route : \array_reverse($route)), $note, $to);
            $pointers[$id] = [$route[0], $route[\count($route) - 1], $edge];
        }

        return $pointers;
    }

    /**
     * To what it stands beside: the note's edge as dot draws it, from or to the point inside the package's
     * cluster, cut at its frame.
     *
     * @return list<Mark>
     */
    public static function routed(PlantUmlNote $note, Placement $placement, int $edge, Style $arrow, Style $noteStyle): array
    {
        $before = \in_array($note->position, ['left', 'top'], strict: true);
        $line = new PlantUmlLink($before ? $note->id : (string) $note->entity, $before ? (string) $note->entity : $note->id, LinkDecor::None, LinkDecor::None, length: 1);
        $line->dashed = true;

        return LinkPainter::marks($line, $placement, $edge, LinkLabel::of([], $arrow, [], $noteStyle), $arrow, '#ffffff');
    }
}
