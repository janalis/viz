<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Packet;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;
use Janalis\Viz\PlantUML\PlantUmlFamily;

/** PlantUML's packet diagrams, drawn natively. */
final class PacketFamily implements PlantUmlFamily
{
    #[\Override]
    public function read(PlantUmlDocument $document, array $commands): PlantUmlDiagram
    {
        return PacketReader::read($document, $commands);
    }
}
