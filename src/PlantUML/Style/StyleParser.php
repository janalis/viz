<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Style;

use Janalis\Viz\Source\Unsupported;

/**
 * PlantUML's style sheet syntax: selectors of style names, `.stereotype`s, `depth(n)` and a
 * trailing `*`, comma-separated and nested in braces; `Property value` or `Property: value`
 * declarations ended by a newline or `;`; `--variables` and `var(--name)`; `//`, `/' '/` and
 * `/* *\/` comments; `<style>` tags; and `@media` blocks, whose dark-scheme values are read and
 * left out.
 */
final class StyleParser
{
    /** @var list<array{string, string}> kind and text */
    private array $tokens = [];
    private int $at = 0;
    /** @var array<string, string> */
    private array $variables = [];

    public function __construct(string $source)
    {
        $this->tokens = self::tokens($source);
    }

    /**
     * Each declaration in order: where it applies, the property, its value, and whether it is
     * for the dark scheme.
     *
     * @return list<array{StyleSignature, string, string, bool}>
     * @throws Unsupported on a declaration or bracket out of place
     */
    public function declarations(): array
    {
        $declarations = [];
        $stack = [[StyleSignature::of([])]];
        $dark = false;
        while (null !== ($token = $this->tokens[$this->at] ?? null)) {
            ++$this->at;
            [$kind, $text] = $token;
            if (\in_array($kind, ['newline', ';'], strict: true) || ('string' === $kind && \in_array(\strtolower($text), ['<style>', '</style>'], strict: true))) {
                continue;
            }
            if ('}' === $kind) {
                if (\count($stack) > 1) {
                    \array_pop($stack);
                }
                continue;
            }
            if ('media' === $kind) {
                $dark = true;
                continue;
            }
            if ('string' !== $kind && ':' !== $kind) {
                throw new Unsupported('The PlantUML style cannot be read: unexpected '.$text);
            }
            $selector = $this->selector($kind, $text);
            if (null !== $selector) {
                $stack[] = self::push($stack[\count($stack) - 1], $selector);
                continue;
            }
            if (':' === $kind) {
                throw new Unsupported('The PlantUML style cannot be read: unexpected :');
            }
            $declaration = $this->declaration($text);
            if (null === $declaration) {
                continue;
            }
            foreach ($stack[\count($stack) - 1] as $signature) {
                $declarations[] = [$signature, $declaration[0], $declaration[1], $dark];
            }
        }

        return $declarations;
    }

    /**
     * A selector opening a block here, its brace read; null when this is a declaration, the
     * newlines after a name read as PlantUML reads them.
     */
    private function selector(string $kind, string $text): ?string
    {
        if (':' === $kind) {
            $star = '*' === $this->kind(1) ? 1 : 0;
            if ('string' !== $this->kind() || '{' !== $this->kind(1 + $star)) {
                return null;
            }
            $selector = $this->tokens[$this->at][1].($star > 0 ? '*' : '');
            $this->at += 2 + $star;

            return $selector;
        }
        $selector = $text;
        $listed = ',' === $this->kind();
        while ($listed && \in_array($this->kind(), ['string', ','], strict: true)) {
            $selector .= $this->tokens[$this->at++][1];
        }
        if (!$listed && '*' === $this->kind()) {
            $selector .= '*';
            ++$this->at;
        }
        while ('newline' === $this->kind()) {
            ++$this->at;
        }
        if ('{' === $this->kind()) {
            ++$this->at;

            return $selector;
        }
        if ($listed) {
            throw new Unsupported('The PlantUML style cannot be read: '.$selector.' opens no block');
        }

        return null;
    }

    /** @return ?array{string, string} a declaration's property and value, or null for a variable or an unknown property */
    private function declaration(string $name): ?array
    {
        while (':' === $this->kind()) {
            ++$this->at;
        }
        if (\str_starts_with($name, '--')) {
            $this->variables[\substr($name, offset: 2)] = $this->value();

            return null;
        }
        if ('string' !== $this->kind()) {
            throw new Unsupported('The PlantUML style cannot be read: '.$name.' has no value');
        }
        $value = $this->value();
        $match = [];
        if (1 === \preg_match('/^var\(-*([_\w][-_\w]+)\)$/', $value, $match) && \array_key_exists($match[1], $this->variables)) {
            $value = $this->variables[$match[1]];
        }
        $property = StyleSheet::PROPERTIES[\strtolower($name)] ?? null;

        return null === $property ? null : [$property, $value];
    }

