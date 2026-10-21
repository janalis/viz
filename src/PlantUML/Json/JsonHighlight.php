<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Json;

use Janalis\Viz\PlantUML\Classes\ClassReader;

/**
 * A `#highlight "a" / "b" <<stereotype>>` line, as PlantUML's `Highlighted` keeps one: the
 * path to the key it highlights, `*` standing for any key and `**` for any depth.
 */
final readonly class JsonHighlight
{
    public const string PREFIX = '#highlight ';

    /** @param list<string> $path */
    private function __construct(
        public array $path,
        public ?string $stereotype,
    ) {}

    /** @return ?self null for a line that is no highlight, or none PlantUML reads */
    public static function of(string $line): ?self
    {
        $match = [];
        if (!\str_starts_with($line, self::PREFIX) || 1 !== \preg_match('/^([^<>]+)(<<.*>>)?$/', \trim(\substr($line, \strlen(self::PREFIX))), $match)) {
            return null;
        }
        $path = \array_map(
            static function (string $key): string {
                $key = \trim($key);

                return \strlen($key) >= 2 && \str_starts_with($key, '"') && \str_ends_with($key, '"') ? \substr($key, offset: 1, length: -1) : $key;
            },
            \explode('/', \trim($match[1])),
        );

        return new self($path, '' === ($match[2] ?? '') ? null : $match[2]);
    }

    /** The highlight as it applies under a key, or null when it reaches nothing there. */
    public function under(string $key): ?self
    {
        $first = $this->path[0];

        return match (true) {
            \count($this->path) <= 1 => null,
            '**' === $first => $this,
            '*' === $first || $first === $key => new self(\array_slice($this->path, offset: 1), $this->stereotype),
            default => null,
        };
    }

    public function highlights(string $key): bool
    {
        return [$key] === $this->path || ['**', $key] === $this->path;
    }

    /** @return list<string> */
    public function stereotypes(): array
    {
        return null === $this->stereotype ? [] : ClassReader::stereotypes($this->stereotype);
    }
}
