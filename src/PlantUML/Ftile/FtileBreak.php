<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/** `break`: the flow stops here, to leave its loop by an arrow the loop draws. */
final class FtileBreak extends FtileEmpty
{
    #[\Override]
    protected function measure(): Geometry
    {
        return parent::measure()->withoutOut();
    }

    #[\Override]
    public function weldings(): array
    {
        return [$this];
    }
}
