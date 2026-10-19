<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Language;

/** One token, its text as the language means it, where it starts, zero-based. */
final readonly class DotToken
{
    public function __construct(
        public DotTokenKind $kind,
        public string $text,
        public int $offset,
        public int $line,
        public int $column,
        /** Whether the text was a quoted string, which no keyword can be. */
        public bool $quoted = false,
    ) {}
}
