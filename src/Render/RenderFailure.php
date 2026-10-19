<?php

declare(strict_types=1);

namespace Janalis\Viz\Render;

/** A render that produced no image: invalid source, a missing tool, or a tool that failed. */
final class RenderFailure extends \RuntimeException
{
    /** @param list<self> $attempts */
    public static function exhausted(Format $format, array $attempts): self
    {
        if ([] === $attempts) {
            return new self(\sprintf('No renderer can produce %s', $format->name));
        }

        return new self(\implode("\n", \array_map(static fn (self $failure): string => $failure->getMessage(), $attempts)), previous: $attempts[0]);
    }
}
