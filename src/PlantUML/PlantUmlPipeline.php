<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML;

use Janalis\Viz\Draw\Pipeline;
use Janalis\Viz\PlantUML\Activities\ActivityFamily;
use Janalis\Viz\PlantUML\Board\BoardFamily;
use Janalis\Viz\PlantUML\Chart\ChartFamily;
use Janalis\Viz\PlantUML\ChenEer\ChenFamily;
use Janalis\Viz\PlantUML\Classes\ClassFamily;
use Janalis\Viz\PlantUML\Creole\CreoleReader;
use Janalis\Viz\PlantUML\Descriptions\DescriptionFamily;
use Janalis\Viz\PlantUML\Ebnf\EbnfFamily;
use Janalis\Viz\PlantUML\Ebnf\RegexFamily;
use Janalis\Viz\PlantUML\Files\FilesReader;
use Janalis\Viz\PlantUML\Flow\FlowFamily;
use Janalis\Viz\PlantUML\Gantt\GanttReader;
use Janalis\Viz\PlantUML\Git\GitReader;
use Janalis\Viz\PlantUML\Json\JsonReader;
use Janalis\Viz\PlantUML\Language\PlantUmlCommands;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlSource;
use Janalis\Viz\PlantUML\LegacyActivities\LegacyActivityFamily;
use Janalis\Viz\PlantUML\MindMaps\MindMapFamily;
use Janalis\Viz\PlantUML\NwDiag\NwFamily;
use Janalis\Viz\PlantUML\Packet\PacketFamily;
use Janalis\Viz\PlantUML\Salt\SaltFamily;
use Janalis\Viz\PlantUML\Sequences\SequenceFamily;
use Janalis\Viz\PlantUML\States\StateFamily;
use Janalis\Viz\PlantUML\Timing\TimingFamily;
use Janalis\Viz\PlantUML\Wbs\WbsFamily;
use Janalis\Viz\PlantUML\Wire\WireFamily;
use Janalis\Viz\Source\Unsupported;

/**
 * PlantUML drawn natively: the block read as PlantUML reads it before any diagram does,
 * then PlantUML's diagram factories tried in its order, by their commands, the first whose
 * commands take every line choosing the diagram. A native family draws it; a factory without
 * one leaves it to PlantUML, as does a kind of diagram no factory here reads.
 */
final readonly class PlantUmlPipeline implements Pipeline
{
    /** PlantUML's factories, in the order it tries them, by the name of their commands, and what their diagrams are called. */
    public const array FACTORIES = [
        'sequence' => 'sequence',
        'class' => 'class',
        'activity' => 'legacy activity',
        'description' => 'description',
        'state' => 'state',
        'activity3' => 'activity',
        'timing' => 'timing',
    ];

    /** The factories for each kind of block, as `@start…` names it, the uml ones above. */
    public const array KINDS = [
        'uml' => self::FACTORIES,
        'mindmap' => ['mindMap' => 'mind map'],
        'wbs' => ['wBS' => 'work breakdown structure'],
        'chen' => ['chenEer' => 'Chen EER'],
        'ebnf' => ['ebnf' => 'EBNF'],
        'regex' => ['regex' => 'regex'],
        'board' => ['board' => 'board'],
        'packetdiag' => ['packet' => 'packet'],
        'nwdiag' => ['nw' => 'network'],
        'wire' => ['wire' => 'wire'],
        'salt' => ['salt' => 'salt'],
        'flow' => ['flow' => 'flow'],
        'chart' => ['chart' => 'chart'],
    ];

    /** @param array<string, PlantUmlFamily> $families the native ones, by factory */
    public function __construct(
        private array $families = [],
    ) {}

    /** The pipeline with every family drawn natively. */
    public static function native(): self
    {
        return new self([
            'sequence' => new SequenceFamily(),
            'class' => new ClassFamily(),
            'activity' => new LegacyActivityFamily(),
            'description' => new DescriptionFamily(),
            'state' => new StateFamily(),
            'activity3' => new ActivityFamily(),
            'timing' => new TimingFamily(),
            'mindMap' => new MindMapFamily(),
            'wBS' => new WbsFamily(),
            'chenEer' => new ChenFamily(),
            'ebnf' => new EbnfFamily(),
            'regex' => new RegexFamily(),
            'board' => new BoardFamily(),
            'packet' => new PacketFamily(),
            'nw' => new NwFamily(),
            'wire' => new WireFamily(),
            'salt' => new SaltFamily(),
            'flow' => new FlowFamily(),
            'chart' => new ChartFamily(),
        ]);
    }

    #[\Override]
    public function drawing(string $source): \Generator
    {
        $drawing = $this->diagram($source)->drawing();
        yield from $drawing;

        return $drawing->getReturn();
    }

    /** @throws Unsupported when no native family reads the source */
    public function diagram(string $source): PlantUmlDiagram
    {
        $parsed = PlantUmlSource::parse($source);
        // Data is read whole, by no commands, as PlantUML's JSON and YAML factories read it.
        if (\array_key_exists($parsed->kind, JsonReader::KINDS)) {
            return JsonReader::read($parsed);
        }
        // A git log is read line by line as it stands, its graph's signs and all.
        if ('git' === $parsed->kind) {
            return GitReader::read($parsed);
        }
        // A Creole diagram is all text: every line, `title` and `skinparam` among them, is Creole.
        if ('creole' === $parsed->kind) {
            return CreoleReader::read($parsed);
        }
        // A files diagram takes the lines that start with `/` or `<note>`, and leaves the rest.
        if ('files' === $parsed->kind) {
            return FilesReader::read($parsed);
        }
        $document = PlantUmlDocument::read($parsed);
        // A Gantt chart's sentences are read by its subjects and verbs, not matched as commands.
        if ('gantt' === $document->kind) {
            try {
                return GanttReader::read($document);
            } catch (PlantUmlRefusal $refused) {
                throw new Unsupported($refused->getMessage(), previous: $refused);
            }
        }
        $refusal = null;
        foreach (self::KINDS[$document->kind] ?? [] as $factory => $name) {
            $commands = PlantUmlCommands::family($factory)->match($document->lines);
            if (null === $commands) {
                continue;
            }
            $family = $this->families[$factory] ?? null;
            if (null === $family) {
                throw new Unsupported('The native PlantUML renderer does not draw '.$name.' diagrams');
            }

            try {
                return $family->read($document, $commands);
            } catch (PlantUmlRefusal $refused) {
                // As PlantUML, which tells of the failure furthest down the source: timing, tried last,
                // mostly fails at once on a diagram written for another factory.
                $refusal = 'timing' === $factory && null !== $refusal ? $refusal : $refused;
                continue;
            }
        }

        // Only a `@startuml` block can hold the kinds PlantUML's later factories read.
        $claimed = 'uml' === $document->kind ? PlantUmlKinds::claimed($document) : null;

        throw new Unsupported(match (true) {
            null !== $claimed => 'The native PlantUML renderer does not draw '.$claimed,
            null !== $refusal => $refusal->getMessage(),
            default => 'No PlantUML diagram reads this source',
        }, previous: $refusal);
    }
}
