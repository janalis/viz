<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

use Janalis\Viz\PlantUML\Language\PlantUmlStdlib;
use Janalis\Viz\Source\Unsupported;

/**
 * PlantUML's preprocessor, ported from its `TContext`: each line of a body carried out —
 * directives done, and plain lines given out with the variables and calls in them replaced,
 * a procedure called in one giving out its own lines in its place.
 */
final class TimContext
{
    public readonly TimFunctions $functions;
    /** @var array<string, list<string>> the `!startsub` blocks, by name */
    public array $subs = [];
    /** The metadata of the theme run last, from its header. */
    public JsonValue $themeMetadata;
    /** @var list<string> the lines given out */
    private array $result = [];
    /** What came before a procedure called in a line, put before the first line it gives out. */
    private ?string $pendingAdd = null;

    private function __construct()
    {
        $this->functions = new TimFunctions();
        $this->themeMetadata = JsonValue::object([]);
        foreach (TimBuiltins::all() as $builtin) {
            $this->functions->add($builtin);
        }
    }

    /**
     * @param list<string> $lines
     * @return list<string> the lines the preprocessor gives out
     * @throws TimError where PlantUML's preprocessor stops; Unsupported for what is left to PlantUML
     */
    public static function process(array $lines): array
    {
        $context = new self();
        $context->execute(TimMemory::global(), $lines, null);

        return $context->result;
    }

    /**
     * Carries out a body; in a function, what its `!return` gives.
     *
     * @param list<string> $body
     */
    public function execute(TimMemory $memory, array $body, ?TimFunctionType $type): ?TimValue
    {
        $lines = new TimLines($body, $this, $memory);
        while (null !== ($line = $lines->peek())) {
            $value = $this->line($memory, $line, $type);
            if (null !== $value) {
                return $value;
            }
            $lines->next();
        }

        return null;
    }

    private function line(TimMemory $memory, string $line, ?TimFunctionType $type): ?TimValue
    {
        $kind = TimLineType::of($line);
        $inFunction = TimFunctionType::ReturnFunction === $type;
        match (true) {
            TimLineType::Return === $kind && $inFunction => null,
            TimLineType::Plain === $kind => $inFunction ? $this->substitutedLines($memory, $line) : $this->plain($memory, $line),
            TimLineType::Theme === $kind => $this->theme($memory, TimEater::trimmed($line)),
            TimLineType::Include === $kind => $this->include($memory, TimEater::trimmed($line)),
            \in_array($kind, [TimLineType::IncludeDef, TimLineType::Import], strict: true) => throw new Unsupported(
                'The native PlantUML renderer leaves '.\explode(' ', \trim($line), limit: 2)[0].' to PlantUML',
            ),
            default => $this->directive($memory, $line, $kind, $type),
        };
        if (TimLineType::Return !== $kind || !$inFunction) {
            return null;
        }
        $eater = new TimEater($line);
        $eater->skipSpaces();
        $eater->expect('!return');
        $eater->skipSpaces();

        return $this->expression($memory, $eater);
    }

    private function directive(TimMemory $memory, string $line, TimLineType $kind, ?TimFunctionType $type): void
    {
        $trimmed = TimEater::trimmed($line);
        match ($kind) {
            TimLineType::Includesub => $this->includeSub($memory, $trimmed),
            TimLineType::DumpMemory => null,
            TimLineType::Assert => $this->assert($memory, $trimmed),
            TimLineType::Option => $this->option($memory, $trimmed),
            TimLineType::Undef => $memory->remove(self::after($trimmed, '!undef')->name()),
            TimLineType::AffectationDefine => $this->define($memory, $trimmed),
            TimLineType::Log => $this->substituted($memory, self::after($trimmed, '!log')->rest()),
            default => $this->stray($line, $kind, $type),
        };
    }

    /** A line the preprocessor does nothing with: an `!end` outside a function, or blank — else an error. */
    private function stray(string $line, TimLineType $kind, ?TimFunctionType $type): void
    {
        if ((TimLineType::EndFunction === $kind && null === $type) || 1 === \preg_match('/^\s+$/', $line)) {
            return;
        }

        throw new TimError('Compile Error '.($type->name ?? 'null').' '.$kind->name, $line);
    }

    /** An eater past a directive's keyword and the spaces after it. */
    private static function after(string $line, string $keyword): TimEater
    {
        $eater = new TimEater($line);
        $eater->skipSpaces();
        $eater->expect($keyword);
        $eater->skipSpaces();

        return $eater;
    }

