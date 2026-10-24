<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Timing;

use Janalis\Viz\PlantUML\Classes\ClassColors;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Source\Unsupported;

/**
 * A timing diagram's commands carried out as PlantUML's `TimingDiagram` carries them out:
 * players declared; `@` a moment, named or not, or a player; states set at the moment, or
 * before any; messages, constraints, notes, highlights; the time axis scaled, hidden or
 * labelled by hand; every player compact.
 */
final class TimingReader
{
    /** @var array<string, TimingPlayer> by code */
    private array $players = [];
    /** @var array<string, TimingPlayer> */
    private array $clocks = [];
    /** @var array<string, TimingTick> named moments, by name */
    private array $codes = [];
    /** @var list<TimingMessage> */
    private array $messages = [];
    /** @var list<TimingHighlight> */
    private array $highlights = [];
    private TimingRuler $ruler;
    private ?TimingTick $now = null;
    private ?TimingPlayer $last = null;
    private string $axis = 'automatic';
    private bool $compact = false;

    private function __construct()
    {
        $this->ruler = new TimingRuler();
    }

    /**
     * @param list<PlantUmlCommand> $commands
     * @throws Unsupported when a command is not drawn natively
     * @throws PlantUmlRefusal when PlantUML fails to carry out a command
     */
    public static function read(PlantUmlDocument $document, array $commands): PlantUmlTiming
    {
        $reader = new self();
        foreach ($commands as $command) {
            $reader->execute($command);
        }

        return new PlantUmlTiming($document, $reader->players, $reader->messages, $reader->highlights, $reader->ruler, $reader->codes, $reader->axis, $reader->compact);
    }

