<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid;

/** Shared source bounds for native family lexers. */
final class MermaidSource
{
    /** The largest source any family reads: 10 MiB, far past any hand-written diagram. */
    public const int MAX_BYTES = 10 * 1024 * 1024;

    public const int MAX_STATEMENT = 65_536;

    /** A leading byte-order mark, as a browser drops it, blanked so no offset moves. */
    public static function unmarked(string $source): string
    {
        return \str_starts_with($source, "\u{FEFF}") ? '   '.\substr($source, offset: 3) : $source;
    }

    public static function validate(string $source): void
    {
        if (\strlen($source) > self::MAX_BYTES) {
            throw new MermaidSyntaxError('Diagram exceeds the source size limit', offset: 0, sourceLine: 0, column: 0);
        }
        if (!\mb_check_encoding($source, encoding: 'UTF-8')) {
            throw new MermaidSyntaxError('Not a UTF-8 text file', offset: 0, sourceLine: 0, column: 0);
        }
    }
}
