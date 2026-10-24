<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Packet;

use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\Source\Unsupported;

/**
 * A packet diagram's commands carried out as PlantUML's `PacketDiagram` carries them out:
 * `colwidth`, `node_height`, `scale_direction`, `scale_interval` and `same_height`; each field
 * by its bits — `0-15:`, `16:`, or `*` after the last — `len`, `height` and `rotate` among
 * the attributes in brackets after it.
 */
final class PacketReader
{
    private int $columns = 16;
    private float $bitHeight = 32.0;
    private ?int $interval = null;
    private bool $rightToLeft = false;
    private bool $sameHeight = false;
    /** @var list<array{int, int, int, string}> each field's first and last bit, height and text */
    private array $fields = [];

    /**
     * @param list<PlantUmlCommand> $commands
     * @throws Unsupported when a command is not drawn natively
     */
    public static function read(PlantUmlDocument $document, array $commands): PlantUmlPacket
    {
        $reader = new self();
        foreach ($commands as $command) {
            $reader->execute($command);
        }

        return new PlantUmlPacket($document, $reader->fields, $reader->columns, $reader->bitHeight, $reader->interval, $reader->rightToLeft, $reader->sameHeight);
    }

    private function execute(PlantUmlCommand $command): void
    {
        $value = static fn (string $slot): int => (int) $command->get($slot);
        match ($command->name) {
            // As PlantUML, which reads `hide` here and draws nothing different.
            'CommandNope', 'CommandPacketDiagStart', 'CommandPacketDiagEnd', 'CommandHideEmptyDescription', 'CommandHideShowByVisibility', 'CommandHideShowByGender' => null,
            'CommandColWidth' => $this->columns = $value('WIDTH') > 0 ? $value('WIDTH') : $this->columns,
            'CommandNodeHeight' => $this->bitHeight = (float) \max(0, $value('HEIGHT')),
            'CommandScaleDirection' => $this->rightToLeft = 'rtl' === \strtolower((string) $command->get('DIR')),
            // As PlantUML, no wider than the row as wide as it is when set.
            'CommandScaleInterval' => $this->interval = $value('INTERVAL') > 0 ? \min($value('INTERVAL'), $this->columns) : $this->interval,
            'CommandSameHeight' => $this->sameHeight = 'true' === \strtolower((string) $command->get('VALUE')),
            'CommandNumRange' => $this->field($command),
            default => throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line()),
        };
    }

    /** `0-15: text`, `16: text [len = 4]`, or `* text`, after the last field. */
    private function field(PlantUmlCommand $command): void
    {
        $attributes = self::attributes($command->get('ATTRIBUTE'));
        $length = self::number($attributes, 'len', 1);
        $unit = $command->get('RANGE_UNIT');
        $from = $command->get('RANGE');
        $to = $command->get('RANGE', 1);
        [$start, $end] = match (true) {
            null !== $unit => [(int) $unit, (int) $unit + $length - 1],
            null !== $from && null !== $to => [(int) $from, (int) $to],
            default => [$last = [] === $this->fields ? 0 : $this->fields[\count($this->fields) - 1][1] + 1, $last + $length - 1],
        };
        $this->fields[] = [$start, $end, self::number($attributes, 'height', 1), (string) $command->get('DESC')];
    }

    /** @return array<string, string> `name = value` pairs, a value quoted or not, as PlantUML's `NwDiagram.toSet` reads them */
    private static function attributes(?string $written): array
    {
        $matches = [];
        \preg_match_all('/\s*(\w+)\s*=\s*("([^"]*)"|[^\s,]+)/', (string) $written, $matches, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);
        $attributes = [];
        foreach ($matches as $match) {
            $attributes[$match[1]] = $match[3] ?? $match[2];
        }

        return $attributes;
    }

    /** @param array<string, string> $attributes */
    private static function number(array $attributes, string $name, int $default): int
    {
        $value = $attributes[$name] ?? null;

        return null !== $value && 1 === \preg_match('/^[+-]?\d+$/', $value) ? (int) $value : $default;
    }
}
