<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Files;

/** What an entry of a files diagram is, as PlantUML's `FilesType`. */
enum FilesType
{
    case Folder;
    case File;
    case Note;

    /** The emoji PlantUML draws beside it: an open folder, or a page. */
    public function emoji(): string
    {
        return self::Folder === $this ? '1f4c2' : '1f4c4';
    }
}