    /** `!$name = value`, `!$name ?= value`, `!local`, `!global`. */
    public function affect(TimMemory $memory, string $line): void
    {
        $eater = new TimEater(TimEater::trimmed($line));
        $eater->skipSpaces();
        $eater->expect('!');
        $eater->skipSpaces();
        $name = $eater->name();
        $scope = match (\strtolower($name)) {
            'local' => false,
            'global' => true,
            default => null,
        };
        if (null !== $scope) {
            $eater->skipSpaces();
            \in_array($eater->peek(), ['?', '='], strict: true) ? ($scope = null) : ($name = $eater->name());
        }
        $eater->skipSpaces();
        $conditional = $eater->accept('?');
        $eater->expect('=');
        if ($conditional && null !== $memory->get($name)) {
            return;
        }
        $eater->skipSpaces();
        $memory->put($name, $this->expression($memory, $eater), $scope);
    }

    /** `!define NAME text`: a global variable of the text, its variables and calls replaced. */
    private function define(TimMemory $memory, string $line): void
    {
        $eater = self::after($line, '!define');
        $name = $eater->name();
        $eater->skipSpaces();
        $text = $this->substituted($memory, $eater->rest()) ?? throw new TimError('Fatal parsing error', $line);
        $memory->put($name, TimValue::string($text), global: true);
    }

    /** `!define name(…) text` and `!definelong name(…)`. */
    public function legacyDefine(TimMemory $memory, string $line, TimLineType $type): void
    {
        $long = TimLineType::LegacyDefinelong === $type;
        $eater = self::after(TimEater::trimmed($line), $long ? '!definelong' : '!define');
        $function = $this->declaration($memory, $eater, $long ? TimFunctionType::LegacyDefinelong : TimFunctionType::LegacyDefine, unquoted: true);
        if ($long) {
            $this->functions->declare($function);

            return;
        }
        $function->definition = $eater->rest();
        $this->functions->define($function);
    }

    /** `!procedure` and `!function`, `unquoted` or `final`; a function's `return` on the same line its body. */
    public function declare(TimMemory $memory, string $line, TimLineType $type): void
    {
        $eater = new TimEater(TimEater::trimmed($line));
        $eater->skipSpaces();
        $eater->expect('!');
        $unquoted = false;
        $final = false;
        while ('u' === $eater->peek() || ('f' === $eater->peek() && 'i' === $eater->peekNext())) {
            $word = 'u' === $eater->peek() ? 'unquoted' : 'final';
            $unquoted = $unquoted || 'unquoted' === $word;
            $final = $final || 'final' === $word;
            $eater->expect($word);
            $eater->skipSpaces();
        }
        $procedure = TimLineType::DeclareProcedure === $type;
        $eater->expect($procedure ? 'procedure' : 'function');
        $eater->skipSpaces();
        $function = $this->declaration($memory, $eater, $procedure ? TimFunctionType::Procedure : TimFunctionType::ReturnFunction, $unquoted, $final);
        if (!$procedure && \in_array($eater->peek(), ['r', '!'], strict: true)) {
            $eater->expect('r' === $eater->peek() ? 'return' : '!return');
            $eater->skipSpaces();
            $function->add('!return '.$eater->rest());
        }
        $this->functions->declare($function);
    }

    /** A function's name and arguments, each with its default; a `!definelong` may have no parentheses. */
    private function declaration(TimMemory $memory, TimEater $eater, TimFunctionType $type, bool $unquoted, bool $final = false): TimUserFunction
    {
        $name = $eater->name();
        $eater->skipSpaces();
        $arguments = [];
        if (!$eater->accept('(')) {
            return TimFunctionType::LegacyDefinelong === $type ? new TimUserFunction($name, [], $unquoted, $type, $final) : throw new TimError('Missing opening parenthesis', $eater->line);
        }
        while (true) {
            $eater->skipSpaces();
            if (TimEater::startsName($eater->peek())) {
                $argument = $eater->name();
                $eater->skipSpaces();
                $arguments[] = [$argument, $eater->accept('=') ? TimExpression::evaluate($eater->argument(), $this, $memory) : null];
                continue;
            }
            if ($eater->accept(',')) {
                continue;
            }
            if ($eater->accept(')')) {
                break;
            }
            throw new TimError('Error in function definition', $eater->line);
        }
        $eater->skipSpaces();

        return new TimUserFunction($name, $arguments, $unquoted, $type, $final);
    }

