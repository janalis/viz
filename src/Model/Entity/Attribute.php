<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Entity;

/** One attribute: its type, name, keys and comment. */
final readonly class Attribute
{
    /** @param list<Key> $keys */
    public function __construct(
        public string $type,
        public string $name,
        public array $keys = [],
        public string $comment = '',
    ) {}

    /** The attribute as one line: `type name [PK, FK] — comment`. */
    public function text(): string
    {
        $keys = [] === $this->keys ? '' : ' ['.\implode(', ', \array_map(static fn (Key $key): string => $key->value, $this->keys)).']';

        return $this->type.' '.$this->name.$keys.('' === $this->comment ? '' : ' — '.$this->comment);
    }
}
