<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Timing;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/** A PlantUML timing diagram, read natively and drawn as PlantUML draws it. */
final readonly class PlantUmlTiming implements PlantUmlDiagram
{
    /**
     * @param array<string, TimingPlayer> $players by code, in the order declared
     * @param list<TimingMessage> $messages
     * @param list<TimingHighlight> $highlights
     * @param array<string, TimingTick> $codes the named moments, by name
     * @param string $axis `automatic`, `hidden` or `manual`
     */
    public function __construct(
        public PlantUmlDocument $document,
        public array $players,
        public array $messages,
        public array $highlights,
        public TimingRuler $ruler,
        public array $codes,
        public string $axis,
        public bool $compact,
    ) {}

    /** As the shared graph model: each player a node, each message an edge. */
    #[\Override]
    public function model(): Diagram
    {
        $nodes = [];
        foreach ($this->players as $code => $player) {
            $nodes[] = new Node(\sprintf('%s', $code), '' === $player->title ? \sprintf('%s', $code) : $player->title);
        }
        $edges = [];
        foreach ($this->messages as $message) {
            $edges[] = new Edge(
                (string) \array_search($message->from, $this->players, strict: true),
                (string) \array_search($message->to, $this->players, strict: true),
                \implode("\n", $message->label),
            );
        }
        $title = $this->document->captions['title'] ?? null;

        return new Graph($nodes, $edges, title: null === $title ? '' : \implode("\n", $title->lines));
    }

    #[\Override]
    public function drawing(): \Generator
    {
        yield from [];

        return TimingDrawing::draw($this);
    }
}
