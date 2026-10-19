<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML;

/**
 * A family's lines that PlantUML's factory for it matches but fails to carry out — a class
 * diagram declaring a state without `allowmixing`, say — so PlantUML tries its next factory.
 */
final class PlantUmlRefusal extends \RuntimeException {}
