<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

use Janalis\Viz\Source\Unsupported;

/**
 * The functions PlantUML's preprocessor has built in, as its `tim.builtin` package writes
 * them. Those that read the clock, chance, the environment, files or the standard library
 * are left to PlantUML.
 */
final class TimBuiltins
{
    public const string VERSION = '1.2026.8';
    /** The characters PlantUML's text keeps for what a preprocessor function writes. */
    public const string NEWLINE = "\u{E100}";
    public const string NEWLINE_LEFT = "\u{E101}";
    public const string NEWLINE_RIGHT = "\u{E102}";
    public const string BREAKLINE = "\u{E103}";
    public const string BACKSLASH = "\u{E110}";
    public const string TABULATION = "\u{E111}";

    /** @return list<TimBuiltin> */
    public static function all(): array
    {
        return [...self::constants(), ...self::texts(), ...self::numbers(), ...self::logic(), ...self::variables(), ...self::calls(), ...self::json(), ...self::colors(), ...self::leftToPlantUml()];
    }

    /**
     * @param array{int, ?int} $counts
     * @param \Closure(TimContext, TimMemory, list<TimValue>, array<string, TimValue>): TimValue $body
     */
    private static function of(string $name, int $arity, array $counts, \Closure $body): TimBuiltin
    {
        return new TimBuiltin($name, $arity, $counts, $body);
    }

    /** @return list<TimBuiltin> */
    private static function constants(): array
    {
        $text = static fn (string $name, string $value): TimBuiltin => self::of($name, 0, [0, 0], static fn (): TimValue => TimValue::string($value));

        return [
            self::of('%false', 0, [0, 0], static fn (): TimValue => TimValue::bool(false)),
            self::of('%true', 0, [0, 0], static fn (): TimValue => TimValue::bool(true)),
            $text('%backslash', value: self::BACKSLASH),
            $text('%breakline', value: self::BREAKLINE),
            $text('%newline', value: self::NEWLINE),
            $text('%n', value: self::NEWLINE),
            $text('%left_align', value: self::NEWLINE_LEFT),
            $text('%right_align', value: self::NEWLINE_RIGHT),
            $text('%tab', value: self::TABULATION),
            $text('%dollar', value: '$'),
            $text('%percent', value: '%'),
            $text('%version', value: self::VERSION),
            $text('%dirpath', value: ''),
            $text('%filedate', value: ''),
            $text('%filename', value: ''),
            $text('%filename_no_extension', value: ''),
            self::of('%get_current_theme', 0, [0, 0], static fn (): TimValue => TimValue::ofJson(JsonValue::object([]))),
            self::of('%feature', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::int(
                \in_array(\strtolower($v[0]->text()), ['style', 'theme'], strict: true) ? 1 : 0,
            )),
            self::of('%xargs', 0, [1, 1], static fn (TimContext $c): TimValue => TimValue::string($c->xargs())),
        ];
    }

    /** @return list<TimBuiltin> */
    private static function texts(): array
    {
        return [
            self::of('%lower', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::string(\mb_strtolower($v[0]->text()))),
            self::of('%upper', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::string(\mb_strtoupper($v[0]->text()))),
            self::of('%string', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::string($v[0]->text())),
            self::of('%strlen', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::int(\mb_strlen($v[0]->text()))),
            self::of('%strpos', 2, [2, 2], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::int(self::strpos($v[0]->text(), $v[1]->text()))),
            self::of('%substr', 3, [2, 3], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::string(self::substr(
                $v[0]->text(),
                $v[1]->toInt(),
                ($v[2] ?? null)?->toInt(),
            ))),
            self::of('%chr', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::string(self::chr($v[0]->toInt()))),
            self::of('%ord', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::int('' === $v[0]->text() ? 0 : (int) \mb_ord($v[0]->text()))),
            self::of('%splitstr', 3, [2, 2], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::ofJson(JsonValue::array(\array_map(
                JsonValue::string(...),
                self::tokenized($v[0]->text(), $v[1]->text()),
            )))),
            self::of('%splitstr_regex', 2, [2, 2], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::ofJson(JsonValue::array(\array_map(
                JsonValue::string(...),
                self::split($v[0]->text(), $v[1]->text()),
            )))),
        ];
    }

    /** As Java's `Character.toChars`: a NUL for what is no character. */
    private static function chr(int $code): string
    {
        $char = \mb_chr($code);

        return false === $char ? "\0" : $char;
    }

    /** As Java's `indexOf`: -1 for none. */
    private static function strpos(string $text, string $searched): int
    {
        $found = \mb_strpos($text, $searched);

        return false === $found ? -1 : $found;
    }

    /** As Java's `substring`: from a place, as long as asked when the text is as long. */
    private static function substr(string $text, int $start, ?int $length): string
    {
        if ($start >= \mb_strlen($text)) {
            return '';
        }
        if ($start < 0) {
            throw new TimError('Fatal parsing error');
        }
        $rest = \mb_substr($text, $start);

        return null !== $length && $length < \mb_strlen($rest) ? \mb_substr($rest, start: 0, length: \max(0, $length)) : $rest;
    }

