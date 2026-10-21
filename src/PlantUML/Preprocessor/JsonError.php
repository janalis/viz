<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

/** Text that is not JSON, and where it stops being JSON. */
final class JsonError extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $offset,
    ) {
        parent::__construct($message);
    }
}
