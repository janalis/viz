<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Dot;

use Janalis\Viz\GraphViz\DotPipeline;
use Janalis\Viz\GraphViz\DotReader;
use Janalis\Viz\Model\Diagram;
use Janalis\Viz\PlantUML\Language\PlantUmlSource;
use Janalis\Viz\PlantUML\PlantUmlDiagram;
use Janalis\Viz\Source\Unsupported;

/**
 * A `@startdot` block, which PlantUML's `PSystemDot` hands to Graphviz as it stands: read and
 * drawn by the native DOT renderer, as Graphviz draws it, with no frame of PlantUML's.
 */
final readonly class PlantUmlDot implements PlantUmlDiagram
{
    /** As `PSystemDotFactory`'s header: `graph` or `digraph`, maybe `strict`, maybe named, then its brace and nothing more. */
    private const string HEADER = '/^\s*(?:strict\s+)?(?:di)?graph\s+(?:[_\p{L}][_\p{L}\p{N}]*|-?(?:\.[0-9]+|[0-9]+(?:\.[0-9]*)?)|"(?:[^"\\\\]|\\\\")*")?\s*\{\s*$/iu';

    private function __construct(
        public string $source,
    ) {}

    /** @throws Unsupported when PlantUML fails on the block: empty, or not opening with a graph's header */
    public static function read(PlantUmlSource $block): self
    {
        $lines = $block->lines;
        while ([] !== $lines && '' === \trim($lines[0])) {
            \array_shift($lines);
        }
        if ([] === $lines) {
            throw new Unsupported('PlantUML fails on an empty @startdot block');
        }
        if (1 !== \preg_match(self::HEADER, $lines[0])) {
            throw new Unsupported('PlantUML fails on a @startdot block not opening with a graph: '.$lines[0]);
        }

        return new self(\implode("\n", $lines)."\n");
    }

    #[\Override]
    public function model(): Diagram
    {
        return new DotReader()->read($this->source);
    }

    #[\Override]
    public function drawing(): \Generator
    {
        return yield from new DotPipeline()->drawing($this->source);
    }
}
