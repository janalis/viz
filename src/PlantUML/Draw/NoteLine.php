<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
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
     * To a package: the note's edge as dot draws it, from or to the point inside the package's
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
