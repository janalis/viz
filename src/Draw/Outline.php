<?php

declare(strict_types=1);

namespace Janalis\Viz\Draw;

/** How a shape is drawn, which a renderer measuring what it draws, as PlantUML's limit finder does, may take into account. */
enum Outline
{
    /** A box, as a rectangle is drawn. */
    case Box;
    /** A path, as it runs. */
    case Path;
    /** An ellipse. */
    case Ellipse;
    /** A box, its far sides taken as drawn, as PlantUML's limit finder takes a class. */
    case Block;
}
