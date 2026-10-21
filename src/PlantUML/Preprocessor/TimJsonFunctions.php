<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

/** The JSON functions of the preprocessor, as PlantUML writes them: each on a copy, giving it back changed. */
final class TimJsonFunctions
{
    /** @return list<string> an object's names, or those of the objects in an array */
    public static function keys(JsonValue $json): array
    {
        if ($json->isObject()) {
            return $json->names();
        }
        if (!$json->isArray()) {
            throw new TimError('Bad JSON type');
        }

        return \array_merge(...\array_map(static fn (JsonValue $item): array => $item->names(), $json->items()));
    }

    public static function type(TimValue $value): string
    {
        $json = $value->json();

        return match (true) {
            $value->isString() || (null !== $json && $json->isString()) => 'string',
            $value->isNumber() || (null !== $json && $json->isNumber()) => 'number',
            null === $json => 'not_json',
            $json->isArray() => 'array',
            $json->isObject() => 'object',
            $json->isBoolean() => 'boolean',
            default => 'json',
        };
    }

    public static function keyExists(TimValue $data, TimValue $key): bool
    {
        $json = $data->json();
        if (null === $json || !$json->isObject() || (!$key->isString() && !($key->json()?->isString() ?? false))) {
            return false;
        }

        return \in_array($key->text(), $json->names(), strict: true);
    }

    public static function add(JsonValue $json, TimValue $data, TimValue $first, ?TimValue $second): TimValue
    {
        return match (true) {
            $json->isArray() => TimValue::ofJson($json->with(null, $first->asJson())),
            $json->isObject() => TimValue::ofJson($json->with($first->text(), self::required($second)->asJson())),
            default => $data,
        };
    }

    public static function merge(TimValue $data, JsonValue $first, JsonValue $second): TimValue
    {
        $containers = static fn (JsonValue $json): bool => $json->isArray() || $json->isObject();
        if ((!$containers($first) && !$containers($second)) || ($first->isArray() && $second->isObject()) || ($first->isObject() && $second->isArray())) {
            return $data;
        }
        if ($first->isArray() && $second->isArray()) {
            return TimValue::ofJson(JsonValue::array([...$first->items(), ...$second->items()]));
        }
        if ($first->isObject() && $second->isObject()) {
            return TimValue::ofJson($first->merged($second));
        }
        throw new TimError('Bad JSON type');
    }

    public static function remove(JsonValue $json, TimValue $data, TimValue $key): TimValue
    {
        if ($json->isObject()) {
            return TimValue::ofJson($json->without($key->text()));
        }
        if (!$json->isArray()) {
            return $data;
        }
        $index = $key->toInt();
        if (!$key->isNumber() || $index < 0 || $index >= $json->size()) {
            return TimValue::ofJson($json);
        }

        return TimValue::ofJson($json->without($index));
    }

    /** `%json_set(object, json)` merges deeply; with a key, sets an object's member or an array's item. */
    public static function set(JsonValue $json, TimValue $data, TimValue $key, ?TimValue $value): TimValue
    {
        if (!$json->isArray() && !$json->isObject()) {
            return $data;
        }
        if (null === $value && $json->isObject()) {
            $merged = $key->asJson();

            return TimValue::ofJson($merged->isObject() ? $json->deepMerged($merged) : $json);
        }
        if ($json->isObject()) {
            return TimValue::ofJson($json->set($key->text(), self::required($value)->asJson()));
        }
        if (!$key->isNumber()) {
            return TimValue::ofJson($json);
        }
        $index = $key->toInt();
        $item = self::required($value)->asJson();

        return TimValue::ofJson($index >= 0 && $index < $json->size() ? $json->set($index, $item) : $json);
    }

    private static function required(?TimValue $value): TimValue
    {
        return $value ?? throw new TimError('Fatal parsing error');
    }
}
