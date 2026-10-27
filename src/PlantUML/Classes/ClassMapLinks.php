<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Classes;

use Janalis\Viz\PlantUML\Links\LinkDecor;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Source\Unsupported;

/** A map's rows as links' ends, as PlantUML's `BodierMap` and ports take them. */
final class ClassMapLinks
{
    /** A map's entry linking its key to an entity, as `BodierMap` finds one. */
    private const string LINK = '/\*-+_?>/';

    /**
     * A map's entries as the native renderer reads them: `key => value`, or a key linked to an
     * entity.
     *
     * @param list<string> $lines
     * @throws Unsupported for another
     */
    public static function check(array $lines): void
    {
        foreach ($lines as $line) {
            if (!\str_contains($line, '=>') && null === self::linked($line)) {
                throw new Unsupported('The native PlantUML renderer does not draw: '.\trim($line));
            }
        }
    }

    /**
     * The arrow of an entry linking its key to an entity, and where it starts; null for none.
     *
     * @return ?array{string, int}
     */
    public static function linked(string $line): ?array
    {
        $match = [];
        if (\str_contains($line, '=>') || 1 !== \preg_match(self::LINK, $line, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return [$match[0][0], (int) $match[0][1]];
    }

    /**
     * A map's `key *-> B` entries, as PlantUML's `CommandCreateMap` reads them: an arrow from the
     * key's row to an entity made before, a rank per dash past the first.
     *
     * @param list<string> $lines the map's
     * @param \Closure(string): ?ClassEntity $known the entity a name stands for, null for none yet
     * @return list<PlantUmlLink>
     * @throws PlantUmlRefusal where PlantUML refuses one: no such entity
     */
    public static function links(ClassEntity $map, array $lines, \Closure $known): array
    {
        $links = [];
        foreach ($lines as $line) {
            $linked = self::linked($line);
            if (null === $linked) {
                continue;
            }
            [$arrow, $at] = $linked;
            $dest = \trim(\substr($line, $at + \strlen($arrow)));
            $link = new PlantUmlLink($map->id, ($known($dest) ?? throw new PlantUmlRefusal('No such entity '.$dest))->id, LinkDecor::None, LinkDecor::Arrow, length: \strlen($arrow) - 2);
            $link->fromPort = \trim(\substr($line, offset: 0, length: $at));
            $links[] = $link;
        }

        return $links;
    }

    /**
     * A link from a map's row, as dot draws one from a port: from the row, at its map's end.
     *
     * @param ?array{string, string} $port
     */
    public static function ported(PlantUmlLink $link, ?array $port): PlantUmlLink
    {
        $link->fromPort = null !== $port && $link->from === $port[0] ? $port[1] : null;

        return $link;
    }

    /**
     * A map's row a link's end names, `M::key`, as PlantUML's ports name one, where no entity is
     * so named and `::` separates no namespaces: the map, and the key; null for a name that is
     * not one.
     *
     * @param \Closure(string): ?ClassEntity $entity the entity a name stands for, null for none
     * @return ?array{string, string}
     */
    public static function port(string $name, ?string $separator, \Closure $entity): ?array
    {
        $at = '::' === $separator || null !== $entity($name) ? false : \strrpos($name, needle: '::');
        $map = false === $at ? null : $entity(\substr($name, offset: 0, length: $at));

        return false !== $at && null !== $map && 'map' === $map->type ? [$map->id, \substr($name, $at + 2)] : null;
    }
}