    /**
     * As Java's `StringTokenizer`: the stretches between any of the separator's characters.
     *
     * @return list<string>
     */
    private static function tokenized(string $text, string $separators): array
    {
        if ('' === $separators) {
            return '' === $text ? [] : [$text];
        }
        $parts = [];
        foreach ((array) \preg_split('/['.\preg_quote($separators, delimiter: '/').']/u', $text) as $part) {
            if (!\is_string($part) || '' === $part) {
                continue;
            }
            $parts[] = $part;
        }

        return $parts;
    }

    /**
     * As Java's `String.split`: the text cut at each match, the empty pieces at the end dropped.
     *
     * @return list<string>
     */
    private static function split(string $text, string $pattern): array
    {
        \set_error_handler(static fn (): bool => true);
        try {
            $parts = \preg_split('/'.\str_replace('/', replace: '\/', subject: $pattern).'/u', $text);
        } finally {
            \restore_error_handler();
        }
        if (false === $parts) {
            throw new TimError('Fatal parsing error');
        }
        if ('' === $text) {
            return [''];
        }
        while ([] !== $parts && '' === $parts[\count($parts) - 1]) {
            \array_pop($parts);
        }

        return $parts;
    }

    /** @return list<TimBuiltin> */
    private static function numbers(): array
    {
        return [
            self::of('%intval', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => self::intval($v[0]->text())),
            self::of('%boolval', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => match (\strtolower($v[0]->text())) {
                'true', '1' => TimValue::bool(true),
                'false', '0' => TimValue::bool(false),
                default => throw new TimError('Cannot convert '.\strtolower($v[0]->text()).' to boolean.'),
            }),
            self::of('%dec2hex', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::string(\dechex($v[0]->toInt() & 0xFFFF_FFFF))),
            self::of('%hex2dec', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => self::hex2dec($v[0]->text())),
            self::of('%mod', 2, [2, 2], static fn (TimContext $c, TimMemory $m, array $v): TimValue => 0 === $v[1]->toInt()
                ? throw new TimError('Divide by zero')
                : TimValue::int($v[0]->toInt() % $v[1]->toInt())),
            self::of('%size', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::int(self::size($v[0]))),
            self::of('%eval', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::int($c->expression($m, new TimEater($v[0]->text()))->toInt())),
        ];
    }

    /** As Java's `Integer.parseInt`. */
    private static function intval(string $text): TimValue
    {
        $match = [];
        if (1 !== \preg_match('/^([+-]?)0*(\d{1,10})$/', $text, $match) && 1 !== \preg_match('/^([+-]?)(0+)$/', $text, $match)) {
            throw new TimError('Cannot convert '.$text.' to integer.');
        }
        $value = (int) $match[2] * ('-' === $match[1] ? -1 : 1);
        if ($value > 0x7FFF_FFFF || $value < -0x8000_0000) {
            throw new TimError('Cannot convert '.$text.' to integer.');
        }

        return TimValue::int($value);
    }

    /** As Java's `Integer.parseInt` in base 16, and 0 for what it does not read. */
    private static function hex2dec(string $text): TimValue
    {
        $match = [];
        if (1 !== \preg_match('/^([+-]?)0*([0-9a-fA-F]{1,8})$/', $text, $match) && 1 !== \preg_match('/^([+-]?)(0+)$/', $text, $match)) {
            return TimValue::int(0);
        }
        $value = (int) \hexdec($match[2]) * ('-' === $match[1] ? -1 : 1);

        return $value > 0x7FFF_FFFF || $value < -0x8000_0000 ? TimValue::int(0) : TimValue::int($value);
    }

    private static function size(TimValue $value): int
    {
        if ($value->isNumber()) {
            return 0;
        }
        if ($value->isString()) {
            return \mb_strlen($value->text());
        }
        $json = $value->json();

        return null !== $json && ($json->isArray() || $json->isObject()) ? $json->size() : 0;
    }

    /** @return list<TimBuiltin> */
    private static function logic(): array
    {
        $count = static fn (array $values): int => \count(\array_filter($values, static fn (TimValue $value): bool => $value->toBool()));
        $all = static fn (array $values): bool => $count($values) === \count($values);

        return [
            self::of('%not', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::bool(!$v[0]->toBool())),
            self::of('%and', 2, [2, null], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::bool($all($v))),
            self::of('%nand', 2, [2, null], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::bool(!$all($v))),
            self::of('%or', 2, [2, null], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::bool($count($v) > 0)),
            self::of('%nor', 2, [2, null], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::bool(0 === $count($v))),
            self::of('%xor', 2, [2, null], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::bool(1 === $count($v))),
            self::of('%nxor', 2, [2, null], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::bool(1 !== $count($v))),
        ];
    }

