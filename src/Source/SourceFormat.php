<?php

declare(strict_types=1);

namespace Janalis\Viz\Source;

/** The three diagram languages, recognised by file extension or Markdown fence name. */
enum SourceFormat: string
{
    case Mermaid = 'mermaid';
    case Dot = 'dot';
    case PlantUml = 'plantuml';

    /** @return non-empty-list<string> */
    public function extensions(): array
    {
        return match ($this) {
            self::Mermaid => ['mmd', 'mermaid'],
            self::Dot => ['dot', 'gv'],
            self::PlantUml => ['puml', 'plantuml', 'pu', 'iuml', 'wsd'],
        };
    }

    /** @return non-empty-list<string> */
    public function fences(): array
    {
        return match ($this) {
            self::Mermaid => ['mermaid'],
            self::Dot => ['dot', 'graphviz'],
            self::PlantUml => ['plantuml', 'puml'],
        };
    }

    public static function forPath(string $path): ?self
    {
        $extension = \strtolower(\pathinfo($path, \PATHINFO_EXTENSION));

        return \array_find(self::cases(), static fn (self $format): bool => \in_array($extension, $format->extensions(), strict: true));
    }

    public static function forFence(string $name): ?self
    {
        $name = \strtolower(\trim($name));

        return \array_find(self::cases(), static fn (self $format): bool => \in_array($name, $format->fences(), strict: true));
    }
}
