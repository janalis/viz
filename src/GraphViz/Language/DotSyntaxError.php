<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Language;

use Janalis\Viz\Source\ParseError;

/** Located DOT parse failure, with zero-based source positions. */
final class DotSyntaxError extends \RuntimeException implements ParseError
{
    public function __construct(
        string $message,
        public readonly int $offset,
        public readonly int $sourceLine,
        public readonly int $column,
    ) {
        parent::__construct('Line '.($sourceLine + 1).', column '.($column + 1).': '.$message);
    }
}