    /** `!if`, `!ifdef`, `!ifndef`, `!elseif`, `!else`, `!endif`. */
    public function conditional(TimMemory $memory, string $line, TimLineType $type): void
    {
        $last = \array_key_last($memory->ifs);
        if (null === $last && \in_array($type, [TimLineType::Else, TimLineType::Elseif, TimLineType::Endif], strict: true)) {
            throw new TimError('No if related to this '.\strtolower($type->name), $line);
        }
        match ($type) {
            TimLineType::If => $memory->ifs[] = $this->opened($memory->allIfsHold() && $this->expression($memory, self::after($line, '!if'))->toBool()),
            TimLineType::Ifdef => $memory->ifs[] = $this->opened($this->defined($memory, self::after($line, '!ifdef')->rest())),
            TimLineType::Ifndef => $memory->ifs[] = $this->opened(!$this->exists($memory, self::after($line, '!ifndef')->name())),
            TimLineType::Elseif => $memory->ifs[(int) $last] = $this->elseIf($memory, $line, $memory->ifs[(int) $last]),
            TimLineType::Else => $memory->ifs[(int) $last] = [!$memory->ifs[(int) $last][1], $memory->ifs[(int) $last][1]],
            default => \array_pop($memory->ifs),
        };
    }

    /** @return array{bool, bool} a decision whose branch holds or not */
    private function opened(bool $holds): array
    {
        return [$holds, $holds];
    }

    /**
     * @param array{bool, bool} $decision
     * @return array{bool, bool}
     */
    private function elseIf(TimMemory $memory, string $line, array $decision): array
    {
        if ($decision[1]) {
            return [false, true];
        }
        $holds = $this->expression($memory, self::after($line, '!elseif'))->toBool();

        return [$holds, $holds];
    }

    private function exists(TimMemory $memory, string $name): bool
    {
        return null !== $memory->get($name) || $this->functions->exists($name);
    }

    /** `!ifdef`'s test: names joined by `!`, `&`, `|` and parentheses, each true when a variable or function of it exists. */
    private function defined(TimMemory $memory, string $expression): bool
    {
        try {
            return new TimDefined($expression, fn (string $name): bool => $this->exists($memory, $name))->evaluate();
        } catch (\InvalidArgumentException $error) {
            throw new TimError('Fatal parsing error', $expression, previous: $error);
        }
    }

    /**
     * `!theme name`: a theme PlantUML bundles under MIT, its header's metadata kept and its
     * lines carried out here; any other, and one `from` elsewhere, is left to PlantUML.
     */
    private function theme(TimMemory $memory, string $line): void
    {
        $name = self::after($line, '!theme')->rest();
        if (false !== \stripos($name, needle: ' from ')) {
            throw new Unsupported('The native PlantUML renderer leaves !theme … from to PlantUML: '.$line);
        }
        $theme = TimThemes::read($this->substituted($memory, $name) ?? '');
        $this->execute($memory, $theme->lines, null);
        $this->themeMetadata = $theme->metadata;
    }

    /**
     * `!include <library/file>`: a file of PlantUML's standard library, its YAML header left
     * out, carried out here; a file or a URL is left to PlantUML.
     */
    private function include(TimMemory $memory, string $line): void
    {
        $eater = new TimEater($line);
        $eater->skipSpaces();
        $eater->expect('!include');
        // As PlantUML: `url`, `_many` or `_once` right after, before any space.
        $suffix = match ($eater->peek()) {
            'u' => 'url',
            '_' => 'm' === $eater->peekNext() ? '_many' : '_once',
            default => '',
        };
        $eater->expect($suffix);
        $eater->skipSpaces();
        $what = $this->substituted($memory, $eater->rest()) ?? '';
        $bang = \strrpos($what, needle: '!');
        $what = false === $bang ? $what : \substr($what, offset: 0, length: $bang);
        if (!\str_starts_with($what, '<') || !\str_ends_with($what, '>')) {
            throw new Unsupported('The native PlantUML renderer leaves !include of a file or a URL to PlantUML: '.$line);
        }
        $lines = PlantUmlStdlib::lines(\substr($what, offset: 1, length: -1)) ?? throw new TimError('cannot include '.$what, $line);
        $this->execute($memory, TimThemes::withoutHeader($lines)[0], null);
    }

