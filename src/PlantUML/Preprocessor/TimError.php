<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

/** What PlantUML's preprocessor stops on, and the line it stopped at. */
final class TimError extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $source = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
