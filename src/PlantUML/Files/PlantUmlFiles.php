<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Files;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/** A PlantUML files diagram, read natively and drawn as PlantUML draws it. */
final readonly class PlantUmlFiles implements PlantUmlDiagram
{
    public function __construct(
        public PlantUmlDocument $document,
        public FilesEntry $root,
    ) {}

    /** As the shared graph model: each folder and file a node by its path, joined to its folder by a line without a head; notes left out. */
    #[\Override]
    public function model(): Diagram
    {
        $nodes = [];
        $edges = [];
        $walk = static function (FilesEntry $folder, string $path) use (&$walk, &$nodes, &$edges): void {
            foreach ($folder->children as $child) {
                if (FilesType::Note === $child->type) {
                    continue;
                }
                $id = $path.'/'.$child->name;
                $nodes[] = new Node($id, $child->name);
                if ('' !== $path) {
                    $edges[] = new Edge($path, $id, head: ArrowHead::None);
                }
                $walk($child, $id);
            }
        };
        $walk($this->root, path: '');

        return new Graph($nodes, $edges);
    }

    #[\Override]
    public function drawing(): \Generator
    {
        yield from [];

        return FilesDrawing::draw($this);
    }
}