    /** `!assert test : message`. */
    private function assert(TimMemory $memory, string $line): void
    {
        $eater = self::after($line, '!assert');
        $holds = TimExpression::evaluate($eater->tokensBeforeColon(), $this, $memory)->toBool();
        $eater->skipSpaces();
        if ($holds) {
            return;
        }
        if (!$eater->accept(':')) {
            throw new TimError('Assertion error', $line);
        }

        throw new TimError('Assertion error : '.$this->expression($memory, $eater)->text(), $line);
    }

    /** `!option`: what it sets changes the drawing, and is left to PlantUML; an unknown one is only warned of. */
    private function option(TimMemory $memory, string $line): void
    {
        $eater = self::after($line, '!option');
        $key = \strtolower((string) \preg_replace('/[^a-zA-Z]/', replacement: '', subject: $eater->name()));
        if (\in_array($key, ['language', 'usedescriptivenames', 'handwritten', 'debug', 'svgdesc', 'svgtitle'], strict: true)) {
            throw new Unsupported('The native PlantUML renderer leaves !option to PlantUML: '.$line);
        }
        $eater->skipSpaces();
        try {
            $this->expression($memory, $eater);
        } catch (TimError) {
            return;
        }
    }

    /** `!startsub name`. */
    public function subName(string $line): string
    {
        $name = self::after(TimEater::trimmed($line), '!startsub')->rest();
        if (1 !== \preg_match('/^\w+$/', $name)) {
            throw new TimError('Bad sub name', $line);
        }

        return $name;
    }

    /** `!includesub name`: a block of this source, carried out here; one of another file is left to PlantUML. */
    private function includeSub(TimMemory $memory, string $line): void
    {
        $what = $this->substituted($memory, self::after($line, '!includesub')->rest()) ?? '';
        if (\str_contains($what, '!')) {
            throw new Unsupported('The native PlantUML renderer leaves !includesub of a file to PlantUML: '.$line);
        }
        $this->execute($memory, $this->subs[$what] ?? throw new TimError('cannot include '.$what, $line), null);
    }

    /** A value written after a directive: JSON when it starts as JSON, else an expression. */
    public function expression(TimMemory $memory, TimEater $eater): TimValue
    {
        if (\in_array($eater->peek(), ['{', '['], strict: true)) {
            return TimValue::ofJson(JsonReader::parse($eater->rest()));
        }
        $tokens = $eater->tokens();
        if ([] === $tokens) {
            throw new TimError('Missing expression', $eater->line);
        }

        return TimExpression::evaluate($tokens, $this, $memory);
    }

    /** A variable an expression names; one with `.` or `[` as its text replaced, read as JSON when it is. */
    public function variable(TimMemory $memory, string $name): ?TimValue
    {
        if (!\str_contains($name, '.') && !\str_contains($name, '[')) {
            return $memory->get($name);
        }
        $text = $this->substituted($memory, $name) ?? '';
        try {
            return TimValue::ofJson(JsonReader::parse($text));
        } catch (JsonError) {
            return TimValue::string($text);
        }
    }

    /** A plain line given out, its variables and calls replaced; a procedure called in it gives out its own lines. */
    private function plain(TimMemory $memory, string $line): void
    {
        $lines = $this->substitutedLines($memory, $line);
        if (null === $lines) {
            return;
        }
        if (null !== $this->pendingAdd) {
            $lines[0] = $this->pendingAdd.$lines[0];
            $this->pendingAdd = null;
        }
        \array_push($this->result, ...$lines);
    }

    /** @return ?non-empty-list<string> a line replaced, split at each new line in it; null where a procedure gave out its own */
    private function substitutedLines(TimMemory $memory, string $line): ?array
    {
        $text = $this->substituted($memory, $line);

        return null === $text ? null : \explode("\n", $text);
    }

    /**
     * A text with its variables and calls replaced, as PlantUML's `applyFunctionsAndVariables`;
     * null when a procedure called in it gave out lines of its own.
     */
    public function substituted(TimMemory $memory, string $text): ?string
    {
        $chars = \mb_str_split($text);
        $result = '';
        for ($at = 0, $count = \count($chars); $at < $count; ++$at) {
            $name = $this->functions->calledAt($chars, $at);
            if (null !== $name) {
                $call = $this->call($memory, $name, \implode('', \array_slice($chars, $at)), $result, \implode('', \array_slice($chars, offset: 0, length: $at)));
                if (null === $call) {
                    return null;
                }
                [$value, $length] = $call;
                $result .= $value;
                $at += $length - 1;
                continue;
            }
            if (null !== $this->variableAt($memory, $chars, $at)) {
                $at = $this->replaceVariable($memory, $chars, $at, $result);
                continue;
            }
            $result .= $chars[$at];
        }

        return $result;
    }

