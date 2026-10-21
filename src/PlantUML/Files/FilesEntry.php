<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Files;

/** PlantUML's `FEntry`: a folder, a file or a note, and the entries a folder holds. */
final class FilesEntry
{
    /** @var list<self> */
    public array $children = [];

    /** @param list<string> $note a note's lines */
    public function __construct(
        public readonly FilesType $type,
        public readonly string $name,
        public readonly ?self $parent = null,
        public readonly array $note = [],
    ) {}

    /** As `addRawEntry`: a path's folders found or made, its last part a file; none for a path ending in `/`. */
    public function add(string $path): ?self
    {
        $slash = \strpos($path, needle: '/');
        if (false === $slash) {
            $file = new self(FilesType::File, $path, $this);
            $this->children[] = $file;

            return $file;
        }
        $folder = $this->folder(\substr($path, offset: 0, length: $slash));
        $rest = \substr($path, $slash + 1);

        return '' === $rest ? null : $folder->add($rest);
    }

    /** @param list<string> $lines */
    public function addNote(array $lines): void
    {
        $this->children[] = new self(FilesType::Note, '', $this, $lines);
    }

    private function folder(string $name): self
    {
        foreach ($this->children as $child) {
            if (FilesType::Folder === $child->type && $child->name === $name) {
                return $child;
            }
        }
        $folder = new self(FilesType::Folder, $name, $this);
        $this->children[] = $folder;

        return $folder;
    }
}
