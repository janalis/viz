<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

/**
 * `.field` and `[index]` after a JSON variable in a line, read into it as PlantUML's
 * `VariableManager` reads them: an index has its own variables replaced, and is a number in
 * an array or a name in an object.
 */
final readonly class TimJsonPath
{
    public function __construct(
        private TimContext $context,
        private TimMemory $memory,
    ) {}

    /**
     * @param list<string> $chars
     * @return int where the path ends
     */
    public function read(JsonValue $json, array $chars, int $at, string &$result): int
    {
        if (!$json->isArray() && !$json->isObject()) {
            throw new TimError('Fatal parsing error');
        }
        $value = $json;
        for ($count = \count($chars); $at < $count;) {
            if ('.' === $chars[$at]) {
                [$value, $at] = $this->field($value, $chars, $at + 1);
                continue;
            }
            if ('[' !== $chars[$at]) {
                break;
            }
            [$value, $at] = $this->index($value, $chars, $at + 1);
        }
        if (null !== $value) {
            $result .= $value->text();
        }

        return $at;
    }

    /**
     * @param list<string> $chars
     * @return array{?JsonValue, int}
     */
    private function field(?JsonValue $value, array $chars, int $at): array
    {
        $name = '';
        while (\array_key_exists($at, $chars) && 1 === \preg_match('/^[\p{L}\p{N}_$]$/u', $chars[$at])) {
            $name .= $chars[$at++];
        }
        if (null === $value || !$value->isObject()) {
            throw new TimError('Fatal parsing error');
        }

        return [$value->get($name), $at];
    }

    /**
     * @param list<string> $chars
     * @return array{JsonValue, int}
     */
    private function index(?JsonValue $value, array $chars, int $at): array
    {
        $inner = '';
        $level = 0;
        while (true) {
            $char = $chars[$at] ?? throw new TimError('Fatal parsing error');
            if (']' === $char && 0 === $level) {
                break;
            }
            $level += match ($char) {
                '[' => 1,
                ']' => -1,
                default => 0,
            };
            $inner .= $char;
            ++$at;
        }
        $key = $this->context->substituted($this->memory, $inner) ?? '';
        $found = match (true) {
            null !== $value && $value->isArray() => 1 === \preg_match('/^[+-]?\d+$/', $key)
                ? $value->get((int) $key) ?? throw new TimError('Fatal parsing error')
                : throw new TimError('Fatal parsing error'),
            null !== $value && $value->isObject() => $value->get($key),
            default => throw new TimError('Major parsing error'),
        };

        return [$found ?? throw new TimError('Data parsing error'), $at + 1];
    }
}