    /**
     * A call in a line: what a function gives, and how much of the line the call took; null
     * when it is a procedure, whose lines are given out, with what came before the call put
     * before the first, and what comes after it after the last.
     *
     * @return ?array{string, int}
     */
    private function call(TimMemory $memory, string $name, string $rest, string $before, string $original): ?array
    {
        $call = new TimCall($rest, $this->functions->is($name, 'legacy'), $this->functions->is($name, 'unquoted'));
        $call->read($this, $memory);
        $function = $this->functions->find($name, \count($call->arguments), \array_keys($call->named)) ?? throw new TimError('Function not found '.$name, $rest);
        if (TimFunctionType::Procedure === $function->type()) {
            $this->pendingAdd = $before;
            $function->run($this, $memory, $call->arguments, $call->named);
            $remaining = \mb_substr($rest, $call->length);
            if ('' !== $remaining) {
                $this->appendToLast($remaining);
            }

            return null;
        }
        if (TimFunctionType::LegacyDefinelong === $function->type()) {
            $this->pendingAdd = $original;
            $function->run($this, $memory, $call->arguments, $call->named);

            return null;
        }

        return [$function->call($this, $memory, $call->arguments, $call->named)->text(), $call->length];
    }

    /** `%xargs()`: as PlantUML gives it, the first line given out, past its debugging prefix. */
    public function xargs(): string
    {
        $first = $this->result[0] ?? null;

        return null === $first ? '' : TimEater::trimmed($first);
    }

    /**
     * `%retrieve_procedure`: the lines a procedure gives out, taken back joined by new lines.
     *
     * @param list<TimValue> $arguments
     */
    public function retrieved(TimFunction $procedure, TimMemory $memory, array $arguments): string
    {
        $count = \count($this->result);
        $procedure->run($this, $memory, $arguments, []);
        $lines = \array_splice($this->result, $count);

        return \implode(TimBuiltins::NEWLINE, $lines);
    }

    private function appendToLast(string $text): void
    {
        $last = \array_key_last($this->result) ?? throw new TimError('Fatal parsing error');
        $this->result[$last] .= $text;
    }

    /**
     * The name of a variable here, as PlantUML finds it: not just after a letter or a digit
     * unless it starts with `$`, and not going on into one.
     *
     * @param list<string> $chars
     */
    private function variableAt(TimMemory $memory, array $chars, int $at): ?string
    {
        if (self::afterName($chars, $at) && '$' !== $chars[$at]) {
            return null;
        }
        $name = $memory->nameAt($chars, $at);
        if ('' === $name) {
            return null;
        }
        $next = $chars[$at + \mb_strlen($name)] ?? null;

        return null === $next || !TimEater::isNamePart($next) ? $name : null;
    }

    /**
     * A variable replaced by its value, as PlantUML's `VariableManager`: `##` around it joins it
     * to what is next to it, and `.field` and `[index]` after JSON read into it.
     *
     * @param list<string> $chars
     * @return int where the variable ends
     */
    private function replaceVariable(TimMemory $memory, array $chars, int $at, string &$result): int
    {
        $name = (string) $this->variableAt($memory, $chars, $at);
        if (\str_ends_with($result, '##')) {
            $result = \substr($result, offset: 0, length: -2);
        }
        $value = $memory->get($name) ?? throw new TimError('Fatal parsing error');
        $at += \mb_strlen($name) - 1;
        $json = $value->json();
        if (null !== $json && !$json->isString() && !$json->isNumber()) {
            $at = new TimJsonPath($this, $memory)->read($json, $chars, $at + 1, $result) - 1;
        }
        if (null === $json || $json->isString() || $json->isNumber()) {
            $result .= $value->text();
        }
        if ('#' === ($chars[$at + 1] ?? null) && '#' === ($chars[$at + 2] ?? null) && ($at + 2) < \count($chars)) {
            $at += 2;
        }

        return $at;
    }

    /**
     * Whether a name here would go on from the letter or digit before it — not after a `\n`.
     *
     * @param list<string> $chars
     */
    public static function afterName(array $chars, int $at): bool
    {
        return $at > 0 && TimEater::isNamePart($chars[$at - 1]) && !($at > 1 && '\\' === $chars[$at - 2] && 'n' === $chars[$at - 1]);
    }
}
