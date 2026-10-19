<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Classes;

/** What a classifier is, where the languages draw a kind differently from a plain class. */
enum ClassifierKind: string
{
    case Concrete = 'class';
    case Interface = 'interface';
    case Abstract = 'abstract';
    case Enum = 'enum';
    case Annotation = 'annotation';
    case Entity = 'entity';
    case Object = 'object';
}
