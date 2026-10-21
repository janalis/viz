<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Board;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Classes\ClassBox;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Draw\Shadow;

/**
 * A board drawn as PlantUML's `BoardDiagram` draws it: each activity's cards in a grid of
 * 170 by 90 — its column across, its stage down — the activities side by side, each card a
 * grey post-it 150 by 70, its text 3 in, shadowed; dashed lines across the board between the
 * stages, 10 above each; 10 more room past what it draws.
 */
final class BoardDrawing
{
    private const float COLUMN = 170.0;
    private const float STAGE = 90.0;
    private const float CARD_WIDTH = 150.0;
    private const float CARD_HEIGHT = 70.0;

    public static function draw(PlantUmlBoard $diagram): Drawing
    {
        $marks = [];
        $left = 0.0;
        $stages = 0;
        foreach ($diagram->activities as $activity) {
            $columns = $activity->place(0) + 1;
            // As PlantUML, the activity's card once on its own, then again among its cards.
            \array_push($marks, ...self::card($activity->text, $left, 0.0));
            foreach ($activity->all() as $card) {
                \array_push($marks, ...self::card($card->text, $left + ($card->column * self::COLUMN), $card->stage * self::STAGE));
                $stages = \max($stages, $card->stage);
            }
            $left += $columns * self::COLUMN;
        }
        for ($stage = 1; $stage <= $stages; ++$stage) {
            $y = ($stage * self::STAGE) - 10;
            $marks[] = new Mark(MarkKind::Polyline, [[0.0, $y], [$left, $y]], stroke: '#000000', dashed: true, strokeWidth: 0.5, dashes: [5.0, 5.0]);
        }
        [$width, $height] = [0.0, 0.0];
        foreach ($marks as $mark) {
            foreach ($mark->points as [$x, $y]) {
                [$width, $height] = [\max($width, $x), \max($height, $y)];
            }
        }

        return PlantUmlFrame::drawing($diagram->document, $marks, $width + 10, $height + 10);
    }

    /** @return list<Mark> PlantUML's `CardBox`, its top left at a point */
    private static function card(string $text, float $x, float $y): array
    {
        $box = ClassBox::rectangle($x, $y, self::CARD_WIDTH, self::CARD_HEIGHT, '#000000', '#c0c0c0', 1.0, 0.0);
        $label = new PlantUmlText([$text], 14.0);

        return [...Shadow::cast([$box], 1.0), ...$label->marks($x + 3, $y + 3, $label->size()[0], 'left')];
    }
}
