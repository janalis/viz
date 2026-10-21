<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Files;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlSource;
use Janalis\Viz\PlantUML\Style\StyleSheet;

/**
 * A `@startfiles` block read as PlantUML's `FilesDiagram` reads one: `<style>` sheets taken
 * out; each line starting `/` a path, its folders made as needed; `<note>` … `</note>` a note
 * in the folder of the last file, or at the top before any; every other line left out.
 */
final class FilesReader
{
    public static function read(PlantUmlSource $source): PlantUmlFiles
    {
        $styles = StyleSheet::skin();
        $root = new FilesEntry(FilesType::Folder, '');
        $last = null;
        $lines = $source->lines;
        for ($at = 0, $count = \count($lines); $at < $count; ++$at) {
            $line = $lines[$at];
            if ('<style>' === \trim($line)) {
                $sheet = [];
                while (++$at < $count && '</style>' !== \trim($lines[$at])) {
                    $sheet[] = $lines[$at];
                }
                $styles = $styles->with(\implode("\n", $sheet));
                continue;
            }
            if (\str_starts_with($line, '/')) {
                $last = $root->add(\substr($line, offset: 1));
                continue;
            }
            if (!\str_starts_with($line, '<note>')) {
                continue;
            }
            $note = [];
            while (++$at < $count && !\str_starts_with($lines[$at], '</note>')) {
                $note[] = $lines[$at];
            }
            ($last->parent ?? $root)->addNote($note);
        }

        return new PlantUmlFiles(new PlantUmlDocument([], $styles, pragmas: $source->pragmas, kind: 'files'), $root);
    }
}
