<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\NwDiag;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Descriptions\DescriptionBox;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\StyleSheet;

/**
 * A server in its cell of the grid, as PlantUML's `NServerDraw` draws it: its symbol in the
 * middle of the cell; a line up to its first network, its address there half way; a line
 * down to each other network it is on, 9 apart, its address there; each line hopping over
 * the networks it crosses.
 */
final class NwCell
{
    /** As PlantUML's `NServerDraw.MAGIC`. */
    public const float MAGIC = 15.0;
    private const float LINK_MARGIN = 10.0;
    private const float BOX_MARGIN = 15.0;
    private const float APART = 9.0;

    /**
     * @param list<array{NwNetwork, string}> $links each network it is on and its address there, in the networks' order
     * @param list<NwNetwork> $networks
     */
    public function __construct(
        public readonly NwServer $server,
        private readonly DescriptionBox $box,
        private readonly array $links,
        private readonly array $networks,
        private readonly float $topMargin,
        private readonly StyleSheet $sheet,
    ) {}

    public function linkedTo(NwNetwork $network): bool
    {
        foreach ($this->links as [$linked]) {
            if ($linked === $network) {
                return true;
            }
        }

        return false;
    }

    /** @return array{float, float} as PlantUML's `naturalDimension` */
    public function size(): array
    {
        $network = $this->server->main();
        [$firstWidth, $firstHeight] = $this->text($this->address($network) ?? '')->size();
        $index = \array_search($network, $this->networks, strict: true);
        $next = false === $index ? null : $this->networks[$index + 1] ?? null;
        [$secondWidth, $secondHeight] = null === $next ? [0.0, 0.0] : $this->text($this->address($next) ?? '')->size();

        return [
            \max($firstWidth + (2 * self::LINK_MARGIN), $this->box->width + (2 * self::BOX_MARGIN), $secondWidth + (2 * self::LINK_MARGIN)),
            $firstHeight + (2 * self::LINK_MARGIN) + (2 * $this->topMargin) + $this->box->height + $secondHeight + (2 * self::LINK_MARGIN),
        ];
    }

    /** @return array{float, float, float, float} the box's bounds in a cell, 5 more all round, as PlantUML's group takes them */
    public function bounds(float $x, float $y, float $width, float $height): array
    {
        [$left, $top] = [$x + (($width - $this->box->width) / 2), $y + (($height - $this->box->height) / 2)];

        return [$left - 5, $top - 5, $left + $this->box->width + 5, $top + $this->box->height + 5];
    }

    /** @return list<Mark> its symbol in the middle of a cell */
    public function marks(float $x, float $y, float $width, float $height): array
    {
        return $this->box->marks($x + (($width - $this->box->width) / 2), $y + (($height - $this->box->height) / 2));
    }

    /** @return list<Mark> its links, in a cell starting at a column's left */
    public function links(float $left, float $width, float $height, string $color): array
    {
        $network = $this->server->main() ?? throw new \LogicException('A server on no network');
        $top = $network->y;
        $middle = $height / 2;
        $alpha = $middle - ($this->box->height / 2);
        $centre = $width / 2;
        $skip = [];
        foreach ($this->networks as $other) {
            if (($left + $centre) <= $other->xmin || ($left + $centre) >= $other->xmax) {
                continue;
            }
            $skip[] = $other->y;
        }
        \sort($skip);
        $marks = [];
        if ($this->server->firstLinkShown) {
            \array_push(
                $marks,
                ...$network->visible
                    ? self::line($left + $centre + $network->shift(), $top + NwDrawing::THIN, $top + $alpha, $skip, $color)
                    : self::line($left + $centre + $network->shift(), $top, $top + $alpha, [], $color),
            );
        }
        \array_push($marks, ...$this->centred($this->text($this->address($network) ?? ''), $left + $centre + $network->shift(), $top + (($alpha - $this->topMargin + self::MAGIC) / 2)));
        $x = $centre - (((\count($this->links) - 2) * self::APART) / 2);
        $first = true;
        foreach ($this->links as [$other, $address]) {
            if ($other === $network) {
                continue;
            }
            \array_push($marks, ...self::line($left + $x - $other->shift(), $top + $middle + ($this->box->height / 2), $other->y, $skip, $color));
            $text = $this->text($address);
            $textX = $first && \count($this->links) > 2 ? $x - ($text->size()[0] / 2) : $x;
            \array_push($marks, ...$this->centred($text, $left + $textX - $other->shift(), $other->y - ($alpha / 2)));
            $x += self::APART;
            $first = false;
        }

        return $marks;
    }

    private function address(?NwNetwork $network): ?string
    {
        foreach ($this->links as [$linked, $address]) {
            if ($linked === $network) {
                return $address;
            }
        }

        return null;
    }

    /** As PlantUML's `toTextBlock`: an empty one for an empty address, each `, ` a new line. */
    private function text(string $address): PlantUmlText
    {
        if ('' === $address) {
            return new PlantUmlText([]);
        }

        return PlantUmlText::styled(ClassReader::split(\str_replace(', ', replace: "\n", subject: $address)), $this->sheet->style(['root', 'element', 'nwdiagDiagram', 'arrow']));
    }

    /** @return list<Mark> */
    private function centred(PlantUmlText $text, float $x, float $y): array
    {
        [$width, $height] = $text->size();

        return $text->marks($x - ($width / 2), $y - ($height / 2), $width, 'left');
    }

    /**
     * PlantUML's `VerticalLine`: a line down from one height to another, hopping over each
     * network it crosses in an arc 3 above and 9 below it.
     *
     * @param list<float> $skip the networks' heights, in order
     * @return list<Mark>
     */
    private static function line(float $x, float $y1, float $y2, array $skip, string $color): array
    {
        [$y1, $y2] = [\min($y1, $y2), \max($y1, $y2)];
        $marks = [];
        $path = [[$x, $y1]];
        $drawn = false;
        foreach ($skip as $step) {
            if ($step < $y1) {
                continue;
            }
            $drawn = true;
            $path[] = [$x, $step === $y2 ? $y2 : \min($y2, $step - 3)];
            if ($step !== $y2 && $y2 > $step) {
                // An arc of radius 6 out to the right, from 3 above the network to 9 below.
                for ($degrees = -75; $degrees <= 90; $degrees += 15) {
                    $path[] = [$x + (6 * \cos(\deg2rad($degrees))), $step + 3 + (6 * \sin(\deg2rad($degrees)))];
                }
                continue;
            }
            $marks[] = new Mark(MarkKind::Polyline, $path, stroke: $color, strokeWidth: 1.0);
            $path = [[$x, $step + 9]];
            if (($step + 9) >= $y2) {
                break;
            }
        }
        if (!$drawn) {
            $path[] = [$x, $y2];
            $marks[] = new Mark(MarkKind::Polyline, $path, stroke: $color, strokeWidth: 1.0);
        }

        return $marks;
    }
}
