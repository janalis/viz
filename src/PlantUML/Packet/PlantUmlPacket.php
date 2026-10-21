<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Packet;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/** A PlantUML packet diagram, read natively and drawn as PlantUML draws it. */
final readonly class PlantUmlPacket implements PlantUmlDiagram
{
    /** @param list<array{int, int, int, string}> $fields each field's first and last bit, height and text */
    public function __construct(
        public PlantUmlDocument $document,
        public array $fields,
        public int $columns = 16,
        public float $bitHeight = 32.0,
        /** How often the scale is numbered; null for every half row. */
        public ?int $interval = null,
        public bool $rightToLeft = false,
        public bool $sameHeight = false,
    ) {}

    /** As the shared graph model: each field a box of its bits and its text, in order. */
    #[\Override]
    public function model(): Diagram
    {
        $nodes = \array_map(
            static fn (int $index, array $field): Node => new Node('n'.$index, ($field[0] === $field[1] ? $field[0] : $field[0].'-'.$field[1]).': '.$field[3]),
            \array_keys($this->fields),
            $this->fields,
        );
        $title = $this->document->captions['title'] ?? null;

        return new Graph($nodes, title: null === $title ? '' : \implode("\n", $title->lines));
    }

    #[\Override]
    public function drawing(): \Generator
    {
        yield from [];

        return PacketDrawing::draw($this);
    }
}
