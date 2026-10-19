<?php

declare(strict_types=1);

namespace Janalis\Viz\Model;

/** The kinds of diagram the languages share, each with its own model. */
enum Family: string
{
    case Graph = 'graph';
    case Sequence = 'sequence';
    case Classes = 'class';
    case State = 'state';
    case Activity = 'activity';
    case Entity = 'entity';
    case UseCase = 'usecase';
}
