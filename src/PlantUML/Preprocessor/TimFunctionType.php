<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

enum TimFunctionType
{
    case Procedure;
    case ReturnFunction;
    case LegacyDefine;
    case LegacyDefinelong;

    public function isLegacy(): bool
    {
        return self::LegacyDefine === $this || self::LegacyDefinelong === $this;
    }
}
