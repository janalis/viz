<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Activities\Stereogroup;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Style\StyleSheet;

/** The styles of an activity diagram's pieces, under `activityDiagram`, from the document's style sheet. */
final readonly class FtileStyles
{
    public function __construct(
        private StyleSheet $sheet,
    ) {}

    /** @param list<string> $stereotypes */
    public function style(array $stereotypes, string ...$names): Style
    {
        return $this->sheet->style(['root', 'element', 'activityDiagram', ...$names], $stereotypes);
    }

    /** An action's style, its `<<…>>` colours over it. */
    public function activity(?string $stereogroup): Style
    {
        return self::colored($this->style(Stereogroup::stereotypes($stereogroup), 'activity'), $stereogroup);
    }

    public function diamond(?string $stereogroup = null): Style
    {
        return $this->style(Stereogroup::stereotypes($stereogroup), 'activity', 'diamond');
    }

    public function arrow(): Style
    {
        return $this->style([], 'arrow');
    }

    public function note(): Style
    {
        return $this->style([], 'note');
    }

    /** A start, stop, end or spot's style, its `<<…>>` colours over it. */
    public function circle(string $kind, ?string $stereogroup = null): Style
    {
        return self::colored($this->style([], 'circle', $kind), $stereogroup);
    }

    /** A style with the background, line and text colours a `<<…>>` gives. */
    public static function colored(Style $style, ?string $stereogroup): Style
    {
        $values = $style->values;
        foreach (['BackGroundColor' => Stereogroup::back($stereogroup), 'LineColor' => Stereogroup::line($stereogroup), 'FontColor' => Stereogroup::text($stereogroup)] as $property => $color) {
            if (null === PlantUmlColor::parse((string) $color)) {
                continue;
            }

            $values[$property] = (string) $color;
        }

        return new Style($values);
    }
}
