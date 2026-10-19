<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz;

/** How teoz counts a participant's activation at an event: with the changes of life the event carries, or some of them. */
enum History
{
    /** Without the activations it carries. */
    case IgnoreFutureActivate;
    /** With the activations it carries, not the deactivations. */
    case IgnoreFutureDeactivate;
    /** With the activations and the deactivations it carries. */
    case ConsiderFutureDeactivate;
}
