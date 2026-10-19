<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid\Source;

use Janalis\Viz\Mermaid\MermaidConfiguration;
use Janalis\Viz\Mermaid\MermaidFrontMatter;
use Janalis\Viz\Mermaid\MermaidInitialization;
use Janalis\Viz\Mermaid\MermaidSource;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Render\Work;

/** A Mermaid source past its front matter and directives: the diagram text, its configuration and its title. */
final readonly class MermaidPrepared
{
    private function __construct(
        public string $source,
        public MermaidConfiguration $configuration,
        public string $title,
    ) {}

    /**
     * @throws MermaidSyntaxError when the front matter or a directive does not parse
     * @throws \InvalidArgumentException when the configuration cannot be applied
     */
    public static function of(string $source): self
    {
        $frontMatter = MermaidFrontMatter::parse(MermaidSource::unmarked($source));
        $initialization = Work::run(MermaidInitialization::parse($frontMatter->source, $frontMatter->configuration));

        return new self($initialization->source, $initialization->configuration, $frontMatter->title->text ?? '');
    }
}
