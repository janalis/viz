<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid;

use Janalis\Viz\Render\Work;

/** Which Mermaid family a source declares, by its header as the pinned detectors read it. */
final class MermaidKinds
{
    /**
     * The family of a whole source, after its front matter and directives.
     *
     * @throws MermaidSyntaxError when the front matter or a directive does not parse
     * @throws \InvalidArgumentException when the configuration cannot be applied
     */
    public static function detect(string $source): string
    {
        $frontMatter = MermaidFrontMatter::parse(MermaidSource::unmarked($source));
        $initialization = Work::run(MermaidInitialization::parse($frontMatter->source, $frontMatter->configuration));

        return self::of(self::header($initialization->source));
    }

    /** The header line of a source already stripped of its front matter and directives. */
    public static function header(string $source): string
    {
        return match (true) {
            EventModelingLexer::detect($source) => 'eventmodeling',
            null !== RailroadLexer::header($source) => RailroadLexer::header($source) ?? '',
            default => new MermaidLexer($source, textual: true)->statements()->current()->text ?? '',
        };
    }

    public static function of(string $header): string
    {
        return match (true) {
            'sequencediagram' === \strtolower($header) => 'sequence',
            1 === \preg_match('/^pie\b/i', $header) => 'pie',
            1 === \preg_match('/^journey\b/i', $header) => 'journey',
            'mindmap' === \strtolower($header) => 'mindmap',
            1 === \preg_match('/^timeline\b/i', $header) => 'timeline',
            'quadrantchart' === \strtolower($header) => 'quadrant',
            1 === \preg_match('/^xychart(?:-beta)?(?:\s|$)/i', $header) => 'xy',
            1 === \preg_match('/^packet(?:-beta)?$/i', $header) => 'packet',
            'kanban' === \strtolower($header) => 'kanban',
            1 === \preg_match('/^sankey(?:-beta)?$/i', $header) => 'sankey',
            1 === \preg_match('/^treemap(?:-beta)?$/i', $header) => 'treemap',
            'treeview-beta' === \strtolower($header) => 'treeView',
            1 === \preg_match('/^ishikawa(?:-beta)?$/i', $header) => 'ishikawa',
            1 === \preg_match('/^radar-beta\s*:?(?:\s|$)/i', $header) => 'radar',
            1 === \preg_match('/^cynefin-beta:?(?:\s|$)/i', $header) => 'cynefin',
            'requirementdiagram' === \strtolower($header) => 'requirement',
            1 === \preg_match('/^info(?:\s|$)/', $header) => 'info',
            'erdiagram' === \strtolower($header) => 'er',
            \in_array($header, ['classDiagram', 'classDiagram-v2'], strict: true) => 'class',
            1 === \preg_match('/^railroad(?:-(?:ebnf|abnf|peg))?-beta(?:\s|$)/', $header) => 'railroad',
            1 === \preg_match('/^venn-beta\b/i', $header) => 'venn',
            1 === \preg_match('/^eventmodeling(?:\s|$)/', $header) => 'eventmodeling',
            1 === \preg_match('/^architecture-beta(?:\s|$)/', $header) => 'architecture',
            1 === \preg_match('/^usecase-beta(?:\s|$)/', $header) => 'usecase',
            'gantt' === \strtolower($header) => 'gantt',
            1 === \preg_match('/^agentflow-beta(?:\s|$)/', $header) => 'agentflow',
            1 === \preg_match('/^swimlane-beta(?:\s|$)/', $header) => 'swimlane',
            1 === \preg_match('/^block(?:-beta)?(?:\s|$)/', $header) => 'block',
            1 === \preg_match('/^C4(?:Context|Container|Component|Dynamic|Deployment)$/', $header) => 'c4',
            'wardley-beta' === $header => 'wardley',
            1 === \preg_match('/^gitGraph(?:\s|:|$)/', $header) => 'git',
            1 === \preg_match('/^stateDiagram(?:-v2)?$/i', $header) => 'state',
            default => 'flowchart',
        };
    }
}
