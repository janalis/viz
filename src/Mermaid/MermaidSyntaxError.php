<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid;

use Janalis\Viz\Source\ParseError;

/** Located native-parser failure, with zero-based source positions. */
final class MermaidSyntaxError extends \RuntimeException implements ParseError
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
