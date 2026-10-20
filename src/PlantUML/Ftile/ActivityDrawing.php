<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\PlantUML\Activities\PlantUmlActivity;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\Source\Unsupported;

/**
 * An activity diagram drawn as PlantUML draws one: its steps as tiles, their arrows merged and
 * drawn last, the whole compressed across then down, set 5 in from its top left with 15 more
 * room right and below.
 */
final class ActivityDrawing
{
    /**
     * @return \Generator<int, null, mixed, Drawing>
     * @throws Unsupported for a step not ported
     */
    public static function draw(PlantUmlActivity $activity): \Generator
    {
        $factory = new FtileFactory($activity->document);
        $root = new FtileBuilder($factory)->list($activity->root);
        yield null;
        $canvas = Canvas::create();
        $root->draw($canvas);
        $shapes = Compression::down(Compression::across($canvas->flush($factory->pen())));
        yield null;
        $limits = \array_map(static fn (Shape $shape): array => $shape->limits(), $shapes);
        $minX = [] === $limits ? 0.0 : \min(\array_column($limits, 0));
        $minY = [] === $limits ? 0.0 : \min(\array_column($limits, 1));
        $maxX = [] === $limits ? 0.0 : \max(\array_column($limits, 2));
        $maxY = [] === $limits ? 0.0 : \max(\array_column($limits, 3));
        $marks = [];
        foreach ($shapes as $shape) {
            \array_push($marks, ...$shape->moved(5 - $minX, 5 - $minY)->marks);
        }

        return PlantUmlFrame::drawing($activity->document, $marks, $maxX - $minX + 15, $maxY - $minY + 15);
    }
}
