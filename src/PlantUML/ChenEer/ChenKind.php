<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\ChenEer;

/** What an element of a Chen EER diagram is, as PlantUML's `LeafType` tells them apart, and the style it takes. */
enum ChenKind: string
{
    case Entity = 'chenEntity';
    case Relationship = 'chenRelationship';
    case Attribute = 'chenAttribute';
    /** The circle a subclass link with several subclasses meets them at. */
    case Circle = 'circle';
}
