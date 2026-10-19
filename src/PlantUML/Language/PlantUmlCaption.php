<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Language;

/** A title, caption, header, footer or legend: its text, and where it is set when the source says. */
final readonly class PlantUmlCaption
{
    /** @param list<string> $lines Creole text, a line each */
    public function __construct(
        public array $lines,
        public ?string $horizontal = null,
        public ?string $vertical = null,
    ) {}
}
