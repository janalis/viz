<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\State;

/** What a state is: an ordinary state or one of the pseudostates the languages draw as marks. */
enum StateKind: string
{
    case Simple = 'simple';
    case Initial = 'initial';
    case Final = 'final';
    case Choice = 'choice';
    case Fork = 'fork';
    case Join = 'join';
    case History = 'history';
    case DeepHistory = 'deep-history';

    /** Whether the state is a pseudostate, drawn as a mark rather than a box. */
    public function pseudo(): bool
    {
        return self::Simple !== $this;
    }
}
