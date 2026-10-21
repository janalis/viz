<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities;

use Janalis\Viz\Model\Activity\ActivityDiagram;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionList;
use Janalis\Viz\PlantUML\Ftile\ActivityDrawing;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;
use Janalis\Viz\Source\Unsupported;

/** A PlantUML activity diagram read natively: its steps as PlantUML holds them. */
final readonly class PlantUmlActivity implements PlantUmlDiagram
{
    public function __construct(
        public PlantUmlDocument $document,
        public InstructionList $root,
        /** The arrow written after the last step, which a `repeat` without a test takes back. */
        public LinkRendering $next = new LinkRendering(),
        /** @var list<Lane> the swimlanes, in the order declared */
        public array $lanes = [],
        /** @var ?\WeakMap<\Janalis\Viz\PlantUML\Activities\Instruction\Instruction, string> the swimlane each step was written in */
        public ?\WeakMap $laneOf = null,
    ) {}

    #[\Override]
    public function model(): ActivityDiagram
    {
        return ActivityModel::of($this);
    }

    /** @throws Unsupported for a step PlantUML draws in ways not ported */
    #[\Override]
    public function drawing(): \Generator
    {
        return yield from ActivityDrawing::draw($this);
    }
}
