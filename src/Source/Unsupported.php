<?php

declare(strict_types=1);

namespace Janalis\Viz\Source;

/** A source in a language a native renderer reads, using something it does not draw: the command-line tool should. */
final class Unsupported extends \RuntimeException {}
