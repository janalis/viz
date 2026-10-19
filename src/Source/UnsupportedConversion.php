<?php

declare(strict_types=1);

namespace Janalis\Viz\Source;

/** A source that cannot be read into the shared model: a language or a diagram type with no reader. */
final class UnsupportedConversion extends \RuntimeException {}
