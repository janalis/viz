<?php

declare(strict_types=1);

namespace Janalis\Viz\Source;

/** A native parser's failure to read a source, with zero-based source positions. */
interface ParseError extends \Throwable {}
