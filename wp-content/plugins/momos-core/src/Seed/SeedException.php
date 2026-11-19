<?php
declare(strict_types=1);

namespace Momos\Seed;

/** A seed step was refused by a module rule or guard (message names the object and the reason). */
final class SeedException extends \RuntimeException {}
