<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

/**
 * A JSON value as PlantUML's minimal-json keeps it: a number as its literal, an object's
 * members in order — a name may come back, and the last one with a name is the one read —
 * and written back without a space, escaping only what minimal-json escapes.
 */
final readonly class JsonValue
{
    /**
     * @param string|list<JsonValue>|list<array{string, JsonValue}> $value a string's text, a number's or a literal's spelling, an array's items, an object's members
     */
    private function __construct(
        public JsonKind $kind,
        public string|array $value,
    ) {}

    public static function string(string $text): self
    {
        return new self(JsonKind::String, $text);
    }

    public static function number(string $literal): self
    {
        return new self(JsonKind::Number, $literal);
    }

    /** @param 'true'|'false'|'null' $literal */
    public static function literal(string $literal): self
    {
        return new self(JsonKind::Literal, $literal);
    }

    /** @param list<JsonValue> $items */
    public static function array(array $items): self
    {
        return new self(JsonKind::Array, $items);
    }

    /** @param list<array{string, JsonValue}> $members */
    public static function object(array $members): self
    {
        return new self(JsonKind::Object, $members);
    }

    public function isString(): bool
    {
        return JsonKind::String === $this->kind;
    }

    public function isNumber(): bool
    {
        return JsonKind::Number === $this->kind;
    }

    public function isBoolean(): bool
    {
        return JsonKind::Literal === $this->kind && 'null' !== $this->value;
    }

    public function isArray(): bool
    {
        return JsonKind::Array === $this->kind;
    }

    public function isObject(): bool
    {
        return JsonKind::Object === $this->kind;
    }

    /** @return list<JsonValue> an array's items, none for anything else */
    public function items(): array
    {
        /** @var list<JsonValue> */
        return JsonKind::Array === $this->kind ? $this->value : [];
    }

    /** @return list<array{string, JsonValue}> an object's members, none for anything else */
    public function members(): array
    {
        /** @var list<array{string, JsonValue}> */
        return JsonKind::Object === $this->kind ? $this->value : [];
    }

    /** @return list<string> an object's names, in order, as often as written */
    public function names(): array
    {
        return \array_map(static fn (array $member): string => $member[0], $this->members());
    }

    /** The value of an object's last member of a name, or an array's item at an index. */
    public function get(string|int $key): ?self
    {
        if (\is_int($key)) {
            return $this->items()[$key] ?? null;
        }
        $found = null;
        foreach ($this->members() as [$name, $value]) {
            if ($name !== $key) {
                continue;
            }

            $found = $value;
        }

        return $found;
    }

    public function size(): int
    {
        return \count(JsonKind::Array === $this->kind ? $this->items() : $this->members());
    }

    /** An array with an item more, or an object with a member more. */
    public function with(?string $name, self $value): self
    {
        if (null === $name) {
            return self::array([...$this->items(), $value]);
        }

        return self::object([...$this->members(), [$name, $value]]);
    }

    /** An object with a name's last member set, or the member added; an array with an item replaced. */
    public function set(string|int $key, self $value): self
    {
        if (\is_int($key)) {
            $items = $this->items();
            $items[$key] = $value;

            return self::array($items);
        }
        $members = $this->members();
        $index = null;
        foreach ($members as $at => [$name]) {
            if ($name !== $key) {
                continue;
            }

            $index = $at;
        }
        if (null === $index) {
            return self::object([...$members, [$key, $value]]);
        }
        $members[$index] = [$key, $value];

        return self::object($members);
    }

    /** An object without a name's last member, or an array without an item. */
    public function without(string|int $key): self
    {
        if (\is_int($key)) {
            $items = $this->items();
            unset($items[$key]);

            return self::array(\array_values($items));
        }
        $members = $this->members();
        $index = null;
        foreach ($members as $at => [$name]) {
            if ($name !== $key) {
                continue;
            }

            $index = $at;
        }
        if (null !== $index) {
            unset($members[$index]);
        }

        return self::object(\array_values($members));
    }

    /** As minimal-json merges: each member of the other set here, or added. */
    public function merged(self $other): self
    {
        $result = $this;
        foreach ($other->members() as [$name, $value]) {
            $result = $result->set($name, $value);
        }

        return $result;
    }

    /** As minimal-json merges deeply: an object member merged into the object of its name here. */
    public function deepMerged(self $other): self
    {
        $result = $this;
        foreach ($other->members() as [$name, $value]) {
            $mine = $result->get($name);
            $result = $result->set($name, null !== $mine && $mine->isObject() && $value->isObject() ? $mine->deepMerged($value) : $value);
        }

        return $result;
    }

    /** The value written as minimal-json writes it. */
    public function write(): string
    {
        return match ($this->kind) {
            JsonKind::String => '"'.self::escaped($this->scalar()).'"',
            JsonKind::Number, JsonKind::Literal => $this->scalar(),
            JsonKind::Array => '['.\implode(',', \array_map(static fn (self $item): string => $item->write(), $this->items())).']',
            JsonKind::Object => '{'.\implode(',', \array_map(static fn (array $member): string => '"'.self::escaped($member[0]).'":'.$member[1]->write(), $this->members())).'}',
        };
    }

    /** The text PlantUML shows: a string's own text, anything else written. */
    public function text(): string
    {
        return JsonKind::String === $this->kind ? $this->scalar() : $this->write();
    }

    /** A string's text, a number's or a literal's spelling. */
    private function scalar(): string
    {
        return \is_string($this->value) ? $this->value : '';
    }

    private static function escaped(string $text): string
    {
        return (string) \preg_replace_callback(
            '/[\\\\"\x00-\x1f\x{2028}\x{2029}]/u',
            static fn (array $match): string => match ($match[0]) {
                '\\' => '\\\\',
                '"' => '\\"',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                default => \sprintf('\\u%04x', \mb_ord($match[0])),
            },
            $text,
        );
    }
}