    private function execute(PlantUmlCommand $command): void
    {
        match ($command->name) {
            'CommandNope', 'CommandComment', 'CommandFootboxIgnored' => null,
            'CommandRobustConcise' => $this->state($command),
            'CommandClock' => $this->clock($command),
            'CommandBinary' => $this->binary($command),
            'CommandDefineStateShort' => $this->define((string) $command->get('PLAYER'), \explode(',', (string) $command->get('STATE').(string) $command->get('STATES'))),
            'CommandDefineStateLong' => $this->labelled((string) $command->get('PLAYER'), (string) $command->get('STATE'), (string) $command->get('LABEL')),
            'CommandChangeStateByPlayerCode' => $this->set($this->player((string) $command->get('CODE'), 'Unknown "%s"'), $this->now, $command),
            'CommandChangeStateByTime' => $this->setAt($command),
            'CommandAtTime' => $this->at($this->tick('TIME', $command) ?? throw new PlantUmlRefusal('What time?'), $command->get('CODE')),
            'CommandAtPlayer' => $this->last = $this->player((string) $command->get('PLAYER'), 'No such participant %s'),
            'CommandTimeMessage' => $this->message($command),
            'CommandNote' => $this->note($command, ClassReader::split((string) $command->get('NOTE'))),
            'CommandNoteLong' => $this->note($command, \array_map(\trim(...), $command->body())),
            'CommandConstraint' => $this->constraint($command),
            'CommandScalePixel' => $this->scale($command),
            'CommandHideTimeAxis' => $this->axis = 'manual' === \strtolower((string) $command->get('COMMAND')) ? 'manual' : 'hidden',
            'CommandHighlight' => $this->highlight($command),
            'CommandModeCompact' => $this->compact = true,
            'CommandTicks' => $this->player((string) $command->get('PLAYER'), 'No such participant %s'),
            'CommandPixelHeight' => $this->player((string) $command->get('PLAYER'), 'No such participant %s')->height = (int) $command->get('NUM'),
            default => throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line()),
        };
    }

    private function state(PlantUmlCommand $command): void
    {
        $player = new TimingPlayer(
            \strtolower((string) $command->get('TYPE')),
            (string) $command->get('FULL'),
            $this->compact || null !== $command->get('COMPACT'),
            0,
            ClassColors::back($command->get('COLOR')),
            self::stereotypes($command->get('STEREOTYPE') ?? $command->get('STEREOTYPE2')),
        );
        $this->players[(string) $command->get('CODE')] = $player;
        $this->last = $player;
    }

    private function clock(PlantUmlCommand $command): void
    {
        $period = self::number($command->get('PERIOD'));
        $player = new TimingPlayer(
            'clock',
            (string) $command->get('FULL'),
            $this->compact,
            30,
            null,
            self::stereotypes($command->get('STEREO')),
            $period,
            self::number($command->get('PULSE')),
            self::number($command->get('OFFSET')),
        );
        $code = (string) $command->get('CODE');
        $this->players[$code] = $player;
        $this->clocks[$code] = $player;
        $this->ruler->add(new TimingTick($period));
    }

    private function binary(PlantUmlCommand $command): void
    {
        $this->players[(string) $command->get('CODE')] = new TimingPlayer(
            'binary',
            (string) $command->get('FULL'),
            $this->compact,
            30,
            null,
            self::stereotypes($command->get('STEREOTYPE') ?? $command->get('STEREOTYPE2')),
        );
    }

    /** @param list<string> $states */
    private function define(string $code, array $states): void
    {
        foreach ($states as $state) {
            $this->labelled($code, $state, $state);
        }
    }

    private function labelled(string $code, string $state, string $label): void
    {
        $player = $this->player($code, 'Unknown %s');
        if (\in_array($player->kind, ['binary', 'clock'], strict: true)) {
            throw new PlantUmlRefusal('A '.$player->kind.' player has no states to name');
        }
        $player->labels[$state] = $label;
    }

    private function setAt(PlantUmlCommand $command): void
    {
        $player = $this->last ?? throw new PlantUmlRefusal('Missing @ line before this');
        $tick = $this->tick('TIME', $command) ?? throw new PlantUmlRefusal('Unknown time label');
        $this->at($tick, null);
        $this->set($player, $tick, $command);
    }

    private function set(TimingPlayer $player, ?TimingTick $now, PlantUmlCommand $command): void
    {
        $first = $command->get('STATE7');
        $states = null === $first ? [$command->lazy('STATE') ?? ''] : [$first, (string) $command->get('STATE7', 1)];
        $states = match ($player->kind) {
            'clock' => throw new PlantUmlRefusal('A clock has no states'),
            'binary' => \array_map(static fn (string $state): string => '1' === $state || 'high' === \strtolower($state) ? '1' : '0', $states),
            default => \array_map($player->label(...), $states),
        };
        $player->change(new TimingChange($now, $states, $command->get('COMMENT'), ClassColors::back($command->get('COLOR'))));
    }

    private function at(TimingTick $tick, ?string $code): void
    {
        $this->now = $tick;
        $this->ruler->add($tick);
        if (null !== $code) {
            $this->codes[$code] = $tick;
        }
    }

    private function message(PlantUmlCommand $command): void
    {
        $from = $this->player((string) $command->get('PART1'), 'No such element: %s');
        $to = $this->player((string) $command->get('PART2'), 'No such element: %s');
        $this->messages[] = new TimingMessage(
            $from,
            $this->tick('TIME1', $command),
            $to,
            $this->tick('TIME2', $command),
            self::lines($command->get('MESSAGE')),
            self::arrowColor($command->get('ARROW_STYLE')),
        );
    }

    /** @param list<string> $lines */
    private function note(PlantUmlCommand $command, array $lines): void
    {
        $player = $this->player((string) $command->get('CODE'), 'Unknown "%s"');
        $player->notes[] = new TimingNote($this->now, $lines, \strtolower((string) $command->get('POSITION')));
    }

    private function constraint(PlantUmlCommand $command): void
    {
        $code = $command->get('PART1');
        $player = null === $code ? $this->last ?? throw new PlantUmlRefusal('You have to provide a participant') : $this->player($code, 'No such participant %s');
        $from = $this->tick('TIME1', $command) ?? throw new PlantUmlRefusal('Unknown time label');
        $restore = $this->now;
        $this->now = $from;
        $to = $this->tick('TIME2', $command);
        $this->now = $restore;
        $margin = match ($player->kind) {
            'clock' => throw new PlantUmlRefusal('A clock takes no constraint'),
            'robust', 'binary' => 2.5,
            default => 1.0,
        };
        $player->constraints[] = new TimingConstraint(
            $from,
            $to ?? throw new PlantUmlRefusal('Unknown time label'),
            self::lines($command->get('MESSAGE')),
            $margin,
            self::arrowColor($command->get('ARROW_STYLE1')),
        );
    }

    private function scale(PlantUmlCommand $command): void
    {
        $factor = match ((string) $command->get('UNIT')) {
            'm' => 60,
            'h' => 3600,
            'd', 'D' => 86_400,
            'y', 'Y' => 3600 * 8766,
            default => 1,
        };
        $tick = (int) $command->get('TICK') * $factor;
        $pixels = (int) $command->get('PIXEL');
        if ($tick <= 0 || $pixels <= 0) {
            throw new PlantUmlRefusal('Bad value');
        }
        $this->ruler->scale($tick, $pixels);
    }

    private function highlight(PlantUmlCommand $command): void
    {
        $color = $command->get('COLOR');
        $this->highlights[] = new TimingHighlight(
            $this->tick('FROM', $command) ?? throw new PlantUmlRefusal('Unknown time label'),
            $this->tick('TO', $command) ?? throw new PlantUmlRefusal('Unknown time label'),
            self::lines($command->get('CAPTION')),
            ClassColors::back($color),
            ClassColors::line($color),
        );
    }

    /** As PlantUML's `TimeTickBuilder.parseTimeTick`: a named moment and a shift, a clock's tick, an hour, a date, a number — after now with `+` — or now. */
    private function tick(string $name, PlantUmlCommand $command): ?TimingTick
    {
        $code = $command->get($name.'CODE');
        if (null !== $code) {
            $named = $this->codes[$code] ?? null;
            $shift = $command->get($name.'CODE', 1);

            return null === $shift || null === $named ? $named : new TimingTick($named->time + self::number($shift));
        }
        $clock = $command->get($name.'CLOCK');
        if (null !== $clock) {
            $player = $this->clocks[$clock] ?? null;

            return null === $player ? null : new TimingTick($player->period * (int) $command->get($name.'CLOCK', 1));
        }
        if (null !== $command->get($name.'HOUR')) {
            return new TimingTick((float) ((3600 * (int) $command->get($name.'HOUR')) + (60 * (int) $command->get($name.'HOUR', 1)) + (int) $command->get($name.'HOUR', 2)), 'hour');
        }
        if (null !== $command->get($name.'DATE')) {
            return new TimingTick(
                (float) \gmmktime(hour: 0, minute: 0, second: 0, month: (int) $command->get($name.'DATE', 1), day: (int) $command->get($name.'DATE', 2), year: (int) $command->get($name.'DATE')),
                'date',
            );
        }
        $number = $command->get($name.'DIGIT', 1);
        if (null === $number) {
            return $this->now;
        }
        $now = '+' === $command->get($name.'DIGIT') && null !== $this->now ? $this->now->time : 0.0;

        return new TimingTick(self::number($number) + $now);
    }

    private static function number(?string $value): float
    {
        return \is_numeric($value) ? (float) $value : 0.0;
    }

    private function player(string $code, string $error): TimingPlayer
    {
        return $this->players[$code] ?? throw new PlantUmlRefusal(\sprintf($error, $code));
    }

    /** @return list<string> */
    private static function lines(?string $text): array
    {
        return null === $text ? [] : ClassReader::split($text);
    }

    /** @return list<string> */
    private static function stereotypes(?string $text): array
    {
        return null === $text ? [] : ClassReader::stereotypes($text);
    }

    /** An arrow's colour, as `[#red]` gives it; any other style is left to PlantUML. */
    private static function arrowColor(?string $style): ?string
    {
        if (null === $style) {
            return null;
        }
        $color = null;
        foreach (\explode(',', $style) as $part) {
            if (!\str_starts_with($part, '#')) {
                throw new Unsupported('The native PlantUML renderer leaves timing arrows styled '.$part.' to PlantUML');
            }
            $color = ClassColors::back($part);
        }

        return $color;
    }
}