    /** @return list<TimBuiltin> */
    private static function variables(): array
    {
        return [
            self::of('%variable_exists', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::bool(null !== $m->get($v[0]->text()))),
            self::of('%get_variable_value', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => $m->get($v[0]->text()) ?? TimValue::string('')),
            self::of('%set_variable_value', 2, [2, 2], static function (TimContext $c, TimMemory $m, array $v): TimValue {
                $m->put($v[0]->text(), $v[1], global: true);

                return TimValue::string('');
            }),
            self::of('%function_exists', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::bool($c->functions->exists($v[0]->text()))),
        ];
    }

    /** @return list<TimBuiltin> */
    private static function calls(): array
    {
        $found = self::found(...);

        return [
            self::of('%call_user_func', 1, [1, null], static fn (TimContext $c, TimMemory $m, array $v, array $n): TimValue => $found($c, $v)->call($c, $m, \array_slice($v, offset: 1), $n)),
            new TimBuiltin(
                '%invoke_procedure',
                1,
                [1, null],
                static function (TimContext $c, TimMemory $m, array $v, array $n) use ($found): TimValue {
                    $found($c, $v)->run($c, $m, \array_slice($v, offset: 1), $n);

                    return TimValue::string('');
                },
                TimFunctionType::Procedure,
            ),
            self::of('%retrieve_procedure', 1, [1, null], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::string($c->retrieved(
                $found($c, $v),
                $m,
                \array_slice($v, offset: 1),
            ))),
        ];
    }

    /**
     * The function a call names in its first argument, taking the others.
     *
     * @param list<TimValue> $values
     */
    private static function found(TimContext $context, array $values): TimFunction
    {
        $name = $values[0]->text();

        return $context->functions->find($name, \count($values) - 1) ?? throw new TimError('Cannot find void function '.$name);
    }

    /** @return list<TimBuiltin> */
    private static function json(): array
    {
        return [
            self::of('%str2json', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => self::parsed($v[0]->text())),
            self::of('%get_json_keys', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::ofJson(JsonValue::array(\array_map(
                JsonValue::string(...),
                TimJsonFunctions::keys(self::data($v[0])),
            )))),
            self::of('%get_json_type', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::string(TimJsonFunctions::type($v[0]))),
            self::of('%json_key_exists', 1, [2, 2], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::bool(TimJsonFunctions::keyExists($v[0], $v[1]))),
            self::of('%json_add', 3, [2, 3], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimJsonFunctions::add(self::data($v[0]), $v[0], $v[1], $v[2] ?? null)),
            self::of('%json_merge', 2, [2, 2], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimJsonFunctions::merge($v[0], self::data($v[0]), self::data($v[1]))),
            self::of('%json_remove', 2, [2, 2], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimJsonFunctions::remove(self::data($v[0]), $v[0], $v[1])),
            self::of('%json_set', 3, [2, 3], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimJsonFunctions::set(self::data($v[0]), $v[0], $v[1], $v[2] ?? null)),
        ];
    }

    private static function parsed(string $text): TimValue
    {
        try {
            return TimValue::ofJson(JsonReader::parse($text));
        } catch (JsonError) {
            return TimValue::string('');
        }
    }

    private static function data(TimValue $value): JsonValue
    {
        return $value->json() ?? throw new TimError('Not JSON data');
    }

    /** @return list<TimBuiltin> */
    private static function colors(): array
    {
        return [
            self::of('%darken', 2, [2, 2], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::string(TimColors::darken($v[0]->text(), $v[1]->toInt()))),
            self::of('%lighten', 2, [2, 2], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::string(TimColors::lighten($v[0]->text(), $v[1]->toInt()))),
            self::of('%is_dark', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::bool(TimColors::isDark($v[0]->text()))),
            self::of('%is_light', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::bool(!TimColors::isDark($v[0]->text()))),
            self::of('%reverse_color', 1, [1, 1], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::string(TimColors::reverse($v[0]->text()))),
            self::of('%hsl_color', 3, [3, 4], static fn (TimContext $c, TimMemory $m, array $v): TimValue => TimValue::string(TimColors::hslColor(
                $v[0]->toInt(),
                $v[1]->toInt(),
                $v[2]->toInt(),
                ($v[3] ?? null)?->toInt(),
            ))),
        ];
    }

    /** @return list<TimBuiltin> */
    private static function leftToPlantUml(): array
    {
        $left = static fn (string $name, int $arity, int $least, int $most): TimBuiltin => self::of(
            $name,
            $arity,
            [$least, $most],
            static fn (): TimValue => throw new Unsupported('The native PlantUML renderer leaves '.$name.'() to PlantUML'),
        );

        return [
            $left('%date', arity: 3, least: 0, most: 3),
            $left('%now', arity: 0, least: 0, most: 0),
            $left('%random', arity: 2, least: 0, most: 2),
            $left('%getenv', arity: 1, least: 1, most: 1),
            $left('%file_exists', arity: 1, least: 1, most: 1),
            $left('%load_json', arity: 3, least: 1, most: 3),
            $left('%get_all_stdlib', arity: 1, least: 0, most: 1),
            $left('%get_stdlib', arity: 1, least: 0, most: 2),
            $left('%get_all_theme', arity: 0, least: 0, most: 0),
            $left('%reverse_hsluv_color', arity: 1, least: 1, most: 1),
        ];
    }
}
