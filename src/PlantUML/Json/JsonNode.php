<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Json;

/**
 * A value of a JSON or YAML diagram, as PlantUML's `JsonValue` keeps one: an object's members
 * in the order written, a name written twice kept twice; an array's items, each under its
 * index; a string's text, or a number's as written.
 */
final readonly class JsonNode
{
    /** @param list<array{string, JsonNode}> $members an object's, or an array's under their indexes */
    public function __construct(
        public JsonKind $kind,
        public array $members = [],
        public string $text = '',
    ) {}

    public static function string(string $text): self
    {
        return new self(JsonKind::String, text: $text);
    }

    /** @param list<JsonNode> $items */
    public static function array(array $items): self
    {
        return new self(JsonKind::Array, \array_map(static fn (int $index, self $item): array => [(string) $index, $item], \array_keys($items), $items));
    }

    /** @param list<array{string, JsonNode}> $members */
    public static function object(array $members): self
    {
        return new self(JsonKind::Object, $members);
    }

    public function container(): bool
    {
        return JsonKind::Object === $this->kind || JsonKind::Array === $this->kind;
    }

    /** How PlantUML's `TextBlockJson` writes it in its parent's table: a container as three spaces. */
    public function short(): string
    {
        return match ($this->kind) {
            JsonKind::String, JsonKind::Number => $this->text,
            JsonKind::Null => "\u{2400}",
            JsonKind::True => "\u{2611} true",
            JsonKind::False => "\u{2610} false",
            default => '   ',
        };
    }
}
