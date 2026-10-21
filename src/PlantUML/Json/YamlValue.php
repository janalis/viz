<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Json;

use Janalis\Viz\PlantUML\PlantUmlRefusal;

/**
 * A YAML value as PlantUML's `Monomorph` builds one: undetermined until a scalar, an item or
 * a key makes it a scalar, a list or a map — a key written again taking its first place.
 */
final class YamlValue
{
    private ?string $scalar = null;
    /** @var ?list<self> */
    private ?array $list = null;
    /** @var ?array<string, self> keys as strings, which a numeric one would not stay */
    private ?array $map = null;

    public static function scalar(string $value): self
    {
        $result = new self();
        $result->scalar = $value;

        return $result;
    }

    /** @param list<string> $values */
    public static function list(array $values): self
    {
        $result = new self();
        $result->list = \array_map(self::scalar(...), $values);

        return $result;
    }

    public function isList(): bool
    {
        return null !== $this->list;
    }

    /** @throws PlantUmlRefusal where PlantUML's `Monomorph` fails, on a scalar or a map */
    public function add(self $item): void
    {
        if (null !== $this->scalar || null !== $this->map) {
            throw new PlantUmlRefusal('Monomorph is not of type LIST.');
        }
        $this->list ??= [];
        $this->list[] = $item;
    }

    /** @throws PlantUmlRefusal where PlantUML's `Monomorph` fails, on a scalar or a list */
    public function put(string $key, self $value): void
    {
        if (null !== $this->scalar || null !== $this->list) {
            throw new PlantUmlRefusal('Monomorph is not of type MAP.');
        }
        $this->map ??= [];
        $this->map["\0".$key] = $value;
    }

    public function last(): ?self
    {
        return null === $this->list || [] === $this->list ? null : $this->list[\count($this->list) - 1];
    }

    /**
     * As PlantUML's `MonomorphToJson`: every scalar a string; null for an undetermined value.
     *
     * @throws PlantUmlRefusal on a list in a list, or a value left undetermined in one
     */
    public function json(): ?JsonNode
    {
        return match (true) {
            null !== $this->scalar => JsonNode::string($this->scalar),
            null !== $this->list => JsonNode::array(\array_map(static fn (self $item): JsonNode => null === $item->scalar && null === $item->map
                ? throw new PlantUmlRefusal('A YAML list holds a list')
                : $item->json() ?? JsonNode::string(''), $this->list)),
            null !== $this->map => JsonNode::object(\array_map(
                static fn (string $key, self $value): array => [\substr($key, offset: 1), $value->json() ?? throw new PlantUmlRefusal('A YAML key has no value')],
                \array_keys($this->map),
                $this->map,
            )),
            default => null,
        };
    }
}