    private function value(): string
    {
        $value = '';
        while (null !== ($token = $this->tokens[$this->at] ?? null) && !\in_array($token[0], ['newline', ';', '}'], strict: true)) {
            ++$this->at;
            $value .= match ($token[0]) {
                'string' => ('' === $value ? '' : ' ').$token[1],
                ',' => ',',
                ':' => ':'.$this->colonValue(),
                default => throw new Unsupported('The PlantUML style cannot be read: unexpected '.$token[1].' in a value'),
            };
        }

        return $value;
    }

    private function colonValue(): string
    {
        if ('string' !== $this->kind()) {
            throw new Unsupported('The PlantUML style cannot be read: a value ends in :');
        }

        return $this->tokens[$this->at++][1];
    }

    private function kind(int $offset = 0): ?string
    {
        return $this->tokens[$this->at + $offset][0] ?? null;
    }

    /**
     * @param list<StyleSignature> $signatures
     * @return list<StyleSignature>
     */
    private static function push(array $signatures, string $selector): array
    {
        $selector = \ltrim($selector, characters: ':');
        $star = \str_ends_with($selector, '*');
        $selector = $star ? \trim(\substr($selector, offset: 0, length: -1)) : $selector;
        $pushed = [];
        foreach (\explode(',', $selector) as $part) {
            foreach ($signatures as $signature) {
                $pushed[] = $signature->with($part, $star);
            }
        }

        return $pushed;
    }

    /** @return list<array{string, string}> */
    private static function tokens(string $source): array
    {
        $tokens = [];
        $length = \strlen($source);
        $single = [',' => ',', ';' => ';', '*' => '*', ':' => ':', '{' => '{', '}' => '}', "\n" => 'newline', "\r" => 'newline'];
        for ($at = 0; $at < $length;) {
            $char = $source[$at];
            $pair = \substr($source, $at, length: 2);
            if (' ' === $char || "\t" === $char) {
                ++$at;
                continue;
            }
            $end = match ($pair) {
                '//' => \strpos($source, needle: "\n", offset: $at),
                "/'" => self::after($source, "'/", $at),
                '/*' => self::after($source, '*/', $at),
                default => null,
            };
            if (null !== $end) {
                $at = false === $end ? $length : $end;
                continue;
            }
            if (\array_key_exists($char, $single)) {
                $tokens[] = [$single[$char], $char];
                ++$at;
                continue;
            }
            [$token, $at] = match ($char) {
                '@' => self::media($source, $at),
                '"' => self::quoted($source, $at),
                default => self::word($source, $at),
            };
            $tokens[] = $token;
        }

        return $tokens;
    }

    private static function after(string $source, string $end, int $at): int|false
    {
        $found = \strpos($source, $end, $at + 2);

        return false === $found ? false : $found + 2;
    }

    /** @return array{array{string, string}, int} an `@media` token, up to and with its brace */
    private static function media(string $source, int $at): array
    {
        $length = \strcspn($source, characters: '{};', offset: $at + 1);

        return [['media', \substr($source, $at + 1, $length)], \min(\strlen($source), $at + 2 + $length)];
    }

    /** @return array{array{string, string}, int} */
    private static function quoted(string $source, int $at): array
    {
        $end = \strpos($source, needle: '"', offset: $at + 1);
        $end = false === $end ? \strlen($source) : $end;

        return [['string', \substr($source, $at + 1, $end - $at - 1)], $end + 1];
    }

    /** @return array{array{string, string}, int} a word, which runs through spaces when it starts with a dot */
    private static function word(string $source, int $at): array
    {
        $stops = '.' === $source[$at] ? "\n\r{};,:\t" : "\n\r{};,:\t ";
        $length = \strcspn($source, $stops, $at);
        $word = \substr($source, $at, $length);

        return [['string', '.' === $source[$at] ? \trim($word) : $word], $at + $length];
    }
}
